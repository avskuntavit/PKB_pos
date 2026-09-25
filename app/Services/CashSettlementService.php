<?php

namespace App\Services;

use App\Enums\CashSettlementStatus;
use App\Enums\OfflineEntryKind;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\CashSettlement;
use App\Models\OfflineSyncEntry;
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
     * เงินสดที่รับมาจริงตอนเน็ตหลุด แต่ยังไม่มีบิลรองรับ
     *
     * ── ทำไมเงินก้อนนี้ต้องมาโผล่ที่นี่ ──────────────────────────────
     * พนักงานเก็บเงินตอนระบบล่ม ลูกค้าเดินออกไปแล้ว พอเน็ตกลับมาลงบิลไม่ได้
     * (มีคนปิดบิลนั้นไปก่อน / ยอดเปลี่ยน / งวดถูกล็อก) เงินอยู่ในลิ้นชักจริง
     * `expectedFor()` มองไม่เห็นเพราะไม่มีแถวใน payments
     * ถ้าไม่นับรวม ปลายวัน `counted` จะมากกว่า `expected` แล้วระบบขึ้นว่า "เงินเกิน"
     * คนที่ทำถูกทุกขั้นตอนจะกลายเป็นคนที่ต้องอธิบายตัวเอง
     *
     * ── นับเฉพาะก้อนที่ยัง "อยู่ในลิ้นชัก" ──────────────────────────
     *   ยังไม่ตัดสิน   นับ — เงินอยู่ในลิ้นชัก รอผู้จัดการบอกว่าจะทำอะไร
     *   overage        นับ — ตัดสินแล้วว่ารับเกิน เงินยังต้องส่งมอบ
     *   booked         ไม่นับ — ผู้จัดการเปิดบิลใหม่แล้ว เงินไปโผล่ใน payments ทางนั้น
     *   refunded       ไม่นับ — คืนลูกค้าไปแล้ว เงินออกจากลิ้นชักไปแล้ว
     *
     * ── วันขายไหน ────────────────────────────────────────────────
     * ใช้ `business_date` ของ **บิล** ไม่ใช่ `client_at` (นาฬิกาแท็บเล็ต เชื่อไม่ได้)
     * และไม่ใช่ `created_at` (เวลาที่ sync ขึ้นมา ซึ่งอาจเป็นวันรุ่งขึ้นถ้าเน็ตกลับมาดึก)
     * วันที่เงินเข้าลิ้นชักคือวันของบิลที่ลูกค้ากินเสมอ
     */
    public function heldCashFor(Branch $branch, string $businessDate): float
    {
        return Money::round((float) $this->heldCashQuery($branch, $businessDate)
            ->sum('offline_sync_entries.amount'));
    }

    /**
     * ก้อนที่ยังไม่มีใครตัดสิน — ต้องเตือนบนหน้าจอ ไม่ใช่กลืนเข้าไปในยอดเงียบ ๆ
     *
     * @return array{count: int, amount: float}
     */
    public function unresolvedHeldCashFor(Branch $branch, string $businessDate): array
    {
        $query = $this->heldCashQuery($branch, $businessDate)
            ->whereNull('offline_sync_entries.resolved_at');

        /*
        | นับกับรวมยอดแยกสองคิวรี ไม่ใช้ selectRaw
        |
        | DB_PREFIX=POS2_ ถูกเติมให้ชื่อตารางใน query builder แต่ไม่เติมให้ข้อความใน selectRaw
        | เขียน selectRaw('SUM(offline_sync_entries.amount)') แล้วจะหาตารางไม่เจอตอนรันจริง
        | ตารางนี้มีไม่กี่แถวต่อวัน สองคิวรีจึงถูกกว่าการเสี่ยงพิมพ์ชื่อตารางเองให้ถูก
        */
        return [
            'count' => (int) (clone $query)->count(),
            'amount' => Money::round((float) $query->sum('offline_sync_entries.amount')),
        ];
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
                'held_cash_amount' => $this->heldCashFor($branch, $businessDate),
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

        /*
        | เทียบกับยอดที่ต้องนำส่ง "ทั้งหมด" ไม่ใช่เฉพาะยอดจากบิล
        |
        | ถ้าเทียบกับ expected_amount เฉย ๆ วันที่มีเงินค้างตอนเน็ตหลุดจะขึ้นว่าโอนเกินทุกครั้ง
        | ทั้งที่พนักงานส่งมอบเงินครบตามที่อยู่ในลิ้นชักจริง
        |
        | ตัวเลขนี้ถูกตรึงไว้ตอนแจ้งโอน — คำตัดสินของผู้จัดการที่มาทีหลังไม่ย้อนไปแก้ใบที่ปิดแล้ว
        | เพราะใบนำส่งคือบันทึกว่า "วันนั้นตกลงกันว่าเท่าไหร่" ไม่ใช่ตัวเลขที่ขยับได้เรื่อย ๆ
        */
        $settlement->forceFill([
            'transferred_amount' => Money::round($amount),
            'diff_amount' => Money::round($amount - $settlement->due()),
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
            'held_cash' => (float) $settlement->held_cash_amount,
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
            // ยอดที่ทวงต้องเป็นยอดที่ต้องนำส่งจริง ไม่งั้นวันที่มีแต่เงินค้างจะถูกมองว่าไม่มีอะไรต้องส่ง
            $expected = $settlement && $settlement->status !== CashSettlementStatus::Pending
                ? $settlement->due()
                : Money::round($this->expectedFor($branch, $date) + $this->heldCashFor($branch, $date));

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
     * ใบรับเงินตอนหลุดที่เงินยังอยู่ในลิ้นชักของวันขายนั้น
     *
     * join ไปที่ orders เพราะวันขายอยู่ที่บิล ไม่ได้อยู่ที่แถวนี้
     * (`order_id` ของ offline_sync_entries เป็น NOT NULL อยู่แล้ว — บิลถูกเปิดไว้ก่อนเน็ตหลุดเสมอ)
     */
    protected function heldCashQuery(Branch $branch, string $businessDate): \Illuminate\Database\Query\Builder
    {
        return DB::table('offline_sync_entries')
            ->join('orders', 'orders.id', '=', 'offline_sync_entries.order_id')
            ->where('offline_sync_entries.branch_id', $branch->id)
            ->where('offline_sync_entries.kind', OfflineEntryKind::PayCash->value)
            ->where('offline_sync_entries.status', OfflineSyncEntry::STATUS_HELD)
            ->where('orders.business_date', $businessDate)
            ->where(fn ($q) => $q
                ->whereNull('offline_sync_entries.resolved_at')
                ->orWhere('offline_sync_entries.resolution', OfflineSyncEntry::RESOLUTION_OVERAGE));
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
