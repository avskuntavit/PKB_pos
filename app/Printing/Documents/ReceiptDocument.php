<?php

namespace App\Printing\Documents;

use App\Models\Order;
use App\Models\OrderItem;
use App\Printing\EscposBuilder;

/**
 * ใบเสร็จรับเงิน
 *
 * คนอ่านคือลูกค้าที่กำลังจะเดินออกจากร้าน สิ่งที่เขาเช็คจริง ๆ มีสามอย่าง:
 * สั่งอะไรไปบ้าง รวมเท่าไหร่ จ่ายไปเท่าไหร่ได้ทอนเท่าไหร่
 * ที่เหลือเป็นข้อมูลทางภาษีซึ่งต้องมีตามกฎหมายแต่ไม่ต้องเด่น
 *
 * ยอดรวมสุดท้ายตัวใหญ่สองเท่า เพราะเป็นบรรทัดเดียวที่ลูกค้าจ้องจริง
 */
class ReceiptDocument
{
    public function __construct(
        protected Order $order,
        protected int $columns = 48,
    ) {}

    public function render(): string
    {
        $o = $this->order;
        $o->loadMissing(['items.modifiers', 'payments', 'branch', 'diningTable:id,name', 'closedBy:id,name']);

        $branch = $o->branch;
        $b = EscposBuilder::make($this->columns)->begin();

        // ── หัวร้าน ──
        $b->align(1)->size(2, 2)->bold()->line($branch->name)->normal()->align(1);

        foreach (array_filter([$branch->address, $branch->phone]) as $row) {
            $b->line($row);
        }

        if (filled($branch->tax_id)) {
            $b->line('เลขประจำตัวผู้เสียภาษี '.$branch->tax_id);
        }

        $b->feed()->align(0);
        $b->columns('เลขที่บิล', (string) $o->order_no);
        $b->columns('วันที่', ($o->closed_at ?? $o->opened_at)?->format('d/m/Y H:i') ?? '-');

        if ($o->diningTable) {
            $b->columns('โต๊ะ', $o->diningTable->name);
        }

        if ($o->closedBy) {
            $b->columns('พนักงาน', $o->closedBy->name);
        }

        $b->rule('=');

        // ── รายการ ──
        foreach ($o->items->where('status', '!=', 'void') as $item) {
            $this->item($b, $item);
        }

        $b->rule('=');

        // ── ยอด ──
        $b->columns('ยอดรวม', $this->baht($o->subtotal));

        foreach ([
            'ส่วนลดรายการ' => $o->item_discount,
            'ส่วนลดท้ายบิล' => $o->bill_discount,
            'ส่วนลดโปรโมชั่น' => $o->promotion_discount,
            'ส่วนลดคูปอง' => $o->voucher_discount,
            'ส่วนลดพนักงาน' => $o->staff_discount,
        ] as $label => $amount) {
            if ((float) $amount > 0) {
                $b->columns($label, '-'.$this->baht($amount));
            }
        }

        if ((float) $o->service_charge > 0) {
            $b->columns('ค่าบริการ', $this->baht($o->service_charge));
        }

        if ((float) $o->delivery_fee > 0) {
            $b->columns('ค่าจัดส่ง', $this->baht($o->delivery_fee));
        }

        if ((float) $o->tax_amount > 0) {
            // ราคารวม VAT แล้วต้องเขียนว่า "รวมอยู่ใน" ไม่งั้นลูกค้าจะบวกซ้ำเอง
            $label = $branch->vat_included ? 'ภาษีมูลค่าเพิ่ม (รวมอยู่แล้ว)' : 'ภาษีมูลค่าเพิ่ม';
            $b->columns($label, $this->baht($o->tax_amount));
        }

        if ((float) $o->rounding != 0.0) {
            $b->columns('ปัดเศษ', $this->baht($o->rounding));
        }

        $b->rule();
        // ตัวอักษรขยาย 2 เท่า -> ใส่ได้ครึ่งเดียวของความกว้างกระดาษ
        $b->size(2, 2)->bold();
        $b->columns('รวม', $this->baht($o->grand_total), width: intdiv($this->columns, 2));
        $b->normal();
        $b->rule();

        // ── การชำระเงิน ──
        foreach ($o->payments as $payment) {
            $b->columns($payment->method->label(), $this->baht($payment->amount));
        }

        $change = (float) $o->payments->sum('change');

        if ($change > 0) {
            $b->bold()->columns('เงินทอน', $this->baht($change))->bold(false);
        }

        $b->feed()->align(1);
        $b->line('ขอบคุณที่ใช้บริการ');

        return $b->cut()->toBytes();
    }

    /**
     * หนึ่งรายการ — ชื่อกับราคาบรรทัดเดียว ตัวเลือกย่อหน้าลงมา
     *
     * ราคาที่โชว์คือราคารวมทั้งบรรทัดแล้ว (รวมตัวเลือก หักส่วนลดรายการ)
     * ไม่ใช่ราคาต่อหน่วย เพราะลูกค้าบวกเองแล้วต้องได้ยอดรวมพอดี
     */
    protected function item(EscposBuilder $b, OrderItem $item): void
    {
        $qty = rtrim(rtrim(number_format((float) $item->qty, 2), '0'), '.');

        $b->columns($qty.' x '.$item->product_name, $this->baht($item->line_total));

        foreach ($item->modifiers as $modifier) {
            $delta = (float) $modifier->price * (float) $item->qty;

            $b->columns(
                '- '.$modifier->name,
                $delta != 0.0 ? $this->baht($delta) : '',
                indent: 2,
            );
        }

        if (filled($item->note)) {
            $b->line('    '.$item->note);
        }
    }

    protected function baht(float|string|null $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
