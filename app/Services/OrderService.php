<?php

namespace App\Services;

use App\Enums\Course;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ServiceCall;
use App\Models\Shift;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * หัวใจของระบบ POS — เปิดบิล / เพิ่มรายการ / คำนวณยอด / ยกเลิก
 *
 * ทุกเมธอดที่แตะหลายตารางถูกห่อด้วย transaction
 * และการคำนวณยอดรวมอยู่ที่ recalculate() ที่เดียว เพื่อไม่ให้ตัวเลขแตกกันระหว่างหน้า
 */
class OrderService
{
    public function __construct(
        protected ActivityLogger $logger,
        protected StockService $stock,
        protected KitchenService $kitchen,
        protected QueueService $queue,
        protected PromotionService $promotions,
    ) {}

    /** เปิดบิลใหม่ */
    public function open(
        Branch $branch,
        ?DiningTable $table = null,
        OrderType $type = OrderType::DineIn,
        int $guestCount = 1,
        ?Shift $shift = null,
    ): Order {
        return DB::transaction(function () use ($branch, $table, $type, $guestCount, $shift) {
            $businessDate = $branch->businessDateFor();

            $order = Order::create([
                'branch_id' => $branch->id,
                'shift_id' => $shift?->id,
                'dining_table_id' => $table?->id,
                'order_no' => $this->nextOrderNo($branch, $businessDate->format('ymd')),
                'business_date' => $businessDate->toDateString(),
                'type' => $type,
                'status' => OrderStatus::Open,
                'guest_count' => $guestCount,
                'opened_by' => auth()->id(),
                'opened_at' => now(),
            ]);

            $table?->update(['status' => TableStatus::Occupied]);

            /*
            | ลูกค้าเคยกด "เรียกพนักงานมาเปิดโต๊ะ" ไว้ — เปิดบิลแล้วถือว่าจบเรื่อง
            |
            | ปิดให้ตรงนี้ไม่ใช่ที่หน้าจอ เพราะพนักงานอาจเปิดโต๊ะจากทางอื่น
            | หรือเปิดโดยไม่ทันเห็นคำขอ ถ้ารอให้กดปิดเอง คำขอจะค้างบนแถบแจ้งเตือน
            | แล้วพนักงานจะเดินไปหาโต๊ะที่เปิดไปแล้ว
            */
            if ($table) {
                ServiceCall::query()
                    ->openRequestFor($table->id)
                    ->update([
                        'status' => 'done',
                        'handled_by' => auth()->id(),
                        'done_at' => now(),
                    ]);
            }

            $this->logger->log('order.open', $order, ['table' => $table?->name], $branch);

            return $order;
        });
    }

    /** เพิ่มรายการอาหารลงบิล */
    public function addItem(Order $order, Product $product, float $qty = 1, array $modifierIds = [], ?string $note = null, ?float $openPrice = null): OrderItem
    {
        $this->assertEditable($order);

        // ตรวจกฎของกลุ่มตัวเลือกที่ฝั่งเซิร์ฟเวอร์ด้วย
        // หน้าเว็บกันได้แค่คนกดปกติ แต่ยิง request ตรงข้าม UI ได้
        $modifiers = $this->resolveModifiers($product, $modifierIds);

        return DB::transaction(function () use ($order, $product, $qty, $modifiers, $note, $openPrice) {
            /*
            | ราคาต้องเป็นราคาของสาขาที่เปิดบิลนี้ ไม่ใช่ราคากลาง
            |
            | สาขาที่ตั้งราคาเองไว้ใน branch_product จะคิดเงินผิดทันทีถ้าอ่าน
            | $product->price ตรง ๆ — และผิดแบบเงียบ ๆ เพราะบิลออกได้ปกติ
            | ยอดขายเพิ่งมาไม่ตรงตอนกระทบยอดปลายวัน
            */
            $unitPrice = $product->is_open_price
                ? Money::round($openPrice ?? 0)
                : $product->priceAt((int) $order->branch_id);

            $item = $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'category_name' => $product->category?->name,
                'unit_price' => $unitPrice,
                'unit_cost' => $this->unitCostFor($product, $modifiers, (int) $order->branch_id),
                'qty' => $qty,
                'note' => $note,
                'status' => 'pending',
                'source' => OrderSource::Pos,
                'created_by' => auth()->id(),
            ]);

            $modifierTotal = 0.0;

            foreach ($modifiers as $modifier) {
                $price = (float) $modifier->price_delta * $qty;
                $modifierTotal += $price;

                $item->modifiers()->create([
                    'modifier_id' => $modifier->id,
                    // เก็บชื่อหน้าบ้านลงบิล ลูกค้าอ่านใบเสร็จแล้วตรงกับตอนสั่ง
                    'group_name' => $modifier->group?->displayName(),
                    'name' => $modifier->name,
                    'price' => $price,
                ]);
            }

            $item->modifier_total = Money::round($modifierTotal);
            $item->recalculate()->save();

            $this->recalculate($order);

            return $item->fresh('modifiers');
        });
    }

    /**
     * ตรวจว่าตัวเลือกที่ส่งมาถูกกฎของเมนูนี้จริง แล้วคืน Modifier ที่โหลดสูตรมาแล้ว
     *
     * กันไว้ 3 อย่าง
     *   1. ตัวเลือกต้องอยู่ในกลุ่มที่แปะกับเมนูนี้ — กันยัดตัวเลือกของเมนูอื่นเข้ามา
     *   2. กลุ่มที่บังคับเลือก ต้องเลือกครบขั้นต่ำ — กันบิลที่ครัวทำไม่ได้
     *   3. เลือกได้ไม่เกินโควตาของกลุ่ม
     *
     * @return EloquentCollection<int, Modifier>
     */
    protected function resolveModifiers(Product $product, array $modifierIds): EloquentCollection
    {
        // activeModifierGroups = เซ็ตที่เปิดทั้งสองระดับ
        // เซ็ตที่เพิ่งถูกปิดไปต้องสั่งไม่ได้ทันที แม้หน้าจอที่เปิดค้างไว้จะยังเห็นปุ่มอยู่
        $groups = $product->activeModifierGroups()
            ->with(['modifiers' => fn ($q) => $q->where('is_active', true)])
            ->get();

        $ids = array_values(array_unique(array_map('intval', $modifierIds)));

        $modifiers = $ids
            ? Modifier::with(['group', 'recipeItems'])
                ->whereIn('id', $ids)
                ->where('is_active', true)
                ->get()
            : new EloquentCollection;

        $allowed = $groups->flatMap(fn ($g) => $g->modifiers->pluck('id'))->all();

        foreach ($modifiers as $modifier) {
            if (! in_array($modifier->id, $allowed, true)) {
                throw ValidationException::withMessages([
                    'modifier_ids' => "ตัวเลือก \"{$modifier->name}\" ไม่ได้อยู่ในเมนู {$product->name}",
                ]);
            }
        }

        foreach ($groups as $group) {
            $chosen = $modifiers->where('modifier_group_id', $group->id)->count();

            // กลุ่มที่ตั้งว่าบังคับ ต้องเลือกอย่างน้อย 1 แม้ min_select จะเป็น 0
            $min = $group->is_required ? max(1, (int) $group->min_select) : (int) $group->min_select;

            if ($chosen < $min) {
                throw ValidationException::withMessages([
                    'modifier_ids' => "ต้องเลือก \"{$group->displayName()}\" อย่างน้อย {$min} อย่าง",
                ]);
            }

            if ($chosen > (int) $group->max_select) {
                throw ValidationException::withMessages([
                    'modifier_ids' => "\"{$group->displayName()}\" เลือกได้มากสุด {$group->max_select} อย่าง",
                ]);
            }
        }

        return $modifiers;
    }

    /**
     * ต้นทุนต่อหน่วยที่จะ snapshot ลงบิล
     *
     * เมนูที่ผูกสูตรไว้ ใช้ต้นทุนจริงจากสูตร + ตัวเลือกที่เลือก
     * ทำให้จัมโบ้เนื้อวัวมีต้นทุนสูงกว่าธรรมดาหมูจริง ไม่ใช่เท่ากันหมด
     * เมนูที่ไม่ได้ผูกสูตร ถอยไปใช้ products.cost ที่กรอกไว้เหมือนเดิม
     */
    /** ต้นทุนต่อหน่วยตามสูตรของสาขานี้ — สูตรแยกรายสาขา ต้นทุนจึงต่างกันได้ */
    protected function unitCostFor(Product $product, EloquentCollection $modifiers, ?int $branchId = null): float
    {
        if ($product->track_stock) {
            $cost = $this->stock->unitCostForSelection($product, $modifiers, $branchId);

            if ($cost > 0) {
                return $cost;
            }
        }

        return (float) $product->cost;
    }

    public function updateItemQty(OrderItem $item, float $qty): OrderItem
    {
        $this->assertEditable($item->order);

        return DB::transaction(function () use ($item, $qty) {
            if ($qty <= 0) {
                return $this->voidItem($item, 'ลดจำนวนเป็น 0');
            }

            $oldQty = (float) $item->qty ?: 1;
            // ตัวเลือกคิดต่อชิ้น จึงต้องสเกลตามจำนวนใหม่
            $item->modifier_total = Money::round((float) $item->modifier_total / $oldQty * $qty);
            $item->qty = $qty;
            $item->recalculate()->save();

            $this->recalculate($item->order);

            return $item;
        });
    }

    /**
     * ตั้งคอร์สให้รายการ — null = เอาออกจากคอร์ส
     *
     * ตั้งได้เฉพาะรายการที่ยังไม่ส่งครัว เพราะพอใบสั่งออกไปแล้ว
     * ครัวถืออยู่ในมือ การมาเปลี่ยนป้ายในระบบไม่ได้เปลี่ยนอะไรบนกระดาษ
     */
    public function setItemCourse(OrderItem $item, ?Course $course): OrderItem
    {
        $this->assertEditable($item->order);

        if ($item->status !== 'pending') {
            throw ValidationException::withMessages([
                'course' => 'รายการนี้ส่งครัวไปแล้ว เปลี่ยนคอร์สไม่ได้',
            ]);
        }

        $item->forceFill(['course' => $course])->save();

        return $item;
    }

    /** ยกเลิกรายการ (ไม่ลบจริง เพื่อให้ตรวจสอบย้อนหลังได้) */
    public function voidItem(OrderItem $item, ?string $reason = null): OrderItem
    {
        $this->assertEditable($item->order);

        return DB::transaction(function () use ($item, $reason) {
            $item->update([
                'status' => 'void',
                'voided_by' => auth()->id(),
                'void_reason' => $reason,
                'line_total' => 0,
            ]);

            $this->recalculate($item->order);
            $this->logger->log('order.item_void', $item->order, [
                'item' => $item->product_name,
                'reason' => $reason,
            ]);

            return $item;
        });
    }

    /**
     * ส่งรายการเข้าครัว แล้วออกใบสั่งครัวให้อัตโนมัติ
     *
     * รายการที่ลูกค้าสั่งเองและยังไม่ถูกยืนยัน จะไม่ถูกส่ง —
     * ต้องผ่าน SelfOrderService::approve() ก่อน (ตั้งใจให้พนักงานเป็นคนกรอง)
     */
    /**
     * ส่งรายการที่พักไว้เข้าครัว
     *
     * @param  array<int>|null  $itemIds  ส่งเฉพาะรายการที่ระบุ (ใช้ตอนส่งทีละคอร์ส)
     *                                    null = ส่งทุกอย่างที่ยังพักอยู่ ซึ่งเป็นพฤติกรรมเดิม
     *
     * กรอง id ที่ส่งมาด้วย whereIn ทับเงื่อนไขเดิม ไม่ได้เชื่อ id ดิบ —
     * ยิง id ของบิลอื่นเข้ามาก็ไม่โดน เพราะ query เริ่มจาก $order->items() อยู่แล้ว
     */
    public function sendToKitchen(Order $order, ?array $itemIds = null): int
    {
        $this->assertEditable($order);

        return DB::transaction(function () use ($order, $itemIds) {
            $items = $order->items()
                ->with('product', 'modifiers')
                ->where('status', 'pending')
                ->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', 'approved'))
                ->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds))
                ->get();

            if ($items->isEmpty()) {
                return 0;
            }

            $order->items()
                ->whereIn('id', $items->pluck('id'))
                ->update(['status' => 'sent', 'sent_at' => now()]);

            /*
            | คอร์สของใบสั่งครัว
            |
            | ส่งทีละคอร์สก็ได้คอร์สนั้น ส่งคละกันก็ไม่ใส่ — ใบเดียวมีสองคอร์ส
            | แล้วพิมพ์ว่า "จานหลัก" บนหัวใบจะหลอกครัวมากกว่าไม่บอกอะไรเลย
            */
            $courses = $items->pluck('course')->filter()->unique();
            $course = $courses->count() === 1 ? $courses->first() : null;

            $this->kitchen->createTickets($order, $items, OrderSource::Pos, $course);

            $this->enterQueue($order);

            $this->logger->log('order.item_sent', $order, ['count' => $items->count()]);

            return $items->count();
        });
    }

    /**
     * บิลหน้าเคาน์เตอร์ที่ไม่ได้นั่งโต๊ะ = ลูกค้ายืนรอรับ ต้องมีเลขคิวไว้เรียก
     *
     * บิลที่นั่งโต๊ะไม่ต้องมี เพราะพนักงานยกไปเสิร์ฟถึงโต๊ะอยู่แล้ว
     * ออเดอร์ออนไลน์มีเลขคิวจาก OnlineOrderService ไปก่อนแล้ว จึงข้ามตรงนี้ไปเอง
     */
    protected function enterQueue(Order $order): void
    {
        if ($order->dining_table_id || $order->queue_number) {
            return;
        }

        $this->queue->assign($order);

        // ยังไม่เคยมีสถานะส่งมอบ = บิลหน้าร้านแท้ ๆ เริ่มนับที่ "กำลังทำ" ได้เลย
        if ($order->fulfilment_status === null) {
            $order->forceFill([
                'fulfilment_status' => FulfilmentStatus::Preparing,
                'accepted_at' => $order->accepted_at ?? now(),
            ])->save();
        }
    }

    /**
     * อนุมัติรายการที่รอการยืนยัน แล้วส่งเข้าครัวทันที
     *
     * ใช้ร่วมกันระหว่างออเดอร์ที่ลูกค้าสั่งจากโต๊ะ (QR) และที่สั่งล่วงหน้าออนไลน์
     * ต่างกันแค่ว่าใบสั่งครัวจะถูกติดป้ายว่ามาจากช่องทางไหน
     *
     * @param  array<int>  $itemIds
     */
    public function approvePendingItems(
        Order $order,
        array $itemIds,
        OrderSource $ticketSource,
        ?int $userId = null,
    ): EloquentCollection {
        return DB::transaction(function () use ($order, $itemIds, $ticketSource, $userId) {
            $items = $order->items()
                ->with('product', 'modifiers')
                ->whereIn('id', $itemIds)
                ->where('approval_status', 'pending')
                ->where('status', '!=', 'void')
                ->get();

            if ($items->isEmpty()) {
                return $items;
            }

            $now = now();

            $order->items()->whereIn('id', $items->pluck('id'))->update([
                'approval_status' => 'approved',
                'approved_by' => $userId ?? auth()->id(),
                'approved_at' => $now,
                'status' => 'sent',
                'sent_at' => $now,
            ]);

            $this->kitchen->createTickets($order, $items, $ticketSource);

            return $items;
        });
    }

    /** ใส่ส่วนลดท้ายบิล */
    public function applyBillDiscount(Order $order, float $amount = 0, ?float $percent = null): Order
    {
        $this->assertEditable($order);

        $base = (float) $order->subtotal - (float) $order->item_discount;
        $order->bill_discount = $percent !== null
            ? Money::percent($base, $percent)
            : Money::round($amount);

        $order->save();

        return $this->recalculate($order);
    }

    /**
     * คำนวณยอดรวมทั้งบิลใหม่ — เรียกทุกครั้งที่รายการหรือส่วนลดเปลี่ยน
     *
     * ลำดับการคิด: ยอดขาย -> ลดราคารายสินค้า -> ลดท้ายบิล/โปรฯ/Voucher/สวัสดิการพนักงาน
     *              -> ค่าบริการ -> ค่าจัดส่ง -> ภาษี -> ปัดเศษ
     *
     * โปรโมชั่นคิดตรงนี้ที่เดียว ไม่ใช่ตอนกดเพิ่มรายการ เพราะเงื่อนไขโปรดูทั้งบิล
     * ลบรายการออกจนหลุดเงื่อนไข ส่วนลดต้องหายตามทันทีโดยไม่ต้องมีใครสั่ง
     */
    public function recalculate(Order $order): Order
    {
        // โหลดรายการใหม่เสมอ กันค่าค้างจาก relation ที่โหลดไว้ก่อนเพิ่ม/ลบรายการ
        // ลากหมวดของแต่ละเมนูมาด้วย เพราะเอนจินโปรต้องรู้ว่าจานนี้อยู่หมวดไหน
        // ถ้าไม่ลากมาตรงนี้จะกลายเป็น query รายจานตอนเทียบเงื่อนไขโปร
        $order->loadMissing('branch');
        $order->load('activeItems.product:id,category_id');
        $branch = $order->branch;

        // รายการที่ลูกค้าสั่งและยังรอยืนยัน ถูกนับในยอดด้วย
        // เพราะลูกค้าเห็นยอดนี้บนมือถือ ถ้าพนักงานปฏิเสธจะถูก void แล้วยอดหายไปเอง
        $subtotal = 0.0;
        $itemDiscount = 0.0;
        $costTotal = 0.0;

        foreach ($order->activeItems as $item) {
            $subtotal += ((float) $item->unit_price * (float) $item->qty) + (float) $item->modifier_total;
            $itemDiscount += (float) $item->discount;
            $costTotal += (float) $item->unit_cost * (float) $item->qty;
        }

        $subtotal = Money::round($subtotal);
        $itemDiscount = Money::round($itemDiscount);

        /*
        | โปรโมชั่นต้องคิดหลังได้ subtotal แล้ว และก่อนหักยอด
        |
        | เขียนสองค่านี้ลง $order ก่อน (ยังไม่ save) เพราะเอนจินอ่าน subtotal
        | กับ item_discount จาก $order ตรง ๆ เพื่อใช้เป็นฐานของส่วนลดท้ายบิล
        | ถ้าไม่เขียนก่อน มันจะอ่านค่าของรอบที่แล้วแล้วคิดผิดไปทั้งบิล
        */
        $order->forceFill([
            'subtotal' => $subtotal,
            'item_discount' => $itemDiscount,
        ]);

        $promotionDiscount = $this->syncPromotion($order);

        $netAfterDiscount = max(0, $subtotal - $itemDiscount
            - (float) $order->bill_discount
            - $promotionDiscount
            - (float) $order->voucher_discount
            - (float) $order->staff_discount);

        $serviceCharge = Money::percent($netAfterDiscount, (float) $branch->service_charge_rate);
        $beforeTax = $netAfterDiscount + $serviceCharge + (float) $order->delivery_fee;

        $vatRate = (float) $branch->vat_rate;

        if ($branch->vat_included) {
            // ราคารวม VAT แล้ว — แยกภาษีออกมาโชว์ ไม่บวกเพิ่ม
            $tax = Money::extractVat($beforeTax, $vatRate);
            $total = $beforeTax;
        } else {
            $tax = Money::addVat($beforeTax, $vatRate);
            $total = $beforeTax + $tax;
        }

        $rounded = Money::applyRounding($total, (int) $branch->rounding_mode);

        $order->forceFill([
            'subtotal' => $subtotal,
            'item_discount' => $itemDiscount,
            'promotion_discount' => $promotionDiscount,
            'service_charge' => $serviceCharge,
            'tax_amount' => $tax,
            'rounding' => Money::round($rounded - $total),
            'grand_total' => Money::round($rounded),
            'cost_total' => Money::round($costTotal),
        ])->save();

        return $order;
    }

    /** ย้ายโต๊ะ */
    public function moveTable(Order $order, DiningTable $target): Order
    {
        $this->assertEditable($order);

        return DB::transaction(function () use ($order, $target) {
            $order->diningTable?->update(['status' => TableStatus::Available]);
            $order->update(['dining_table_id' => $target->id]);
            $target->update(['status' => TableStatus::Occupied]);

            $this->logger->log('table.update', $order, ['to' => $target->name]);

            return $order->fresh('diningTable');
        });
    }

    /** ทำลายบิล */
    public function void(Order $order, string $reason): Order
    {
        /*
        | ทำลายบิลไม่ผ่าน assertEditable() เพราะบิลที่ปิดไปแล้วก็ยังทำลายได้
        | (เช่นรับเงินผิดคน) จึงต้องเช็คงวดเองตรงนี้
        |
        | และนี่คือช่องที่อันตรายที่สุดถ้าลืม — ทำลายบิลเก่าหนึ่งใบ
        | ยอดขายทั้งเดือนที่ยื่นภาษีไปแล้วเปลี่ยนทันทีโดยไม่มีใครรู้
        */
        app(PeriodLockService::class)->assertEditable($order);

        return DB::transaction(function () use ($order, $reason) {
            $order->update([
                'status' => OrderStatus::Void,
                'voided_by' => auth()->id(),
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            $order->diningTable?->update(['status' => TableStatus::Available]);

            // ใบสั่งครัวที่ยังค้างอยู่ต้องถูกยกเลิกตาม ไม่งั้นครัวทำของที่ไม่มีคนจ่าย
            foreach ($order->kitchenTickets()->open()->get() as $ticket) {
                $this->kitchen->cancel($ticket, 'บิลถูกทำลาย: '.$reason);
            }

            app(TableSessionService::class)->closeForOrder($order, 'staff_closed');

            // บิลถูกทำลาย = ไม่ได้ใช้สิทธิ์จริง คืนวงเงินสวัสดิการให้พนักงาน
            app(StaffBenefitService::class)->revoke($order);

            $this->logger->log('order.void', $order, ['reason' => $reason]);

            return $order;
        });
    }

    /** เลขที่บิลถัดไป เช่น B2609150001 */
    protected function nextOrderNo(Branch $branch, string $datePart): string
    {
        $prefix = 'B'.$datePart;

        $last = Order::where('branch_id', $branch->id)
            ->where('order_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->max('order_no');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * เลือกโปรที่คุ้มที่สุดให้บิลนี้ แล้วบันทึกว่าใช้ใบไหน คืนส่วนลดเป็นบาท
     *
     * บิลหนึ่งใบใช้โปรได้ใบเดียว จึงลบของเดิมทิ้งแล้วเขียนใหม่ทุกครั้ง
     * ไม่ไล่ diff เพราะรายการในบิลเปลี่ยนได้ตลอด โปรที่เคยเข้าเงื่อนไข
     * อาจหลุดไปแล้วหลังลูกค้าลบจานออก
     *
     * ชื่อโปรเก็บเป็น snapshot ใน order_promotions ร้านเปลี่ยนชื่อโปรทีหลัง
     * ใบเสร็จเก่าก็ยังอ่านได้ว่าวันนั้นใช้โปรชื่ออะไร
     */
    protected function syncPromotion(Order $order): float
    {
        /*
        | บิลที่ปิดไปแล้วห้ามคิดโปรใหม่
        |
        | recalculate ถูกเรียกจากหลายทาง รวมถึงตอนคืนเงินและตอนออกรายงาน
        | ถ้าปล่อยให้คิดใหม่ ยอดของบิลที่ลูกค้าจ่ายไปแล้วจะขยับตามโปรที่ร้าน
        | เพิ่งสร้างวันนี้ — เงินในลิ้นชักกับยอดในระบบจะไม่ตรงกันย้อนหลัง
        */
        if (! $order->isOpen()) {
            return (float) $order->promotion_discount;
        }

        $outcome = $this->promotions->bestAutoApplicable($order);

        $order->orderPromotions()->delete();

        if (! $outcome) {
            return 0.0;
        }

        $order->orderPromotions()->create([
            'promotion_id' => $outcome['promotion']->id,
            'name' => $outcome['promotion']->name,
            'discount_amount' => $outcome['discount'],
        ]);

        return (float) $outcome['discount'];
    }

    /**
     * ด่านเดียวที่ทุกเมธอดซึ่งแก้บิลต้องผ่าน
     *
     * เดิมชื่อ assertOpen() และเช็คแค่ว่าบิลยังเปิดอยู่ พอมีการปิดงวดบัญชีเข้ามา
     * เงื่อนไข "แก้บิลนี้ได้ไหม" มีสองข้อแล้ว จึงรวมไว้ที่เดียวและเปลี่ยนชื่อให้ตรงกับหน้าที่
     *
     * รวมไว้ที่นี่ ไม่กระจายไปเรียกตามเมธอด เพราะวันหน้าที่มีคนเพิ่มเมธอดแก้บิลตัวใหม่
     * เขาจะก๊อป assertEditable() ตามของเดิมโดยอัตโนมัติ — ด่านที่ต้องจำว่าต้องใส่
     * คือด่านที่วันหนึ่งจะมีคนลืมใส่
     */
    protected function assertEditable(Order $order): void
    {
        if (! $order->isOpen()) {
            throw new \DomainException('บิลนี้ปิดไปแล้ว ไม่สามารถแก้ไขได้');
        }

        app(PeriodLockService::class)->assertEditable($order);
    }
}
