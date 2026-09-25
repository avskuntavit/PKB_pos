<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * ล็อกงวดบัญชีที่ปิดไปแล้ว
 *
 * ── ปัญหาที่แก้ ──────────────────────────────────────────────────────────
 * เฟส C (กระทบยอดธนาคาร) กับ D (ส่งข้อมูลให้บัญชี) จับได้แค่ว่า "ข้อมูลเปลี่ยนไปหลังส่งแล้ว"
 * แต่ห้ามไม่ให้เปลี่ยนไม่ได้ พอยื่นภาษีเดือนสิงหาไปแล้วมีคนกดคืนเงินบิลเดือนสิงหา
 * ตัวเลขในระบบกับที่ยื่นไปจะไม่ตรงกันตลอดไป และไม่มีใครรู้จนถึงตอนถูกตรวจ
 *
 * ── ขอบเขตที่ตกลงกันไว้ ─────────────────────────────────────────────────
 * ล็อก **เฉพาะฝั่งบิล** — แก้บิล · ชำระเงิน · คืนเงิน · ทำลายบิล
 * ไม่ล็อกสต๊อก ไม่ล็อกใบนำส่งเงินสด ไม่ล็อกการกระทบยอด และไม่ล็อกการส่งข้อมูลให้บัญชี
 * เพราะสามอย่างหลังเป็นงานที่มัก "ทำเสร็จหลังปิดงวด" ล็อกไปจะกลายเป็นขวางงานตัวเอง
 *
 * ── ทำไมไม่แคชผลการเช็ค ─────────────────────────────────────────────────
 * ตัวนี้ถูกเรียกทุกครั้งที่มีคนแตะบิล ดูเหมือนควรแคช แต่การแคชสถานะ "ล็อกอยู่ไหม"
 * แปลว่าช่วงหนึ่งหลังเปิดงวดกลับ ระบบจะยังบอกว่าล็อกอยู่ หรือแย่กว่านั้น
 * ช่วงหนึ่งหลังปิดงวด ระบบจะยังยอมให้แก้บิล — ผิดในทางที่อันตรายที่สุด
 * คิวรีนี้ยิงเข้า unique index ตรง ๆ และเกิดแค่ตอนคนกดทำอะไรจริง ไม่ใช่ในลูป
 */
class PeriodLockService
{
    /** เหตุผลตอนเปิดงวดกลับต้องพิมพ์จริง ไม่ใช่เคาะ "ok" ผ่าน ๆ */
    public const MIN_REASON_CHARS = 10;

    /*
    |--------------------------------------------------------------------------
    | ด่าน
    |--------------------------------------------------------------------------
    */

    /** งวดของวันขายนี้ถูกปิดไปแล้วหรือยัง */
    public function isLocked(int $branchId, CarbonInterface|string|null $businessDate): bool
    {
        if (! $businessDate) {
            return false;
        }

        return (bool) $this->find($branchId, $this->periodOf($businessDate))?->isClosed();
    }

    /**
     * ด่านหลัก — เรียกจาก OrderService และ PaymentService ก่อนแตะบิลทุกครั้ง
     *
     * @throws DomainException
     */
    public function assertEditable(Order $order): void
    {
        if (! $this->isLocked((int) $order->branch_id, $order->business_date)) {
            return;
        }

        $period = $this->periodOf($order->business_date);

        throw new DomainException(
            'งวด '.$period.' ของสถานีนี้ปิดบัญชีไปแล้ว จึงแก้บิลของวันที่ '
            .Carbon::parse($order->business_date)->toDateString().' ไม่ได้'
            .' — ถ้าจำเป็นต้องแก้จริง ให้เจ้าของระบบเปิดงวดกลับที่หน้า "ปิดงวดบัญชี" ก่อน'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ปิด / เปิดกลับ
    |--------------------------------------------------------------------------
    */

    public function close(Branch $branch, string $period, ?User $user = null, ?string $note = null): AccountingPeriod
    {
        $period = $this->normalise($period);

        $existing = $this->find($branch->id, $period);

        if ($existing?->isClosed()) {
            throw new DomainException('งวด '.$period.' ของสถานีนี้ปิดไปแล้ว');
        }

        /*
        | ปิดงวดที่ยังไม่จบไม่ได้
        |
        | ถ้าปิดเดือนที่กำลังขายอยู่ บิลของวันนี้จะแก้ไม่ได้ทันที ทั้งที่ร้านยังเปิดอยู่
        | และพนักงานจะกดอะไรไม่ได้เลยจนกว่าจะมีคนมาเปิดงวดกลับ
        */
        $today = $branch->businessDateFor();

        if ($this->endOf($period)->gte($today)) {
            throw new DomainException(
                'งวด '.$period.' ยังไม่จบ ปิดได้ตั้งแต่วันที่ '
                .$this->endOf($period)->copy()->addDay()->toDateString().' เป็นต้นไป'
            );
        }

        /*
        | ยังมีบิลที่เปิดค้างอยู่ในงวดนั้น = ปิดไม่ได้
        |
        | ถ้าปล่อยผ่าน บิลนั้นจะค้างเป็น "เปิดอยู่" ตลอดไป เพราะพอปิดงวดแล้ว
        | จะไม่มีใครรับเงินหรือทำลายมันได้อีก — เป็นบิลผีที่โผล่ในรายงานทุกเดือน
        */
        $open = $this->openBillCount($branch, $period);

        if ($open > 0) {
            throw new DomainException(
                'งวด '.$period.' ยังมีบิลที่เปิดค้างอยู่ '.$open.' ใบ '
                .'ต้องรับเงินหรือทำลายบิลพวกนั้นให้เรียบร้อยก่อน ไม่งั้นจะค้างถาวร'
            );
        }

        $now = Carbon::now();

        if ($existing) {
            // เคยปิดแล้วเปิดกลับ แล้วปิดใหม่ — ใช้แถวเดิม ประวัติการเปิดกลับต้องไม่หาย
            $existing->update([
                'status' => AccountingPeriod::CLOSED,
                'closed_at' => $now,
                'closed_by' => $user?->getKey(),
                'note' => $note,
            ]);

            $this->logAction('period.close', $branch, $existing, ['period' => $period, 'again' => true]);

            return $existing->refresh();
        }

        $record = AccountingPeriod::create([
            'branch_id' => $branch->id,
            'period' => $period,
            'status' => AccountingPeriod::CLOSED,
            'closed_at' => $now,
            'closed_by' => $user?->getKey(),
            'note' => $note,
        ]);

        $this->logAction('period.close', $branch, $record, ['period' => $period]);

        return $record;
    }

    /**
     * เปิดงวดที่ปิดไปแล้วกลับมา — เฉพาะเจ้าของระบบ และต้องมีเหตุผล
     *
     * ด่านนี้อยู่ในเซอร์วิสไม่ใช่แค่ที่ controller โดยตั้งใจ
     * เพราะงวดที่เปิดกลับได้โดยไม่มีใครรู้ว่าใครเปิดและเพราะอะไร ก็เท่ากับไม่มีล็อก
     */
    public function reopen(Branch $branch, string $period, User $user, string $reason): AccountingPeriod
    {
        $period = $this->normalise($period);
        $record = $this->find($branch->id, $period);

        if (! $record?->isClosed()) {
            throw new DomainException('งวด '.$period.' ของสถานีนี้ไม่ได้ปิดอยู่');
        }

        if (! $user->isOwner()) {
            throw new DomainException('เปิดงวดที่ปิดไปแล้วกลับมาได้เฉพาะเจ้าของระบบ');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_CHARS) {
            throw new DomainException('ต้องเขียนเหตุผลที่เปิดงวดกลับอย่างน้อย '.self::MIN_REASON_CHARS.' ตัวอักษร');
        }

        $record->update([
            'status' => AccountingPeriod::REOPENED,
            'reopened_at' => Carbon::now(),
            'reopened_by' => $user->getKey(),
            'reopen_reason' => $reason,
            'times_reopened' => $record->times_reopened + 1,
        ]);

        $this->logAction('period.reopen', $branch, $record, ['period' => $period, 'reason' => $reason]);

        return $record->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | ข้อมูลสำหรับหน้าจอ
    |--------------------------------------------------------------------------
    */

    /**
     * งวดย้อนหลัง N เดือน พร้อมตัวเลขของแต่ละงวด
     *
     * ยิงคิวรีทีละงวดโดยตั้งใจ ไม่รวบเป็นคิวรีเดียวด้วย GROUP BY เดือน
     * เพราะฟังก์ชันตัดเดือนของ SQL Server กับ sqlite (ที่เทสต์ใช้) คนละตัวกัน
     * และ raw ที่มี prefix ตารางเป็นจุดที่โปรเจกต์นี้พลาดมาแล้ว
     * หน้านี้เปิดเดือนละครั้งสองครั้ง ไม่คุ้มที่จะแลกความถูกต้องกับความเร็ว
     *
     * @return array<int, array<string, mixed>>
     */
    public function overview(Branch $branch, int $months = 12): array
    {
        $cursor = $branch->businessDateFor()->copy()->startOfMonth();

        $records = AccountingPeriod::where('branch_id', $branch->id)
            ->with(['closedBy:id,name', 'reopenedBy:id,name'])
            ->get()
            ->keyBy('period');

        $out = [];

        for ($i = 0; $i < $months; $i++) {
            $period = $cursor->format('Y-m');
            $record = $records->get($period);
            $totals = $this->totalsFor($branch, $period);

            $out[] = [
                'period' => $period,
                'label' => $this->thaiLabel($period),
                'status' => $record?->status ?? 'open',
                'is_closed' => (bool) $record?->isClosed(),
                // เดือนที่ยังขายอยู่ ปิดไม่ได้ ปุ่มต้องเทาไว้ ไม่ใช่กดแล้วค่อยขึ้น error
                'has_ended' => $this->endOf($period)->lt($branch->businessDateFor()),
                'closed_at' => $record?->closed_at?->toIso8601String(),
                'closed_by' => $record?->closedBy?->name,
                'note' => $record?->note,
                'reopened_at' => $record?->reopened_at?->toIso8601String(),
                'reopened_by' => $record?->reopenedBy?->name,
                'reopen_reason' => $record?->reopen_reason,
                'times_reopened' => (int) ($record?->times_reopened ?? 0),
                'bills' => $totals['bills'],
                'sales' => $totals['sales'],
                'open_bills' => $totals['open_bills'],
            ];

            $cursor->subMonthNoOverflow();
        }

        return $out;
    }

    public function openBillCount(Branch $branch, string $period): int
    {
        return (int) $this->totalsFor($branch, $this->normalise($period))['open_bills'];
    }

    /** @return array{bills: int, sales: float, open_bills: int} */
    protected function totalsFor(Branch $branch, string $period): array
    {
        /*
        | ขอบช่วงต้องเป็นสตริง 'Y-m-d' ไม่ใช่ Carbon
        |
        | `business_date` ถูกเก็บเป็นวันที่ล้วน (ดู HasBusinessDate) แต่ query builder
        | แปลง Carbon ที่ผูกเข้ามาเป็น 'Y-m-d H:i:s' ตาม $dateFormat ของโมเดล
        | บน sqlite ที่เทียบสตริงตามตัวอักษร '2026-09-01' >= '2026-09-01 00:00:00'
        | เป็นเท็จ — **วันแรกของงวดจะหลุดออกไปเงียบ ๆ** แล้วยอดปิดงวดขาดไปหนึ่งวัน
        |
        | SQL Server กลืนให้เพราะคอลัมน์เป็นชนิดวันที่จริง อาการจึงโผล่แค่ในเทสต์
        | ซึ่งเป็นที่เดียวที่ควรจับได้ — จับตรงนี้ด้วยการส่งสตริงไปให้ตรงชนิดกัน
        */
        $row = Order::where('branch_id', $branch->id)
            ->whereBetween('business_date', [
                $this->startOf($period)->toDateString(),
                $this->endOf($period)->toDateString(),
            ])
            // ไม่มี join และไม่มี alias ตาราง raw ตรงนี้จึงไม่โดนเรื่อง DB_PREFIX
            ->selectRaw('COUNT(*) as bills')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN grand_total ELSE 0 END) as sales")
            ->selectRaw("SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_bills")
            ->first();

        return [
            'bills' => (int) ($row->bills ?? 0),
            'sales' => round((float) ($row->sales ?? 0), 2),
            'open_bills' => (int) ($row->open_bills ?? 0),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ตัวช่วยเรื่องงวด
    |--------------------------------------------------------------------------
    */

    public function find(int $branchId, string $period): ?AccountingPeriod
    {
        return AccountingPeriod::where('branch_id', $branchId)
            ->where('period', $this->normalise($period))
            ->first();
    }

    public function periodOf(CarbonInterface|string $date): string
    {
        return Carbon::parse($date)->format('Y-m');
    }

    public function startOf(string $period): Carbon
    {
        return Carbon::parse($this->normalise($period).'-01')->startOfDay();
    }

    public function endOf(string $period): Carbon
    {
        return $this->startOf($period)->endOfMonth()->startOfDay();
    }

    /** 'YYYY-MM' — รับ '2026-8' หรือวันที่เต็มมาก็ได้ */
    public function normalise(string $period): string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})$/', trim($period), $m) === 1) {
            return $m[1].'-'.str_pad($m[2], 2, '0', STR_PAD_LEFT);
        }

        try {
            return Carbon::parse($period)->format('Y-m');
        } catch (\Throwable) {
            throw new DomainException('รูปแบบงวดไม่ถูกต้อง ต้องเป็น ปี-เดือน เช่น 2026-08');
        }
    }

    public function thaiLabel(string $period): string
    {
        [$year, $month] = array_map('intval', explode('-', $this->normalise($period)));

        $names = [
            1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
            'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
        ];

        return ($names[$month] ?? $period).' '.($year + 543);
    }

    protected function logAction(string $action, Branch $branch, AccountingPeriod $record, array $meta): void
    {
        // บันทึกไม่สำเร็จต้องไม่ทำให้การปิดงวดล้ม — ของสำคัญคือแถวในตารางงวด
        try {
            app(ActivityLogger::class)->log($action, $record, $meta, $branch);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
