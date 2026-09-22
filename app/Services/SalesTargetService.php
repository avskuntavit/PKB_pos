<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\SalesTarget;
use App\Services\Concerns\SqlDateExpressions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * แปลง "เป้ายอดขายรายเดือน" ให้เป็นเป้ารายวันที่เอาไปเทียบกับยอดจริงได้
 *
 * ทำไมต้องเกลี่ย: ผู้บริหารตั้งเป้าเป็นเดือน แต่คำถามที่ถามทุกเช้าคือ
 * "เมื่อวานเข้าเป้าไหม" ถ้าเอาเป้าเดือนหาร 30 ตรง ๆ วันเสาร์จะดูเข้าเป้าตลอด
 * และวันอังคารจะดูพลาดเป้าตลอด ทั้งที่ทั้งคู่ขายได้ตามปกติของวันนั้น
 *
 * จึงถ่วงน้ำหนักตามวันในสัปดาห์จากยอดขายจริงย้อนหลัง
 * ร้านที่เพิ่งเปิดยังไม่มีประวัติ ก็ถอยไปเกลี่ยเท่ากันทุกวัน
 */
class SalesTargetService
{
    use SqlDateExpressions;

    /** ใช้ยอดขายจริงย้อนหลังกี่สัปดาห์มาหาน้ำหนักวันในสัปดาห์ */
    public const HISTORY_WEEKS = 8;

    /** ต้องมีวันที่ขายจริงอย่างน้อยเท่านี้ ถึงจะเชื่อน้ำหนักที่คำนวณได้ */
    public const MIN_HISTORY_DAYS = 14;

    /** กันไม่ให้วันใดวันหนึ่งกินเป้าไปเกินสัดส่วนนี้ เผื่อประวัติมีวันจัดอีเวนต์ปนมา */
    protected const MAX_WEEKDAY_SHARE = 0.30;

    public function forMonth(int $branchId, int $year, int $month): ?SalesTarget
    {
        return SalesTarget::where('branch_id', $branchId)
            ->where('year', $year)
            ->where('month', $month)
            ->first();
    }

    public function monthlyAmount(int $branchId, int $year, int $month): float
    {
        return (float) ($this->forMonth($branchId, $year, $month)?->target_amount ?? 0);
    }

    /**
     * เป้ารายวันของทั้งเดือน — คีย์เป็น Y-m-d
     *
     * ผลรวมของทุกวันเท่ากับเป้าเดือนเสมอ (ไม่ปัดเศษระหว่างทาง
     * เพราะเป้าสะสมถูกคำนวณจากค่าเหล่านี้ ปัดทีละวันแล้วบวกกันจะเพี้ยนสะสม)
     *
     * @return array<string, float>
     */
    public function dailyPlan(int $branchId, int $year, int $month): array
    {
        $target = $this->monthlyAmount($branchId, $year, $month);
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $days = (int) $start->daysInMonth;

        if ($target <= 0) {
            return array_fill_keys($this->datesOf($start, $days), 0.0);
        }

        $weights = $this->weekdayWeights($branchId);

        // น้ำหนักรวมของ "เดือนนี้" ต่างจาก 1 เพราะแต่ละเดือนมีวันเสาร์ไม่เท่ากัน
        $perDate = [];
        $total = 0.0;

        for ($d = 0; $d < $days; $d++) {
            $date = $start->copy()->addDays($d);
            $w = $weights[$date->dayOfWeek] ?? (1 / 7);

            $perDate[$date->toDateString()] = $w;
            $total += $w;
        }

        if ($total <= 0) {
            return array_fill_keys(array_keys($perDate), $target / $days);
        }

        return array_map(fn (float $w) => $target * $w / $total, $perDate);
    }

    /** เป้าของวันเดียว */
    public function targetForDate(int $branchId, string $date): float
    {
        $day = Carbon::parse($date);

        return $this->dailyPlan($branchId, (int) $day->year, (int) $day->month)[$date] ?? 0.0;
    }

    /**
     * เป้าสะสมตั้งแต่ต้นเดือนถึงวันที่กำหนด (รวมวันนั้น)
     *
     * นี่คือตัวเลขที่ตอบว่า "ถึงวันนี้ควรได้เท่าไหร่แล้ว" ซึ่งต่างจากเป้าทั้งเดือน
     * และเป็นตัวที่ใช้ตัดสินว่าเดือนนี้ยังตามแผนอยู่ไหม
     */
    public function targetToDate(int $branchId, string $date): float
    {
        $day = Carbon::parse($date);
        $plan = $this->dailyPlan($branchId, (int) $day->year, (int) $day->month);

        $sum = 0.0;

        foreach ($plan as $key => $amount) {
            if ($key <= $date) {
                $sum += $amount;
            }
        }

        return $sum;
    }

    /**
     * สัดส่วนยอดขายของแต่ละวันในสัปดาห์ (0 = อาทิตย์ ถึง 6 = เสาร์) รวมกันได้ 1
     *
     * ใช้ "ยอดเฉลี่ยต่อวัน" ไม่ใช่ "ยอดรวม" เพราะช่วงย้อนหลังอาจมีวันจันทร์
     * 9 ครั้งแต่วันอาทิตย์ 8 ครั้ง ถ้าใช้ยอดรวมวันจันทร์จะได้เปรียบฟรี ๆ
     *
     * @return array<int, float>
     */
    public function weekdayWeights(int $branchId, ?Carbon $before = null): array
    {
        $equal = array_fill(0, 7, 1 / 7);

        $end = ($before ?? Carbon::now())->copy()->startOfDay()->subDay();
        $start = $end->copy()->subWeeks(self::HISTORY_WEEKS);

        $expr = $this->weekdayExpression('business_date');

        // ไม่ใช้ alias ตรงนี้โดยตั้งใจ — ไม่มี alias ก็ไม่มีปัญหาเรื่อง prefix ใน SQL ดิบ
        $rows = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("
                  {$expr} AS weekday
                , COALESCE(SUM(grand_total), 0) AS amount
                , COUNT(DISTINCT business_date) AS day_count
            ")
            ->groupByRaw($expr)
            ->get();

        $average = array_fill(0, 7, 0.0);
        $daysSeen = 0;

        foreach ($rows as $row) {
            $weekday = (int) $row->weekday;
            $days = (int) $row->day_count;

            if ($weekday < 0 || $weekday > 6 || $days <= 0) {
                continue;
            }

            $average[$weekday] = (float) $row->amount / $days;
            $daysSeen += $days;
        }

        $sum = array_sum($average);

        // ข้อมูลน้อยเกินไปก็อย่าเดา เกลี่ยเท่ากันตรงไปตรงมากว่า
        if ($sum <= 0 || $daysSeen < self::MIN_HISTORY_DAYS) {
            return $equal;
        }

        return $this->capped(array_map(fn (float $v) => $v / $sum, $average));
    }

    /* ---------- ภายใน ---------- */

    /**
     * ตัดยอดของวันที่สูงผิดปกติลง แล้วเกลี่ยส่วนเกินให้วันอื่นตามสัดส่วนเดิม
     *
     * ถ้าเดือนก่อนมีงานวัดตรงกับวันเสาร์ วันเสาร์อาจกินไป 45% ของทั้งสัปดาห์
     * แล้วเป้าวันเสาร์เดือนนี้จะสูงจนไม่มีทางถึง ซึ่งทำให้ทั้งระบบเป้าเสียความน่าเชื่อถือ
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    protected function capped(array $weights): array
    {
        // วนซ้ำ เพราะการเกลี่ยส่วนเกินอาจดันวันที่เกือบชนเพดานให้ทะลุขึ้นมาอีกวัน
        // 7 รอบพอเสมอ อย่างช้าที่สุดคือชนเพดานทีละวันจนครบ
        for ($pass = 0; $pass < 7; $pass++) {
            $excess = 0.0;

            foreach ($weights as $day => $w) {
                if ($w > self::MAX_WEEKDAY_SHARE) {
                    $excess += $w - self::MAX_WEEKDAY_SHARE;
                    $weights[$day] = self::MAX_WEEKDAY_SHARE;
                }
            }

            if ($excess <= 1e-9) {
                return $weights;
            }

            $room = array_sum(array_filter($weights, fn (float $w) => $w < self::MAX_WEEKDAY_SHARE));

            // ทุกวันชนเพดานพร้อมกันเป็นไปไม่ได้ในทางเลข (7 × 0.30 > 1) แต่กันหารศูนย์ไว้ก่อน
            if ($room <= 0) {
                return array_fill(0, 7, 1 / 7);
            }

            foreach ($weights as $day => $w) {
                if ($w < self::MAX_WEEKDAY_SHARE) {
                    $weights[$day] = $w + $excess * ($w / $room);
                }
            }
        }

        return $weights;
    }

    /** @return list<string> */
    protected function datesOf(Carbon $start, int $days): array
    {
        $dates = [];

        for ($d = 0; $d < $days; $d++) {
            $dates[] = $start->copy()->addDays($d)->toDateString();
        }

        return $dates;
    }
}
