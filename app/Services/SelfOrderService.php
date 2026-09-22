<?php

namespace App\Services;

use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\ServiceCallType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ServiceCall;
use App\Models\TableSession;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * ลูกค้าสั่งอาหารเองผ่าน QR
 *
 * หลักการ: ลูกค้าไม่ต้องล็อกอิน แต่ก็แก้ไข/ลบของที่สั่งไปแล้วไม่ได้เช่นกัน
 * ทุกอย่างที่ลูกค้าสั่งจะเข้าสถานะ "รอพนักงานยืนยัน" ก่อนเสมอ
 * พนักงานเป็นคนตัดสินว่าจะส่งเข้าครัวหรือปฏิเสธ (เช่น ของหมด, กดผิด)
 *
 * เพราะไม่มีการล็อกอิน ระบบจึงไม่ผูกลูกค้ากับบัญชีสมาชิก
 * => ไม่ได้แต้มสะสม และใช้โปรโมชั่นเฉพาะสมาชิกไม่ได้
 *    ถ้าอยากได้ ให้แจ้งพนักงานผูกเบอร์ตอนชำระเงิน
 */
class SelfOrderService
{
    /** กันกดรัว — สั่งได้สูงสุดกี่รายการต่อ 1 ครั้ง */
    public const MAX_LINES_PER_SUBMIT = 30;

    public function __construct(
        protected OrderService $orders,
        protected TableSessionService $sessions,
        protected ActivityLogger $logger,
        protected KitchenService $kitchen,
    ) {}

    /**
     * ลูกค้ากดยืนยันตะกร้า
     *
     * @param  array<int, array{product_id: int, qty: float, modifier_ids?: array<int>, note?: string|null}>  $lines
     */
    public function submit(TableSession $session, array $lines): Order
    {
        if (! $session->isActive()) {
            throw new \DomainException('รอบการสั่งนี้ปิดแล้ว กรุณาสแกน QR ใหม่อีกครั้ง');
        }

        if (empty($lines)) {
            throw new \DomainException('ยังไม่ได้เลือกรายการอาหาร');
        }

        if (count($lines) > self::MAX_LINES_PER_SUBMIT) {
            throw new \DomainException('สั่งได้สูงสุด '.self::MAX_LINES_PER_SUBMIT.' รายการต่อครั้ง');
        }

        return DB::transaction(function () use ($session, $lines) {
            $order = $this->ensureOrder($session);

            $productIds = array_column($lines, 'product_id');

            $products = Product::with('category')
                ->where('branch_id', $session->branch_id)
                ->where('is_active', true)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);

                // เมนูถูกปิดขายหรือไม่ใช่ของสาขานี้ — ข้ามไปเงียบ ๆ ไม่ให้ทั้งตะกร้าล้ม
                if (! $product || $product->is_open_price) {
                    continue;
                }

                $this->addPendingItem(
                    $order,
                    $session,
                    $product,
                    (float) $line['qty'],
                    $line['modifier_ids'] ?? [],
                    $line['note'] ?? null,
                );
            }

            $this->orders->recalculate($order);

            $session->increment('order_count');
            $session->touchActivity();

            $this->logger->log('self_order.submitted', $order, [
                'table' => $session->diningTable?->name,
                'lines' => count($lines),
                'round' => $session->order_count,
            ], $order->branch);

            return $order->fresh(['items.modifiers']);
        });
    }

    /**
     * พนักงานกดยืนยัน — รายการเข้าครัวทันทีหลังอนุมัติ
     *
     * @param  array<int>  $itemIds
     */
    public function approve(Order $order, array $itemIds, ?int $userId = null): EloquentCollection
    {
        $items = $this->orders->approvePendingItems($order, $itemIds, OrderSource::SelfOrder, $userId);

        if ($items->isNotEmpty()) {
            $this->logger->log('self_order.approved', $order, ['count' => $items->count()], $order->branch);
        }

        return $items;
    }

    /** พนักงานปฏิเสธ — เช่นของหมด ลูกค้ากดผิด */
    public function reject(Order $order, array $itemIds, ?string $reason = null): int
    {
        return DB::transaction(function () use ($order, $itemIds, $reason) {
            $items = $order->items()
                ->whereIn('id', $itemIds)
                ->where('approval_status', 'pending')
                ->get();

            foreach ($items as $item) {
                $item->update([
                    'approval_status' => 'rejected',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'status' => 'void',
                    'void_reason' => $reason ?? 'พนักงานปฏิเสธรายการที่ลูกค้าสั่งเอง',
                    'voided_by' => auth()->id(),
                    'line_total' => 0,
                ]);
            }

            $this->orders->recalculate($order);

            $this->logger->log('self_order.rejected', $order, [
                'count' => $items->count(),
                'reason' => $reason,
            ], $order->branch);

            return $items->count();
        });
    }

    /** ลูกค้ากดเรียกพนักงาน / ขอเช็คบิล */
    public function callStaff(TableSession $session, ServiceCallType $type, ?string $note = null): ServiceCall
    {
        $session->loadMissing('branch', 'diningTable');

        // กันกดรัว — ถ้ามีคำขอชนิดเดียวกันค้างอยู่แล้ว คืนอันเดิมไป
        $existing = ServiceCall::where('table_session_id', $session->id)
            ->where('type', $type->value)
            ->pending()
            ->first();

        if ($existing) {
            return $existing;
        }

        $call = ServiceCall::create([
            'branch_id' => $session->branch_id,
            'dining_table_id' => $session->dining_table_id,
            'order_id' => $session->order_id,
            'table_session_id' => $session->id,
            'type' => $type,
            'status' => 'open',
            'note' => $note,
            'business_date' => $session->branch->businessDateFor()->toDateString(),
        ]);

        $session->touchActivity();

        $this->logger->log('self_order.service_call', $call, [
            'table' => $session->diningTable?->name,
            'type' => $type->value,
        ], $session->branch);

        return $call;
    }

    /** สรุปสถานะที่หน้าลูกค้าใช้แสดงผล (polling) */
    public function statusFor(TableSession $session): array
    {
        $order = $session->order;

        if (! $order) {
            return [
                'order_no' => null,
                'items' => [],
                'totals' => ['subtotal' => 0.0, 'grand_total' => 0.0],
                'pending_calls' => $this->pendingCalls($session),
            ];
        }

        $order->load(['items.modifiers', 'kitchenTickets.items']);

        // map order_item_id -> สถานะครัว เพื่อบอกลูกค้าว่า "กำลังทำ" หรือ "เสิร์ฟแล้ว"
        $kitchenStatus = [];

        foreach ($order->kitchenTickets as $ticket) {
            foreach ($ticket->items as $ticketItem) {
                if ($ticketItem->order_item_id) {
                    $kitchenStatus[$ticketItem->order_item_id] = $ticket->status->value;
                }
            }
        }

        $items = $order->items
            ->where('status', '!=', 'void')
            ->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'name' => $item->product_name,
                'qty' => (float) $item->qty,
                'line_total' => (float) $item->line_total,
                'modifiers' => $item->modifiers->pluck('name')->all(),
                'note' => $item->note,
                'mine' => $item->table_session_id === $session->id,
                'stage' => $this->stageFor($item, $kitchenStatus[$item->id] ?? null),
            ])
            ->values()
            ->all();

        return [
            'order_no' => $order->order_no,
            'items' => $items,
            'totals' => [
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->bill_discount + (float) $order->item_discount,
                'service_charge' => (float) $order->service_charge,
                'tax_amount' => (float) $order->tax_amount,
                'grand_total' => (float) $order->grand_total,
            ],
            'pending_calls' => $this->pendingCalls($session),
        ];
    }

    /* ---------- ภายใน ---------- */

    protected function pendingCalls(TableSession $session): array
    {
        return ServiceCall::where('table_session_id', $session->id)
            ->pending()
            ->get()
            ->map(fn (ServiceCall $c) => [
                'type' => $c->type->value,
                'label' => $c->type->label(),
                'status' => $c->status,
            ])
            ->all();
    }

    /** แปลงสถานะภายในเป็นคำที่ลูกค้าอ่านเข้าใจ */
    protected function stageFor(OrderItem $item, ?string $kitchenStatus): string
    {
        if ($item->approval_status === 'pending') {
            return 'waiting_approval';
        }

        return match ($kitchenStatus) {
            'queued' => 'queued',
            'preparing' => 'preparing',
            'ready' => 'ready',
            'served' => 'served',
            default => $item->status === 'served' ? 'served' : 'queued',
        };
    }

    /** บิลของ session นี้ — ยังไม่มีก็เปิดให้ตอนสั่งครั้งแรก */
    protected function ensureOrder(TableSession $session): Order
    {
        if ($session->order_id && $session->order?->isOpen()) {
            return $session->order;
        }

        $session->loadMissing('branch', 'diningTable');

        $order = $this->orders->open(
            $session->branch,
            $session->diningTable,
            OrderType::DineIn,
            $session->guest_count,
            app(ShiftService::class)->current($session->branch),
        );

        $order->update(['source' => OrderSource::SelfOrder]);
        $session->update(['order_id' => $order->id]);

        return $order;
    }

    protected function addPendingItem(
        Order $order,
        TableSession $session,
        Product $product,
        float $qty,
        array $modifierIds,
        ?string $note,
    ): OrderItem {
        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'category_name' => $product->category?->name,
            'unit_price' => (float) $product->price,
            'unit_cost' => (float) $product->cost,
            'qty' => $qty,
            'note' => $note,
            'status' => 'pending',
            'source' => OrderSource::SelfOrder,
            'approval_status' => 'pending',
            'table_session_id' => $session->id,
        ]);

        $modifierTotal = 0.0;

        if ($modifierIds) {
            // จำกัดเฉพาะตัวเลือกที่ผูกกับสินค้านี้จริง กันยิง id มั่วจากฝั่ง client
            // เฉพาะเซ็ตที่เปิดทั้งสองระดับ และตัวเลือกที่ยังเปิดอยู่
            $allowed = $product->activeModifierGroups()
                ->with(['modifiers' => fn ($q) => $q->where('is_active', true)])
                ->get()
                ->flatMap->modifiers
                ->keyBy('id');

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
}
