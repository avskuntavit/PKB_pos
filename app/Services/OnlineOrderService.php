<?php

namespace App\Services;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\PaymentIntent;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ออเดอร์ล่วงหน้าจากหน้าร้านออนไลน์
 *
 * ใช้หน้าจอเดียวกันทั้งลูกค้าและพนักงาน ต่างกันที่:
 *   ลูกค้าสั่ง  -> รายการรอร้านกดรับก่อน (ร้านรู้ว่าของหมด/คิวยาว ระบบไม่รู้)
 *   พนักงานสั่ง -> ถือว่าร้านรับแล้ว เข้าครัวทันที (พนักงานคือคนที่กรองให้อยู่แล้ว)
 */
class OnlineOrderService
{
    public const MAX_LINES = 40;

    public function __construct(
        protected OrderService $orders,
        protected KitchenService $kitchen,
        protected ShiftService $shifts,
        protected ActivityLogger $logger,
        protected QueueService $queue,
        protected StaffBenefitService $benefits,
    ) {}

    /**
     * รับออเดอร์เข้าระบบ
     *
     * @param  array<int, array{product_id: int, qty: float, modifier_ids?: array<int>, note?: string|null}>  $lines
     * @param  array{name: string, phone: string, note?: string|null}  $contact
     */
    public function place(
        Branch $branch,
        array $lines,
        array $contact,
        OrderType $type,
        ?Carbon $pickupAt,
        PaymentIntent $intent,
        ?User $staff = null,
        ?DiningTable $table = null,
        ?Customer $member = null,
        ?string $guestName = null,
    ): Order {
        $this->guard($branch, $lines, $staff);

        return DB::transaction(function () use ($branch, $lines, $contact, $type, $pickupAt, $intent, $staff, $table, $guestName) {
            $placedByStaff = $staff !== null;

            /*
            | โต๊ะที่มีบิลเปิดค้างอยู่ ต้องสั่งลงบิลเดิม ไม่ใช่เปิดบิลใหม่
            |
            | ลูกค้านั่งโต๊ะเดียวสั่งหลายรอบเป็นเรื่องปกติ ถ้าเปิดบิลใหม่ทุกรอบ
            | โต๊ะเดียวจะมีบิลค้างหลายใบ ลูกค้าดูยอดสะสมไม่ได้ พนักงานก็เก็บเงินหลายรอบ
            | (ซื้อกลับบ้านไม่มีโต๊ะ จึงเป็นบิลใหม่เสมอ ถูกแล้ว)
            */
            $existing = $table?->openOrder()->first();

            $order = $existing ?? $this->orders->open(
                $branch,
                $table,
                $type,
                max(1, (int) ($contact['guest_count'] ?? 1)),
                $this->shifts->current($branch),
            );

            // บิลที่พนักงานเปิดไว้แต่ยังไม่มีรายการ ลูกค้าสั่งรอบแรกต้องเป็นรอบ 1 ไม่ใช่รอบ 2
            $round = $existing
                ? ((int) $existing->items()->max('round')) + 1
                : 1;

            // ลูกค้าที่ล็อกอินอยู่ใช้บัญชีตัวเองเสมอ ไม่ต้องเดาจากเบอร์ที่พิมพ์
            $customer = $member ?? $this->linkCustomer($branch, $contact);

            /*
            | บิลที่เปิดค้างอยู่แล้ว แตะได้เฉพาะช่องที่ยังว่าง
            |
            | ถ้าเขียนทับหมดทุกรอบ ชื่อผู้สั่งของรอบแรกจะหาย สถานะที่ร้านกดรับไปแล้ว
            | จะเด้งกลับเป็น "รอร้านรับ" และ track_token เดิมที่ลูกค้าเปิดค้างไว้จะใช้ไม่ได้
            */
            if ($existing) {
                $order->update(array_filter([
                    'customer_id' => $order->customer_id ?: $customer?->id,
                    'contact_name' => $order->contact_name ?: $contact['name'],
                    'contact_phone' => $order->contact_phone ?: $contact['phone'],
                    'payment_intent' => $order->payment_intent ?: $intent,
                    'track_token' => $order->track_token ?: Str::random(40),
                    'note' => $this->mergeNote($order->note, $contact['note'] ?? null),
                ], fn ($v) => $v !== null));
            } else {
                $order->update([
                    'source' => $placedByStaff ? OrderSource::Pos : OrderSource::Online,
                    'customer_id' => $customer?->id,
                    'contact_name' => $contact['name'],
                    'contact_phone' => $contact['phone'],
                    'pickup_at' => $pickupAt ?? $branch->earliestPickupAt(),
                    'payment_intent' => $intent,
                    'note' => $contact['note'] ?? null,
                    'track_token' => Str::random(40),
                    'fulfilment_status' => $placedByStaff ? FulfilmentStatus::Accepted : FulfilmentStatus::Placed,
                    'placed_by_user_id' => $staff?->id,
                    'accepted_at' => $placedByStaff ? now() : null,
                ]);
            }

            /*
            | ต้องใช้ sellableAt ไม่ใช่ where('branch_id') ตรง ๆ
            |
            | ตั้งแต่แยกแคตตาล็อกกลางกับแคตตาล็อกสาขา เมนูกลางมี branch_id เป็น NULL
            | การกรองด้วย branch_id ตรง ๆ จึงคัดเมนูกลางออกหมด แล้วสั่งอะไรไม่ได้เลย
            | sellableAt รวมเมนูกลาง + เมนูของสาขานี้ และหักที่ปิดขาย/ของหมดให้แล้ว
            */
            $products = Product::with(['category', 'activeModifierGroups.modifiers' => fn ($q) => $q->where('is_active', true)])
                ->sellableAt($branch->id)
                ->where('is_open_price', false)
                ->whereIn('id', array_column($lines, 'product_id'))
                ->get()
                ->keyBy('id');

            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);

                // เมนูปิดขายหรือไม่ใช่ของสาขานี้ — ข้ามไป ไม่ทำให้ทั้งออเดอร์ล้ม
                if (! $product) {
                    continue;
                }

                /*
                | ชื่อคนสั่งเป็นรายบรรทัดได้ ไม่ใช่ชื่อเดียวทั้งบิล
                |
                | ตะกร้าร่วมของโต๊ะมีหลายคนใส่ของลงตะกร้าเดียวกัน แล้วใครคนหนึ่งกดส่ง
                | ถ้าใช้ชื่อคนกดทั้งบิล จานของทุกคนจะขึ้นชื่อคนเดียวกันหมด
                | แล้วบิลโต๊ะจะแยกรายคนไม่ได้ ซึ่งเป็นเหตุผลหลักที่เก็บชื่อไว้ตั้งแต่แรก
                |
                | หน้าเช็คเอาต์ปกติไม่ได้ส่ง guest_name มาในแต่ละบรรทัด จึงถอยไปใช้ชื่อเดียวทั้งบิลเหมือนเดิม
                */
                $this->addItem($order, $product, (float) $line['qty'], $line['modifier_ids'] ?? [], $line['note'] ?? null, $placedByStaff, $round, $line['guest_name'] ?? $guestName);
            }

            if ($order->items()->count() === 0) {
                throw new \DomainException('ไม่มีเมนูที่สั่งได้ในตอนนี้ กรุณาเลือกใหม่อีกครั้ง');
            }

            /*
            | ประเภทบิลเดาจากรายการที่สั่ง ไม่ได้ให้ลูกค้าเลือกที่หัวบิลแล้ว
            | มีอย่างน้อยหนึ่งจานห่อกลับ = ซื้อกลับบ้าน ไม่งั้นถือว่าทานที่ร้าน
            | (บิลที่พนักงานเลือกโต๊ะไว้ให้ ยึดตามโต๊ะเป็นหลัก)
            */
            if ($table === null) {
                $order->update(['type' => $this->deriveType($order)]);
            }

            $this->orders->recalculate($order);

            // สิทธิ์สวัสดิการพนักงานคิดหลังจากรายการครบแล้ว
            if ($customer?->isVerifiedEmployee()) {
                $this->benefits->apply($order, $this->orders, $customer);
            }

            // บิลเดิมมี event "รับออเดอร์" ของมันอยู่แล้ว บันทึกซ้ำทุกรอบจะอ่านไทม์ไลน์ไม่รู้เรื่อง
            if (! $existing) {
                $this->recordEvent($order, $order->fulfilment_status, $staff?->id);
            }

            // พนักงานสั่งแทน = ร้านรับแล้ว ส่งเข้าครัวได้เลย
            if ($placedByStaff) {
                // เลขคิวออกครั้งเดียวต่อบิล สั่งเพิ่มไม่ใช่คิวใหม่
                if (! $existing) {
                    $this->queue->assign($order);
                }

                $this->orders->sendToKitchen($order->fresh());
            }

            $this->logger->log(
                $placedByStaff ? 'online_order.placed_by_staff' : 'online_order.placed',
                $order,
                ['type' => $type->value, 'intent' => $intent->value, 'lines' => count($lines)],
                $branch,
            );

            return $order->fresh(['items.modifiers']);
        });
    }

    /**
     * หมายเหตุของบิลที่สั่งหลายรอบ
     *
     * ต่อท้ายแทนที่จะทับ เพราะหมายเหตุรอบแรก ("แพ้ถั่ว") ยังต้องอยู่ตอนรอบสอง
     * คืน null เมื่อไม่มีอะไรเปลี่ยน เพื่อให้ array_filter ข้างบนตัดคีย์นี้ทิ้ง
     */
    protected function mergeNote(?string $current, ?string $incoming): ?string
    {
        if (blank($incoming) || $incoming === $current) {
            return null;
        }

        if (blank($current)) {
            return $incoming;
        }

        return str_contains($current, $incoming) ? null : $current."\n".$incoming;
    }

    /** ร้านกดรับออเดอร์ — รายการเข้าครัวทันที */
    public function accept(Order $order, ?User $staff = null): Order
    {
        $this->assertOnline($order);

        return DB::transaction(function () use ($order, $staff) {
            $itemIds = $order->pendingApprovalItems()->pluck('id')->all();

            if ($itemIds) {
                $this->orders->approvePendingItems($order, $itemIds, OrderSource::Online, $staff?->id);
            }

            $order->update([
                'fulfilment_status' => FulfilmentStatus::Accepted,
                'accepted_at' => now(),
            ]);

            // ออกเลขคิวตอนร้านรับ ไม่ใช่ตอนลูกค้ากดสั่ง
            // เพราะใบที่ถูกปฏิเสธไม่ควรกินเลขคิวไปเปล่า ๆ
            $this->queue->assign($order);

            $this->recordEvent($order, FulfilmentStatus::Accepted, $staff?->id);
            $this->logger->log('online_order.accepted', $order, [], $order->branch);

            return $order;
        });
    }

    /** ร้านปฏิเสธ — ทำลายบิลทั้งใบ เพราะลูกค้ายังไม่ได้ของและยังไม่จ่าย */
    public function reject(Order $order, string $reason, ?User $staff = null): Order
    {
        $this->assertOnline($order);

        return DB::transaction(function () use ($order, $reason, $staff) {
            $order->update([
                'fulfilment_status' => FulfilmentStatus::Rejected,
                'reject_reason' => $reason,
            ]);

            $this->orders->void($order, 'ร้านปฏิเสธออเดอร์ออนไลน์: '.$reason);
            $this->recordEvent($order, FulfilmentStatus::Rejected, $staff?->id, $reason);

            return $order;
        });
    }

    /** ลูกค้ากดยกเลิกเอง — ทำได้เฉพาะตอนที่ร้านยังไม่กดรับ */
    public function cancelByCustomer(Order $order): Order
    {
        $this->assertOnline($order);

        if (! $order->fulfilment_status?->isCancellableByCustomer()) {
            throw new \DomainException('ร้านเริ่มทำอาหารแล้ว ยกเลิกเองไม่ได้ กรุณาโทรหาร้านโดยตรง');
        }

        return DB::transaction(function () use ($order) {
            $order->update(['fulfilment_status' => FulfilmentStatus::Cancelled]);
            $this->orders->void($order, 'ลูกค้ายกเลิกออเดอร์ออนไลน์');
            $this->recordEvent($order, FulfilmentStatus::Cancelled);

            return $order;
        });
    }

    public function moveTo(Order $order, FulfilmentStatus $status, ?User $staff = null): Order
    {
        $this->assertOnline($order);

        $timestamps = match ($status) {
            FulfilmentStatus::Ready => ['ready_at' => now()],
            FulfilmentStatus::Completed => ['completed_at' => now()],
            default => [],
        };

        $order->update(['fulfilment_status' => $status] + $timestamps);
        $this->recordEvent($order, $status, $staff?->id);

        return $order;
    }

    /** ข้อมูลที่หน้าติดตามของลูกค้าใช้ (polling) */
    public function trackPayload(Order $order): array
    {
        $order->loadMissing(['items.modifiers', 'branch', 'diningTable:id,name', 'statusEvents', 'review']);

        $status = $order->fulfilment_status ?? FulfilmentStatus::Placed;

        return [
            'order_no' => $order->order_no,
            'queue_number' => $order->queue_number,
            'queue_ahead' => $order->queue_number ? $this->queue->aheadOf($order) : null,
            // นาทีที่คาดว่าจะรอ — คำนวณจากจังหวะที่ร้านทำเสร็จจริงวันนี้ ไม่ใช่ตัวเลขตายตัว
            'queue_wait_minutes' => $this->queue->estimatedWaitMinutes($order),
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_hint' => $status->hint(),
            'step_index' => $status->stepIndex(),
            'is_finished' => $status->isFinished(),
            'can_cancel' => $status->isCancellableByCustomer(),
            'reject_reason' => $order->reject_reason,
            'type' => $order->type->value,
            'type_label' => $order->type->label(),
            'table' => $order->diningTable?->name,
            'pickup_at' => $order->pickup_at?->toIso8601String(),
            'payment_intent' => $order->payment_intent?->value,
            'payment_intent_label' => $order->payment_intent?->label(),
            'is_paid' => $order->status->countsAsSale(),
            'has_review' => $order->review !== null,
            'my_rating' => $order->review?->rating,
            'items' => $order->items
                ->where('status', '!=', 'void')
                ->map(fn (OrderItem $i) => [
                    'id' => $i->id,
                    'name' => $i->product_name,
                    'qty' => (float) $i->qty,
                    'line_total' => (float) $i->line_total,
                    'modifiers' => $i->modifiers->pluck('name')->all(),
                    'note' => $i->note,
                ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $order->subtotal,
                'service_charge' => (float) $order->service_charge,
                'tax_amount' => (float) $order->tax_amount,
                'grand_total' => (float) $order->grand_total,
            ],
            'timeline' => $order->statusEvents->map(fn ($e) => [
                'status' => $e->status->value,
                'label' => $e->status->label(),
                'note' => $e->note,
                'at' => $e->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /* ---------- ภายใน ---------- */

    protected function guard(Branch $branch, array $lines, ?User $staff): void
    {
        // พนักงานสั่งแทนได้แม้ร้านปิดรับออนไลน์ เพราะเขายืนอยู่หน้าร้านจริง
        if (! $staff && ! $branch->isTakingOnlineOrders()) {
            throw new \DomainException('ตอนนี้ร้านปิดรับออเดอร์ออนไลน์ กรุณาลองใหม่ในเวลาทำการ');
        }

        if (empty($lines)) {
            throw new \DomainException('ยังไม่ได้เลือกรายการอาหาร');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new \DomainException('สั่งได้สูงสุด '.self::MAX_LINES.' รายการต่อออเดอร์');
        }
    }

    /**
     * ผูกออเดอร์กับลูกค้าจากเบอร์โทร
     *
     * ไม่มี OTP จึงถือว่า "เบอร์นี้คือช่องทางติดต่อ" ไม่ใช่การยืนยันตัวตน
     * เรื่องแต้มสะสมจึงขึ้นกับการตั้งค่า award_points_online ของสาขา
     * และตัวจริงมาถึงร้านให้พนักงานเห็นหน้าอยู่ดีตอนรับของ
     */
    /**
     * ทานที่ร้านหรือซื้อกลับบ้าน — ดูจากตัวเลือกที่ติดธง marks_takeaway ไว้
     *
     * เก็บ modifier_id ไว้ใน order_item_modifiers อยู่แล้ว จึงย้อนไปดูได้
     * โดยไม่ต้องเทียบชื่อตัวเลือกซึ่งร้านแก้เองได้ตลอด
     */
    protected function deriveType(Order $order): OrderType
    {
        $hasTakeaway = Modifier::whereIn(
            'id',
            OrderItemModifier::whereIn('order_item_id', $order->items()->select('id'))
                ->whereNotNull('modifier_id')
                ->select('modifier_id'),
        )->where('marks_takeaway', true)->exists();

        return $hasTakeaway ? OrderType::Takeaway : OrderType::DineIn;
    }

    protected function linkCustomer(Branch $branch, array $contact): ?Customer
    {
        $phone = preg_replace('/\D/', '', $contact['phone'] ?? '') ?: null;

        if (! $phone) {
            return null;
        }

        $customer = Customer::where('branch_id', $branch->id)->where('phone', $phone)->first();

        if ($customer) {
            return $customer;
        }

        return Customer::create([
            'branch_id' => $branch->id,
            'name' => $contact['name'],
            'phone' => $phone,
        ]);
    }

    protected function addItem(
        Order $order,
        Product $product,
        float $qty,
        array $modifierIds,
        ?string $note,
        bool $placedByStaff,
        int $round = 1,
        ?string $guestName = null,
    ): OrderItem {
        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'category_name' => $product->category?->name,
            // ราคาของสาขาที่เปิดบิล ไม่ใช่ราคากลาง — สาขาที่ตั้งราคาเองจะคิดเงินผิดแบบเงียบ ๆ
            'unit_price' => $product->priceAt($order->branch_id),
            'unit_cost' => (float) $product->cost,
            'qty' => $qty,
            'round' => $round,
            // พนักงานคีย์แทนไม่ต้องมีชื่อเล่น เพราะไม่ได้เป็นคนกิน
            'guest_name' => $placedByStaff ? null : $guestName,
            'note' => $note,
            'status' => 'pending',
            'source' => $placedByStaff ? OrderSource::Pos : OrderSource::Online,
            'approval_status' => $placedByStaff ? null : 'pending',
            'created_by' => $placedByStaff ? auth()->id() : null,
        ]);

        $modifierTotal = 0.0;

        if ($modifierIds) {
            // รับเฉพาะตัวเลือกที่ผูกกับสินค้านี้จริง กันยิง id มั่วจากฝั่ง client
            $allowed = $product->activeModifierGroups->flatMap->modifiers->keyBy('id');

            foreach ($modifierIds as $modifierId) {
                $modifier = $allowed->get($modifierId);

                if (! $modifier) {
                    continue;
                }

                $price = (float) $modifier->price_delta * $qty;
                $modifierTotal += $price;

                $item->modifiers()->create([
                    'modifier_id' => $modifier->id,
                    'group_name' => $modifier->group?->displayName(),
                    'name' => $modifier->name,
                    'price' => $price,
                ]);
            }
        }

        $item->modifier_total = Money::round($modifierTotal);
        $item->recalculate()->save();

        return $item;
    }

    protected function recordEvent(Order $order, ?FulfilmentStatus $status, ?int $userId = null, ?string $note = null): void
    {
        $order->statusEvents()->create([
            'status' => $status ?? FulfilmentStatus::Placed,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }

    protected function assertOnline(Order $order): void
    {
        if ($order->fulfilment_status === null) {
            throw new \DomainException('ออเดอร์นี้ไม่ใช่ออเดอร์ล่วงหน้า');
        }
    }
}
