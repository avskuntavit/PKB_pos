<?php

namespace App\Services;

use App\Enums\CashSettlementStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\CashSettlement;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * นำส่งเงินสดประจำวัน — พนักงานโอนเข้าบัญชีบริษัทแทนการนำฝากเงินสด
 *
 * ── หลักการเดียวที่สำคัญที่สุด ─────────────────────────────
 * ยอดที่ต้องนำส่ง **คำนวณจากบิล ไม่ใช่ให้พนักงานพิมพ์เอง**
 * ถ้าให้พิมพ์เอง ตัวเลขที่เอาไปเทียบก็มาจากคนเดียวกับที่ถือเงิน
 * แล้วการตรวจสอบทั้งระบบก็ไม่เหลือความหมาย
 *
 * ── ทำไมไม่รวมเงินทอนตั้งต้น ──────────────────────────────
 * เงินทอนตั้งต้นเป็นเงินของร้านที่ค้างไว้ในลิ้นชักสำหรับวันถัดไป
 * ไม่ใช่รายได้ที่เพิ่งเก็บมา ถ้ารวมเข้าไปด้วยพนักงานจะต้องโอนเงินที่ไม่ได้รับมา
 */
class CashSettlementService
{
    public function __construct(protected ActivityLogger $logger) {}

    /**
     * ยอดเงินสดที่ควรได้ของวันนั้น
     *
     * = ขายเงินสด + เงินเข้าลิ้นชัก − เงินออกจากลิ้นชัก − คืนเงินสด
     * ไม่รวมเงินทอนตั้งต้น และไม่รวมช่องทางอื่นที่เงินเข้าบัญชีอยู่แล้ว
     */
    public function expectedFor(Branch $branch, string $businessDate): float
    {
        $cashSales = (float) DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.branch_id', $branch->id)
            ->where('orders.business_date', $businessDate)
            ->where('payments.method', PaymentMethod::Cash->value)
            ->sum('payments.amount');

        $refunds = (float) DB::table('refunds')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->where('orders.branch_id', $branch->id)
            ->where('orders.business_date', $businessDate)
            ->where('refunds.method', PaymentMethod::Cash->value)
            ->sum('refunds.amount');

        $shifts = $this->shiftsOf($branch, $businessDate);

        return Money::round(
            $cashSales
            + (float) $shifts->sum('cash_in')
            - (float) $shifts->sum('cash_out')
            - $refunds
        );
    }

    /**
     * เงินที่นับได้จริงในลิ้นชักของวันนั้น หักเงินทอนตั้งต้นออกแล้ว
     *
     * นับเฉพาะรอบที่ปิดแล้ว รอบที่ยังเปิดอยู่ยังไม่มีตัวเลขให้นับ
     */
    public function countedFor(Branch $branch, string $businessDate): float
    {
        $closed = $this->shiftsOf($branch, $businessDate)->where('status', 'closed');

        return Money::round(
            (float) $closed->sum('counted_cash') - (float) $closed->sum('opening_cash')
        );
    }

    /** ยังมีรอบที่เปิดค้างอยู่ไหม — ถ้ามี ตัวเลขที่นับได้ยังไม่ครบ */
    public function hasOpenShift(Branch $branch, string $businessDate): bool
    {
        return $this->shiftsOf($branch, $businessDate)->contains(fn (Shift $s) => $s->status !== 'closed');
    }

    /**
     * ใบนำส่งของวันนั้น สร้างให้ถ้ายังไม่มี
     *
     * ยอดที่ควรได้ถูกคำนวณใหม่ทุกครั้งที่เรียก ตราบใดที่ยังไม่มีใครแจ้งโอน
     * เพราะระหว่างวันยังมีบิลเพิ่มเข้ามาได้เรื่อย ๆ
     */
    public function forDate(Branch $branch, string $businessDate): CashSettlement
    {
        $settlement = CashSettlement::firstOrNew([
            'branch_id' => $branch->id,
            'business_date' => $businessDate,
        ]);

        if (! $settlement->exists || $settlement->status === CashSettlementStatus::Pending) {
            $settlement->fill([
                'expected_amount' => $this->expectedFor($branch, $businessDate),
                'counted_amount' => $this->countedFor($branch, $businessDate),
                'status' => $settlement->status ?? CashSettlementStatus::Pending,
            ])->save();
        }

        return $settlement;
    }

    /** พนักงานแจ้งว่าโอนแล้ว */
    public function submit(
        CashSettlement $settlement,
        float $amount,
        ?string $reference,
        ?string $slipPath,
        User $user,
        ?string $note = null,
    ): CashSettlement {
        if ($settlement->status === CashSettlementStatus::Verified) {
            throw new \DomainException('ใบนำส่งนี้ถูกตรวจกับธนาคารแล้ว แก้ไม่ได้');
        }

        $settlement->forceFill([
            'transferred_amount' => Money::round($amount),
            'diff_amount' => Money::round($amount - (float) $settlement->expected_amount),
            'reference' => $reference,
            'slip_path' => $slipPath ?? $settlement->slip_path,
            'settled_by' => $user->id,
            'transferred_at' => now(),
            'status' => CashSettlementStatus::Submitted,
            'note' => $note,
        ])->save();

        $this->logger->log('cash_settlement.submit', $settlement, [
            'business_date' => $settlement->business_date->toDateString(),
            'expected' => (float) $settlement->expected_amount,
            'transferred' => (float) $settlement->transferred_amount,
        ], $settlement->branch);

        return $settlement;
    }

    /** ผู้จัดการเห็นเงินเข้าบัญชีแล้ว */
    public function verify(CashSettlement $settlement, User $user, ?string $note = null): CashSettlement
    {
        $settlement->forceFill([
            'status' => CashSettlementStatus::Verified,
            'verified_by' => $user->id,
            'verified_at' => now(),
            'note' => $note ?? $settlement->note,
        ])->save();

        $this->logger->log('cash_settlement.verify', $settlement, [], $settlement->branch);

        return $settlement;
    }

    /** ยอดไม่ตรงกับที่เข้าบัญชี — ต้องตามเรื่อง */
    public function dispute(CashSettlement $settlement, User $user, string $note): CashSettlement
    {
        $settlement->forceFill([
            'status' => CashSettlementStatus::Disputed,
            'verified_by' => $user->id,
            'verified_at' => now(),
            'note' => $note,
        ])->save();

        $this->logger->log('cash_settlement.dispute', $settlement, ['note' => $note], $settlement->branch);

        return $settlement;
    }

    /**
     * วันที่ยังนำส่งไม่จบ ย้อนหลังไม่เกิน $days วัน
     *
     * รวมวันที่ยังไม่มีใบนำส่งเลยด้วย เพราะ "ไม่มีใบ" คือการค้างที่อันตรายที่สุด
     * ถ้าไล่จากตารางนำส่งอย่างเดียว วันที่ไม่มีใครกดจะหายไปจากรายงานเงียบ ๆ
     *
     * @return array<int, array{business_date: string, expected: float, settlement: ?CashSettlement}>
     */
    public function outstanding(Branch $branch, int $days = 14): array
    {
        $today = $branch->businessDateFor();
        $settlements = CashSettlement::where('branch_id', $branch->id)
            ->where('business_date', '>=', $today->copy()->subDays($days)->toDateString())
            ->get()
            ->keyBy(fn (CashSettlement $s) => $s->business_date->toDateString());

        $rows = [];

        for ($i = 0; $i <= $days; $i++) {
            $date = $today->copy()->subDays($i)->toDateString();
            $settlement = $settlements->get($date);
            $expected = $settlement && $settlement->status !== CashSettlementStatus::Pending
                ? (float) $settlement->expected_amount
                : $this->expectedFor($branch, $date);

            // วันที่ไม่มีเงินสดเข้าเลยไม่ต้องทวง
            if ($expected <= 0 && ! $settlement) {
                continue;
            }

            if ($settlement && $settlement->status === CashSettlementStatus::Verified) {
                continue;
            }

            $rows[] = [
                'business_date' => $date,
                'expected' => $expected,
                'settlement' => $settlement,
            ];
        }

        return $rows;
    }

    /**
     * รอบการขายทั้งหมดของวันนั้น
     *
     * ใช้ where ไม่ใช่ whereDate เพราะ business_date เป็นคอลัมน์ DATE อยู่แล้ว
     * การห่อด้วย DATE() ซ้ำทำให้ index ใช้ไม่ได้ และบน SQL Server ยังแปลงชนิดไม่ตรงอีก
     *
     * @return \Illuminate\Support\Collection<int, Shift>
     */
    protected function shiftsOf(Branch $branch, string $businessDate)
    {
        return Shift::where('branch_id', $branch->id)
            ->where('business_date', $businessDate)
            ->get();
    }
}
