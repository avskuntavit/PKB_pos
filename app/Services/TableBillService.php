<?php

namespace App\Services;

use App\Enums\KitchenTicketStatus;
use App\Enums\ServiceCallType;
use App\Models\DiningTable;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceCall;

/**
 * บิลของโต๊ะ ในมุมของลูกค้าที่นั่งอยู่
 *
 * ── ปัญหาที่แก้ ─────────────────────────────────────────────
 * ลูกค้าสแกน QR สั่ง แล้วสั่งเพิ่มอีกรอบ ไม่มีที่ไหนบอกว่า
 * "ตกลงสั่งอะไรไปแล้วบ้าง รวมเท่าไหร่" ต้องเรียกพนักงานมาถาม
 *
 * ── ต่างจาก trackPayload ตรงไหน ────────────────────────────
 * trackPayload มองเป็น "ออเดอร์ออนไลน์หนึ่งใบ" ของคนสั่งกลับบ้าน
 * ส่วนที่นี่มองเป็น "บิลของโต๊ะ" ที่โตขึ้นเรื่อย ๆ ตามรอบที่สั่ง
 * จึงจัดกลุ่มตามรอบ และเน้นยอดสะสมกับสถานะรายจานเป็นหลัก
 */
class TableBillService
{
    /** บิลที่เปิดค้างอยู่ของโต๊ะนี้ — null = ยังไม่มีอะไรให้ดู */
    public function forTable(?DiningTable $table): ?array
    {
        $order = $table?->openOrder()->first();

        if (! $order) {
            return null;
        }

        $payload = $this->payload($order);

        // บิลเปล่า (พนักงานเพิ่งเปิดโต๊ะ) ไม่ต้องเด้งแถบขึ้นมากวน
        return $payload['item_count'] > 0 ? $payload : null;
    }

    public function payload(Order $order): array
    {
        $order->loadMissing(['items.modifiers', 'diningTable:id,name']);

        $items = $order->items->where('status', '!=', 'void')->values();
        $stages = $this->kitchenStages($items->pluck('id')->all());

        $rounds = $items
            ->groupBy(fn (OrderItem $i) => (int) ($i->round ?: 1))
            ->sortKeys()
            ->map(fn ($group, $round) => [
                'round' => (int) $round,
                'placed_at' => $group->min('created_at')?->toIso8601String(),
                'items' => $group->map(fn (OrderItem $i) => $this->item($i, $stages))->values()->all(),
            ])
            ->values()
            ->all();

        /*
        | ต้องรวมส่วนลด "ทุกชนิด" ที่ recalculate() หักออกจากยอด
        |
        | ตัวเลขชุดนี้อยู่บนมือถือลูกค้าและเขาเอาไปบวกลบตามเองได้
        |   subtotal − discount + service_charge = grand_total (ร้านที่ราคารวม VAT แล้ว)
        | ขาดตัวไหนไปตัวเดียว บรรทัดที่โชว์จะบวกไม่ได้ยอดสุทธิ
        | แล้วลูกค้าจะเรียกพนักงานมาถามว่าคิดเงินถูกหรือเปล่า — ซึ่งเป็นสิ่งที่หน้านี้ตั้งใจจะกำจัด
        |
        | `staff_discount` เคยหายไปจากรายการนี้ตัวเดียว ทั้งที่ ReceiptDocument
        | กับ SalesExportService นับมันอยู่แล้ว — ใบเสร็จกับบิลบนมือถือจึงบอกไม่ตรงกัน
        */
        $discount = (float) $order->item_discount
            + (float) $order->bill_discount
            + (float) $order->promotion_discount
            + (float) $order->voucher_discount
            + (float) $order->staff_discount;

        return [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'table' => $order->diningTable?->name,
            // นับเป็น "จำนวนจาน" ไม่ใช่จำนวนบรรทัด สั่งผัดกะเพรา 3 จานคือ 3
            'item_count' => (int) round($items->sum(fn (OrderItem $i) => (float) $i->qty)),
            'rounds' => $rounds,
            'totals' => [
                'subtotal' => (float) $order->subtotal,
                'discount' => round($discount, 2),
                'service_charge' => (float) $order->service_charge,
                'tax_amount' => (float) $order->tax_amount,
                'grand_total' => (float) $order->grand_total,
            ],
            'waiting_approval' => $items->where('approval_status', 'pending')->count(),
            'bill_called' => $this->billCalled($order),
        ];
    }

    /** ลูกค้ากดเรียกพนักงานมาเก็บเงิน */
    public function callForBill(Order $order): ServiceCall
    {
        // กดรัวไม่ทำให้เกิดคำขอซ้อน พนักงานจะได้ไม่เห็นรายการเดิมซ้ำสิบแถว
        if ($existing = $this->pendingBillCall($order)) {
            return $existing;
        }

        $order->loadMissing('branch');

        return ServiceCall::create([
            'branch_id' => $order->branch_id,
            'dining_table_id' => $order->dining_table_id,
            'order_id' => $order->id,
            'type' => ServiceCallType::Bill,
            'status' => 'open',
            'business_date' => $order->branch->businessDateFor()->toDateString(),
        ]);
    }

    /* ---------- ภายใน ---------- */

    protected function item(OrderItem $item, array $stages): array
    {
        [$status, $label] = $this->stageOf($item, $stages);

        return [
            'id' => $item->id,
            'name' => $item->product_name,
            'qty' => (float) $item->qty,
            'modifiers_text' => $item->modifiersText(),
            'guest_name' => $item->guest_name,
            'note' => $item->note,
            'line_total' => (float) $item->line_total,
            'status' => $status,
            'status_label' => $label,
        ];
    }

    /**
     * สถานะที่ลูกค้าควรเห็น
     *
     * เรียงจากสิ่งที่ลูกค้าอยากรู้ที่สุด: ของถึงโต๊ะหรือยัง
     * "รอร้านยืนยัน" มาก่อนทุกอย่าง เพราะรายการที่ยังไม่ถูกยืนยัน
     * ครัวยังไม่เห็นเลย ถ้าไปโชว์ว่า "รอเข้าครัว" ลูกค้าจะนั่งรอของที่ไม่มีใครทำ
     *
     * @param  array<int, ?KitchenTicketStatus>  $stages
     * @return array{0: string, 1: string}
     */
    protected function stageOf(OrderItem $item, array $stages): array
    {
        if ($item->approval_status === 'pending') {
            return ['waiting_approval', 'รอร้านยืนยัน'];
        }

        if ($item->status === 'served') {
            return ['served', 'เสิร์ฟแล้ว'];
        }

        if ($item->status === 'pending') {
            return ['waiting_kitchen', 'รอเข้าครัว'];
        }

        return match ($stages[$item->id] ?? null) {
            KitchenTicketStatus::Preparing => ['preparing', 'กำลังทำ'],
            KitchenTicketStatus::Ready => ['ready', 'พร้อมเสิร์ฟ'],
            KitchenTicketStatus::Served => ['served', 'เสิร์ฟแล้ว'],
            default => ['in_kitchen', 'ครัวรับแล้ว'],
        };
    }

    /**
     * สถานะครัวของแต่ละรายการ
     *
     * รายการหนึ่งอาจถูกพิมพ์ลงใบสั่งครัวมากกว่าหนึ่งใบ (เช่นครัวสั่งพิมพ์ซ้ำ)
     * เอาใบล่าสุดเป็นหลัก เพราะเป็นใบที่ครัวถืออยู่จริง
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, ?KitchenTicketStatus>
     */
    protected function kitchenStages(array $itemIds): array
    {
        if (! $itemIds) {
            return [];
        }

        return KitchenTicketItem::with('ticket:id,status')
            ->whereIn('order_item_id', $itemIds)
            ->get()
            ->groupBy('order_item_id')
            ->map(fn ($rows) => $rows->sortByDesc('id')->first()?->ticket?->status)
            ->all();
    }

    protected function billCalled(Order $order): bool
    {
        return $this->pendingBillCall($order) !== null;
    }

    protected function pendingBillCall(Order $order): ?ServiceCall
    {
        return ServiceCall::where('order_id', $order->id)
            ->where('type', ServiceCallType::Bill->value)
            ->pending()
            ->first();
    }
}
