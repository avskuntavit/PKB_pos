<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * รายงานภาษีขาย สำหรับปิดบัญชีและยื่น ภ.พ.30
 *
 * ── สามส่วนที่ผู้ทำบัญชีต้องใช้ ────────────────────────────
 *   1. สรุปรายวัน      ตัวเลขที่เอาไปกรอกแบบ
 *   2. รายใบกำกับ      รายงานภาษีขายที่ต้องเก็บไว้ให้ตรวจ
 *   3. เลขที่ขาดหาย    ถ้าเลขใบกำกับไม่ต่อเนื่อง ต้องอธิบายได้ว่าหายไปไหน
 *
 * ── บิลที่ยกเลิกไม่ได้หายไป ────────────────────────────────
 * รายงานเดิมกรองเฉพาะบิลที่จ่ายแล้ว บิลที่ถูกทำลายจึงหายไปเงียบ ๆ
 * แต่เลขใบกำกับที่ออกไปแล้วยกเลิกได้ ยกเลิกแล้วต้องยังเห็นอยู่ในรายงาน
 * ไม่งั้นจะกลายเป็นเลขขาดหายที่อธิบายไม่ได้ตอนถูกตรวจ
 *
 * ผมไม่ใช่ผู้ทำบัญชี — ตัวเลขและรูปแบบต้องให้ผู้ทำบัญชียืนยันก่อนใช้ยื่นจริง
 */
class SalesTaxReportService
{
    /**
     * สรุปรายวัน
     *
     * @return array<int, array<string, mixed>>
     */
    public function daily(array $branchIds, string $from, string $to): array
    {
        // Laravel เติม prefix ให้ alias ด้วย SQL ดิบจึงต้องอ้างชื่อที่ผ่าน prefix แล้ว
        $o = DB::getTablePrefix().'o';

        return DB::table('orders AS o')
            ->selectRaw("
                  {$o}.business_date
                , COUNT(*) AS bill_count
                , SUM({$o}.grand_total - {$o}.tax_amount - {$o}.rounding) AS net_amount
                , SUM({$o}.tax_amount) AS tax_amount
                , SUM({$o}.rounding) AS rounding
                , SUM({$o}.grand_total) AS total_amount
            ")
            ->whereIn('o.branch_id', $branchIds)
            ->whereBetween('o.business_date', [$from, $to])
            ->where('o.status', OrderStatus::Paid->value)
            ->groupBy('o.business_date')
            ->orderBy('o.business_date')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * รายใบกำกับ
     *
     * เอาทุกใบที่เคยออกเลข รวมที่ยกเลิกแล้ว แล้วติดธงไว้
     * เพราะเลขที่ออกไปแล้วต้องอธิบายได้ทุกเลข
     *
     * @return array<int, array<string, mixed>>
     */
    public function invoices(array $branchIds, string $from, string $to, int $limit = 5000): array
    {
        return Order::with('branch:id,name,code')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$from, $to])
            ->whereNotNull('receipt_no')
            ->orderBy('business_date')
            ->orderBy('receipt_no')
            ->limit($limit)
            ->get()
            ->map(fn (Order $o) => [
                'business_date' => $o->business_date->toDateString(),
                'receipt_no' => $o->receipt_no,
                'order_no' => $o->order_no,
                'branch' => $o->branch?->name,
                'customer' => $o->contact_name,
                'net_amount' => round((float) $o->grand_total - (float) $o->tax_amount - (float) $o->rounding, 2),
                'tax_amount' => (float) $o->tax_amount,
                'rounding' => (float) $o->rounding,
                'total_amount' => (float) $o->grand_total,
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                // ยกเลิกแล้วไม่นับเป็นยอดขาย แต่ยังต้องอยู่ในรายงาน
                'counts_as_sale' => $o->status->countsAsSale(),
                'paid_at' => $o->closed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * เลขที่ใบกำกับที่ขาดหายไป
     *
     * ── ทำไมสำคัญ ─────────────────────────────────────────
     * เลขใบกำกับต้องเรียงต่อเนื่อง ถ้ามีเลขหาย ผู้ทำบัญชีต้องตอบให้ได้ว่าหายเพราะอะไร
     * รายงานนี้ทำให้รู้ตั้งแต่ตอนปิดเดือน ไม่ใช่ตอนถูกตรวจ
     *
     * นับทุกสถานะรวมบิลที่ยกเลิก เพราะบิลที่ยกเลิกยังกินเลขของมันอยู่
     *
     * @return array<int, array{branch_id: int, business_date: string, missing: array<int, string>}>
     */
    public function numberGaps(array $branchIds, string $from, string $to): array
    {
        $rows = Order::whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$from, $to])
            ->whereNotNull('receipt_no')
            ->orderBy('receipt_no')
            ->get(['branch_id', 'business_date', 'receipt_no']);

        $byDay = [];

        foreach ($rows as $row) {
            $key = $row->branch_id.'|'.$row->business_date->toDateString();
            $seq = $this->sequenceOf($row->receipt_no);

            if ($seq !== null) {
                $byDay[$key][] = $seq;
            }
        }

        $gaps = [];

        foreach ($byDay as $key => $numbers) {
            [$branchId, $date] = explode('|', $key);

            $missing = $this->missingIn($numbers);

            if ($missing) {
                $gaps[] = [
                    'branch_id' => (int) $branchId,
                    'business_date' => $date,
                    'missing' => $missing,
                ];
            }
        }

        return $gaps;
    }

    /** ยอดรวมของช่วงที่เลือก — ตัวเลขที่เอาไปกรอก ภ.พ.30 */
    public function totals(array $daily): array
    {
        return [
            'bill_count' => (int) array_sum(array_column($daily, 'bill_count')),
            'net_amount' => round((float) array_sum(array_column($daily, 'net_amount')), 2),
            'tax_amount' => round((float) array_sum(array_column($daily, 'tax_amount')), 2),
            'rounding' => round((float) array_sum(array_column($daily, 'rounding')), 2),
            'total_amount' => round((float) array_sum(array_column($daily, 'total_amount')), 2),
        ];
    }

    /**
     * เลขลำดับที่หายไปจากชุดที่ให้มา
     *
     * แยกออกมาเป็นฟังก์ชันล้วน ๆ เพราะเป็นหัวใจของรายงานนี้
     * และต้องทดสอบได้โดยไม่ต้องมีฐานข้อมูล
     *
     * เลขต้องเริ่มที่ 1 เสมอ ถ้าชุดเริ่มที่ 3 แปลว่า 1 กับ 2 หายไปด้วย
     * ไม่ใช่ว่าวันนั้นเริ่มนับที่ 3
     *
     * @param  array<int, int>  $numbers
     * @return array<int, string>
     */
    public function missingIn(array $numbers): array
    {
        $numbers = array_values(array_unique(array_filter($numbers, fn ($n) => $n >= 1)));

        if (! $numbers) {
            return [];
        }

        $highest = max($numbers);
        $seen = array_flip($numbers);
        $missing = [];

        for ($i = 1; $i <= $highest; $i++) {
            if (! isset($seen[$i])) {
                $missing[] = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            }
        }

        return $missing;
    }

    /**
     * ตัวเลขลำดับท้ายเลขที่ใบเสร็จ เช่น R2609220012 -> 12
     *
     * คืน null เมื่อรูปแบบไม่ตรงที่ระบบออกให้ (เช่นข้อมูลนำเข้าจากระบบเก่า)
     * ดีกว่าเดาแล้วรายงานว่าเลขหายทั้งที่ระบบเก่าใช้รูปแบบอื่น
     */
    protected function sequenceOf(?string $receiptNo): ?int
    {
        if (! is_string($receiptNo) || ! preg_match('/^R\d{6}(\d{4,})$/', $receiptNo, $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
