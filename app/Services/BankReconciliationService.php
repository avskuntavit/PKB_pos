<?php

namespace App\Services;

use App\Enums\CashSettlementStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReconcileStatus;
use App\Models\BankReconciliation;
use App\Models\Branch;
use App\Models\CashSettlement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * กระทบยอดเงินกับธนาคาร — ต่อวัน แยกตามช่องทางรับเงิน
 *
 * ── คำถามเดียวที่รายงานนี้ต้องตอบ ──────────────────────────
 * "เงินของวันนั้นเข้าบัญชีบริษัทครบหรือยัง"
 * ยอดขายไม่ใช่คำตอบ เพราะเงินที่เข้าบัญชีจริงไม่เท่ากับที่ลูกค้าจ่าย
 *   บัตรเครดิต       เข้าหลังหักค่าธรรมเนียม
 *   แอปเดลิเวอรี่    เข้าหลังหัก GP และเข้าเป็นรอบ ไม่ใช่รายวัน
 *   เงินสด           ไม่เข้าเองเลย ต้องรอพนักงานโอน
 * รายงานนี้จึงคิด "ยอดที่ควรเข้าบัญชี" ไม่ใช่ "ยอดขาย"
 *
 * ── เงินสดมาจากใบนำส่ง ไม่ใช่จากยอดขายเงินสด ────────────────
 * ยอดเงินสดที่ควรเข้าบัญชีคือยอดที่พนักงานต้องโอน ซึ่ง CashSettlementService
 * คำนวณไว้แล้ว (รวมเงินเข้า-ออกลิ้นชัก และหักคืนเงินสด)
 * ถ้าคิดใหม่ที่นี่จากยอดขายเงินสดอย่างเดียว เลขสองหน้าจะไม่ตรงกัน
 * แล้วไม่มีใครรู้ว่าควรเชื่อหน้าไหน
 *
 * ── บิลที่ยกเลิกยังนับ ─────────────────────────────────────
 * ตัดตามเงินที่เคลื่อนจริง ไม่ใช่ตามสถานะบิล เงินที่รับมาแล้วคืนไป
 * จะเห็นเป็นรับ + คืน ไม่ใช่หายไปทั้งคู่ เพราะในสเตทเมนต์ก็เห็นสองรายการ
 *
 * ผมไม่ใช่ผู้ทำบัญชี — วิธีจัดกลุ่มและตัวเลขต้องให้ผู้ทำบัญชียืนยันก่อนใช้จริง
 */
class BankReconciliationService
{
    public function __construct(
        protected CashSettlementService $cash,
        protected ActivityLogger $logger,
    ) {}

    /**
     * ตารางกระทบยอดรายวัน เรียงวันล่าสุดขึ้นก่อน
     *
     * @return array<int, array<string, mixed>>
     */
    public function daily(Branch $branch, string $from, string $to): array
    {
        $payments = $this->paymentTotals($branch, $from, $to);
        $refunds = $this->refundTotals($branch, $from, $to);
        $settlements = $this->settlementsOf($branch, $from, $to);
        $records = $this->recordsOf($branch, $from, $to);

        $dates = array_unique(array_merge(
            array_keys($payments),
            array_keys($refunds),
            array_keys($settlements),
            array_keys($records),
        ));

        rsort($dates);

        $days = [];

        foreach ($dates as $date) {
            $channels = $this->channelsFor(
                $payments[$date] ?? [],
                $refunds[$date] ?? [],
                $this->cashFor($branch, $date, $settlements[$date] ?? null),
                $records[$date] ?? [],
            );

            if (! $channels) {
                continue;
            }

            $days[] = [
                'business_date' => $date,
                'channels' => $channels,
                'expected_total' => Money::round(array_sum(array_column($channels, 'expected'))),
                'actual_total' => Money::round(array_sum(array_map(
                    fn (array $c) => $c['actual'] ?? 0.0,
                    $channels,
                ))),
                'diff_total' => Money::round(array_sum(array_column($channels, 'diff'))),
                'open_count' => count(array_filter(
                    $channels,
                    fn (array $c) => $c['status'] !== ReconcileStatus::Matched->value,
                )),
            ];
        }

        return $days;
    }

    /**
     * รวมยอดต่อช่องทางของวันเดียว
     *
     * แยกเป็นฟังก์ชันล้วน ๆ ไม่แตะฐานข้อมูล เพราะนี่คือหัวใจของรายงาน
     * และต้องทดสอบตัวเลขได้โดยไม่ต้องมีข้อมูลจริง
     *
     * @param  array<string, array{gross: float, fee: float, count: int}>  $payments  key = ช่องทาง
     * @param  array<string, float>  $refunds  key = ช่องทาง
     * @param  array{expected: float, declared: ?float, status: ?string}|null  $cash
     * @param  array<string, array<string, mixed>>  $records  key = ช่องทาง
     * @return array<int, array<string, mixed>>
     */
    public function channelsFor(array $payments, array $refunds, ?array $cash, array $records): array
    {
        $channels = array_unique(array_merge(
            array_keys($payments),
            array_keys($refunds),
            array_keys($records),
            $cash ? [PaymentMethod::Cash->value] : [],
        ));

        $rows = [];

        foreach ($channels as $channel) {
            $isCash = $channel === PaymentMethod::Cash->value;

            $gross = (float) ($payments[$channel]['gross'] ?? 0);
            $fee = (float) ($payments[$channel]['fee'] ?? 0);
            $refund = (float) ($refunds[$channel] ?? 0);

            /*
            | เงินสดใช้ยอดจากใบนำส่ง ช่องทางอื่นคิดจากที่รับมา − ค่าธรรมเนียม − ที่คืนไป
            |
            | ค่าธรรมเนียมถูกหักก่อนเงินเข้าบัญชี ธนาคารจึงโอนมาเป็นยอดสุทธิอยู่แล้ว
            | ถ้าเอายอดเต็มไปเทียบ ทุกวันจะขึ้นว่าไม่ตรงทั้งที่ไม่มีอะไรผิด
            */
            $expected = $isCash && $cash
                ? Money::round($cash['expected'])
                : Money::round($gross - $fee - $refund);

            $record = $records[$channel] ?? null;
            $actual = $record !== null && $record['actual'] !== null ? (float) $record['actual'] : null;
            $status = $record['status'] ?? ReconcileStatus::Pending->value;

            // ไม่มีอะไรเกิดขึ้นในช่องทางนี้และไม่มีใครเคยกด — ไม่ต้องขึ้นเป็นแถวให้รก
            if ($gross == 0.0 && $refund == 0.0 && $expected == 0.0 && $record === null) {
                continue;
            }

            $rows[] = [
                'id' => $record['id'] ?? null,
                'channel' => $channel,
                'label' => PaymentMethod::tryFrom($channel)?->label() ?? $channel,
                'gross' => Money::round($gross),
                'fee' => Money::round($fee),
                'refund' => Money::round($refund),
                'expected' => $expected,
                // เงินสด: ยอดที่พนักงานแจ้งว่าโอน — ต่างจากยอดที่เข้าบัญชีจริง
                'declared' => $isCash && $cash ? $cash['declared'] : null,
                'settlement_status' => $isCash && $cash ? $cash['status'] : null,
                'actual' => $actual,
                'diff' => $actual === null ? 0.0 : Money::round($actual - $expected),
                'status' => $status,
                'status_label' => ReconcileStatus::tryFrom($status)?->label() ?? $status,
                'reference' => $record['reference'] ?? null,
                'note' => $record['note'] ?? null,
                'reconciled_at' => $record['reconciled_at'] ?? null,
                'reconciled_by' => $record['reconciled_by'] ?? null,
                'payment_count' => (int) ($payments[$channel]['count'] ?? 0),
            ];
        }

        usort($rows, fn (array $a, array $b) => $this->channelOrder($a['channel']) <=> $this->channelOrder($b['channel']));

        return $rows;
    }

    /** ยอดรวมทั้งช่วง แยกตามช่องทาง — แถวสรุปบนหัวรายงาน */
    public function channelTotals(array $days): array
    {
        $totals = [];

        foreach ($days as $day) {
            foreach ($day['channels'] as $channel) {
                $key = $channel['channel'];

                $totals[$key] ??= [
                    'channel' => $key,
                    'label' => $channel['label'],
                    'expected' => 0.0,
                    'fee' => 0.0,
                    'diff' => 0.0,
                    'open_count' => 0,
                ];

                $totals[$key]['expected'] += $channel['expected'];
                $totals[$key]['fee'] += $channel['fee'];
                $totals[$key]['diff'] += $channel['diff'];
                $totals[$key]['open_count'] += $channel['status'] === ReconcileStatus::Matched->value ? 0 : 1;
            }
        }

        $totals = array_map(fn (array $t) => [
            ...$t,
            'expected' => Money::round($t['expected']),
            'fee' => Money::round($t['fee']),
            'diff' => Money::round($t['diff']),
        ], $totals);

        uasort($totals, fn (array $a, array $b) => $this->channelOrder($a['channel']) <=> $this->channelOrder($b['channel']));

        return array_values($totals);
    }

    /** ยอดรวมทั้งหมดของช่วงที่เลือก */
    public function totals(array $days): array
    {
        return [
            'day_count' => count($days),
            'expected' => Money::round(array_sum(array_column($days, 'expected_total'))),
            'actual' => Money::round(array_sum(array_column($days, 'actual_total'))),
            'diff' => Money::round(array_sum(array_column($days, 'diff_total'))),
            'open_count' => (int) array_sum(array_column($days, 'open_count')),
        ];
    }

    /**
     * ติ๊กว่ากระทบแล้ว
     *
     * ── ทำไมไม่กรอกยอดก็กดได้ ──────────────────────────────
     * ส่วนใหญ่ยอดตรงอยู่แล้ว การบังคับพิมพ์เลขเดิมซ้ำทุกวันทำให้คนเลิกใช้
     * ไม่กรอก = ยืนยันว่าตรงตามที่ระบบคำนวณ กรอกเมื่อไหร่คือ "เห็นเลขอื่นในสเตทเมนต์"
     *
     * ── ทำไมไม่มีค่าความคลาดเคลื่อนที่ยอมรับได้ ────────────
     * ต่างแค่บาทเดียวก็คือต่าง ถ้าตั้งเพดานไว้ ส่วนต่างเล็ก ๆ ที่เกิดทุกวัน
     * จะไม่มีใครเห็นจนกลายเป็นก้อนใหญ่ตอนปิดปี
     */
    public function reconcile(
        Branch $branch,
        string $businessDate,
        string $channel,
        ?float $actual,
        ?string $reference,
        ?string $note,
        User $user,
    ): BankReconciliation {
        $expected = $this->expectedFor($branch, $businessDate, $channel);
        $amount = $actual ?? $expected;
        $diff = Money::round($amount - $expected);

        $record = BankReconciliation::firstOrNew([
            'branch_id' => $branch->id,
            'business_date' => $businessDate,
            'channel' => $channel,
        ]);

        $record->forceFill([
            'branch_id' => $branch->id,
            'business_date' => $businessDate,
            'channel' => $channel,
            'expected_amount' => $expected,
            'actual_amount' => $amount,
            'diff_amount' => $diff,
            'status' => $diff == 0.0 ? ReconcileStatus::Matched : ReconcileStatus::Mismatched,
            'reference' => $reference,
            'note' => $note,
            'reconciled_by' => $user->id,
            'reconciled_at' => now(),
        ])->save();

        $this->logger->log('bank_reconciliation.reconcile', $record, [
            'business_date' => $businessDate,
            'channel' => $channel,
            'expected' => $expected,
            'actual' => $amount,
            'diff' => $diff,
        ], $branch);

        return $record;
    }

    /** ยกเลิกการติ๊ก — กดผิดวันหรือผิดช่องทางแล้วต้องแก้ได้ */
    public function unreconcile(BankReconciliation $record, User $user): BankReconciliation
    {
        $record->forceFill([
            'actual_amount' => null,
            'diff_amount' => 0,
            'status' => ReconcileStatus::Pending,
            'reconciled_by' => null,
            'reconciled_at' => null,
        ])->save();

        $this->logger->log('bank_reconciliation.unreconcile', $record, [
            'business_date' => $record->business_date->toDateString(),
            'channel' => $record->channel,
            'by' => $user->id,
        ], $record->branch);

        return $record;
    }

    /**
     * ยอดที่ควรเข้าบัญชีของช่องทางเดียว วันเดียว
     *
     * คิดใหม่ตอนกดเสมอ ไม่เชื่อตัวเลขที่ส่งมาจากหน้าจอ
     * เพราะยอดที่เอาไปเทียบต้องมาจากบิล ไม่ใช่จากคนที่กำลังกดยืนยัน
     */
    public function expectedFor(Branch $branch, string $businessDate, string $channel): float
    {
        if ($channel === PaymentMethod::Cash->value) {
            $settlement = CashSettlement::where('branch_id', $branch->id)
                ->where('business_date', $businessDate)
                ->first();

            return $this->cashFor($branch, $businessDate, $settlement)['expected'];
        }

        $payments = $this->paymentTotals($branch, $businessDate, $businessDate)[$businessDate][$channel] ?? null;
        $refund = $this->refundTotals($branch, $businessDate, $businessDate)[$businessDate][$channel] ?? 0;

        return Money::round(
            (float) ($payments['gross'] ?? 0)
            - (float) ($payments['fee'] ?? 0)
            - (float) $refund
        );
    }

    /**
     * ยอดเงินสดของวันนั้นในมุมของการกระทบยอด
     *
     * ใช้ยอดที่ล็อกไว้ในใบนำส่งถ้าพนักงานแจ้งโอนแล้ว เพราะนั่นคือยอดที่ทั้งสองฝั่งตกลงกัน
     * ถ้ายังไม่มีใครแจ้ง ค่อยคำนวณสด ๆ จากบิล — วิธีเดียวกับหน้ารายการนำส่ง
     *
     * @return array{expected: float, declared: ?float, status: ?string}
     */
    protected function cashFor(Branch $branch, string $businessDate, ?CashSettlement $settlement): array
    {
        $locked = $settlement && $settlement->status !== CashSettlementStatus::Pending;

        return [
            'expected' => $locked
                ? (float) $settlement->expected_amount
                : $this->cash->expectedFor($branch, $businessDate),
            'declared' => $locked ? (float) $settlement->transferred_amount : null,
            'status' => $settlement?->status->value,
        ];
    }

    /**
     * ยอดรับเงินต่อวันต่อช่องทาง
     *
     * Laravel เติม prefix ให้ alias ด้วย SQL ดิบจึงต้องอ้างชื่อที่ผ่าน prefix แล้ว
     *
     * @return array<string, array<string, array{gross: float, fee: float, count: int}>>
     */
    protected function paymentTotals(Branch $branch, string $from, string $to): array
    {
        $p = DB::getTablePrefix().'p';
        $o = DB::getTablePrefix().'o';

        $rows = DB::table('payments AS p')
            ->join('orders AS o', 'o.id', '=', 'p.order_id')
            ->selectRaw("
                  {$o}.business_date
                , {$p}.method
                , SUM({$p}.amount) AS gross_amount
                , SUM({$p}.fee) AS fee_amount
                , COUNT(*) AS payment_count
            ")
            ->where('o.branch_id', $branch->id)
            ->whereBetween('o.business_date', [$from, $to])
            ->groupBy('o.business_date', 'p.method')
            ->get();

        return $this->indexByDateAndChannel($rows, fn ($row) => [
            'gross' => (float) $row->gross_amount,
            'fee' => (float) $row->fee_amount,
            'count' => (int) $row->payment_count,
        ]);
    }

    /**
     * ยอดคืนเงินต่อวันต่อช่องทาง
     *
     * ตัดตามวันขายของบิลเดิม ไม่ใช่วันที่กดคืน — วิธีเดียวกับ CashSettlementService
     * ถ้าสองหน้าตัดคนละวัน ยอดเงินสดจะไม่ตรงกันทุกครั้งที่มีการคืนข้ามวัน
     *
     * @return array<string, array<string, float>>
     */
    protected function refundTotals(Branch $branch, string $from, string $to): array
    {
        $r = DB::getTablePrefix().'r';
        $o = DB::getTablePrefix().'o';

        $rows = DB::table('refunds AS r')
            ->join('orders AS o', 'o.id', '=', 'r.order_id')
            ->selectRaw("
                  {$o}.business_date
                , {$r}.method
                , SUM({$r}.amount) AS refund_amount
            ")
            ->where('o.branch_id', $branch->id)
            ->whereBetween('o.business_date', [$from, $to])
            ->groupBy('o.business_date', 'r.method')
            ->get();

        return $this->indexByDateAndChannel($rows, fn ($row) => (float) $row->refund_amount);
    }

    /** @return array<string, CashSettlement> */
    protected function settlementsOf(Branch $branch, string $from, string $to): array
    {
        return CashSettlement::where('branch_id', $branch->id)
            ->whereBetween('business_date', [$from, $to])
            ->get()
            ->keyBy(fn (CashSettlement $s) => $s->business_date->toDateString())
            ->all();
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    protected function recordsOf(Branch $branch, string $from, string $to): array
    {
        $out = [];

        BankReconciliation::with('reconciledBy:id,name')
            ->where('branch_id', $branch->id)
            ->whereBetween('business_date', [$from, $to])
            ->get()
            ->each(function (BankReconciliation $r) use (&$out) {
                $out[$r->business_date->toDateString()][$r->channel] = [
                    'id' => $r->id,
                    'actual' => $r->actual_amount === null ? null : (float) $r->actual_amount,
                    'status' => $r->status->value,
                    'reference' => $r->reference,
                    'note' => $r->note,
                    'reconciled_at' => $r->reconciled_at?->toIso8601String(),
                    'reconciled_by' => $r->reconciledBy?->name,
                ];
            });

        return $out;
    }

    /**
     * จัดผลรวมดิบให้เป็น [วัน][ช่องทาง]
     *
     * business_date กลับมาเป็น string จาก query builder ดิบ และบางไดรเวอร์
     * ใส่เวลา 00:00:00 ต่อท้ายมาด้วย จึงตัดเอาเฉพาะสิบตัวแรกเสมอ
     * ไม่งั้นคีย์ของวันเดียวกันจะไม่ตรงกับคีย์ที่มาจาก Eloquent
     */
    protected function indexByDateAndChannel($rows, callable $map): array
    {
        $out = [];

        foreach ($rows as $row) {
            $date = substr((string) $row->business_date, 0, 10);
            $out[$date][(string) $row->method] = $map($row);
        }

        return $out;
    }

    /** ลำดับที่อยากให้เห็นในตาราง — เงินสดก่อนเสมอเพราะเป็นก้อนที่เสี่ยงที่สุด */
    protected function channelOrder(string $channel): int
    {
        $order = array_flip(array_column(PaymentMethod::cases(), 'value'));

        return $order[$channel] ?? 99;
    }
}
