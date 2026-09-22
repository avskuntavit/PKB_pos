<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Services\Concerns\SqlDateExpressions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * หน้าจอที่ผู้บริหารเปิดดูทุกเช้า
 *
 * ตอบ 3 คำถามตามลำดับ ไม่ใช่ยัดทุกตัวเลขที่มี:
 *   1. เมื่อวาน/วันนี้ขายได้เท่าไหร่ เข้าเป้าไหม
 *   2. เดือนนี้ยังตามแผนอยู่ไหม ถ้าปล่อยแบบนี้สิ้นเดือนจะได้เท่าไหร่
 *   3. แล้วต้องไปทำอะไรต่อ — เมนูไหนดัน ของอะไรต้องซื้อ
 *
 * ตัวเลข "ยอดขาย" ที่นี่คือ grand_total ของบิลที่ปิดแล้ว ตัวเดียวกับหน้ารายงานสรุป
 * จะได้ไม่มีสองความจริงให้เถียงกันในที่ประชุม
 */
class ExecutiveDashboardService
{
    use SqlDateExpressions;

    public function __construct(
        protected SalesTargetService $targets,
    ) {}

    /**
     * @param  list<int>  $branchIds  สาขาที่กำลังดู (รวมยอดกันได้)
     * @param  Branch  $primary  สาขาหลัก ใช้ตัดสินว่า "วันนี้" คือวันขายไหน
     */
    public function build(array $branchIds, Branch $primary): array
    {
        $today = $primary->businessDateFor()->startOfDay();
        $todayKey = $today->toDateString();
        $monthStart = $today->copy()->startOfMonth();

        $plan = $this->mergedPlan($branchIds, (int) $today->year, (int) $today->month);
        $monthTarget = array_sum($plan);

        $todayTarget = $plan[$todayKey] ?? 0.0;
        $targetToDate = $this->sumUpTo($plan, $todayKey);

        $todaySales = $this->sales($branchIds, $todayKey, $todayKey);
        $monthSales = $this->sales($branchIds, $monthStart->toDateString(), $todayKey);

        $yesterday = $today->copy()->subDay()->toDateString();
        $lastWeek = $today->copy()->subWeek()->toDateString();

        return [
            'today' => $this->todayBlock(
                $today,
                $todaySales,
                $todayTarget,
                $this->sales($branchIds, $yesterday, $yesterday),
                $this->sales($branchIds, $lastWeek, $lastWeek),
            ),
            'month' => $this->monthBlock($today, $monthSales, $monthTarget, $targetToDate, $plan, $branchIds),
            'hourly' => $this->hourlySales($branchIds, $todayKey),
            'top_products' => $this->topProducts($branchIds, $todayKey),
            'purchasing' => $this->purchasingWatch($branchIds),
            'has_target' => $monthTarget > 0,
            'food_cost_target' => $this->foodCostTarget($branchIds, (int) $today->year, (int) $today->month),
        ];
    }

    /* ---------- วันนี้ ---------- */

    protected function todayBlock(
        Carbon $today,
        array $sales,
        float $target,
        array $yesterday,
        array $lastWeek,
    ): array {
        $net = $sales['net_sales'];

        return [
            'date' => $today->toDateString(),
            'net_sales' => $net,
            'bill_count' => $sales['bill_count'],
            'guest_count' => $sales['guest_count'],
            'avg_per_bill' => $sales['bill_count'] > 0 ? round($net / $sales['bill_count'], 2) : 0.0,
            'cost_total' => $sales['cost_total'],
            'gross_profit' => round($net - $sales['cost_total'], 2),
            'food_cost_percent' => $this->share($sales['cost_total'], $net),
            'target' => round($target, 2),
            'target_percent' => $this->share($net, $target),
            'target_diff' => round($net - $target, 2),
            // ติดลบ = ขายได้น้อยกว่าวันเทียบ ใช้ขึ้นลูกศรบนการ์ด
            'vs_yesterday' => $this->change($net, $yesterday['net_sales']),
            'vs_last_week' => $this->change($net, $lastWeek['net_sales']),
        ];
    }

    /* ---------- เดือนนี้ ---------- */

    /**
     * @param  array<string, float>  $plan
     * @param  list<int>  $branchIds
     */
    protected function monthBlock(
        Carbon $today,
        array $sales,
        float $monthTarget,
        float $targetToDate,
        array $plan,
        array $branchIds,
    ): array {
        $net = $sales['net_sales'];
        $daysTotal = count($plan);
        $daysElapsed = (int) $today->day;

        return [
            // ปี พ.ศ. เพราะเป็นสิ่งที่เจ้าของร้านกับบัญชีคุยกันจริง
            'label' => $today->locale('th')->translatedFormat('F').' '.((int) $today->year + 543),
            'year' => (int) $today->year,
            'month' => (int) $today->month,
            'net_sales' => $net,
            'bill_count' => $sales['bill_count'],
            'cost_total' => $sales['cost_total'],
            'gross_profit' => round($net - $sales['cost_total'], 2),
            'food_cost_percent' => $this->share($sales['cost_total'], $net),
            'target' => round($monthTarget, 2),
            // "ถึงวันนี้ควรได้เท่าไหร่แล้ว" — ตัวที่บอกว่ายังตามแผนอยู่ไหม
            'target_to_date' => round($targetToDate, 2),
            'percent_of_target' => $this->share($net, $monthTarget),
            'percent_of_pace' => $this->share($net, $targetToDate),
            'pace_diff' => round($net - $targetToDate, 2),
            'projection' => $this->projection($net, $targetToDate, $monthTarget, $daysElapsed, $daysTotal),
            'days_elapsed' => $daysElapsed,
            'days_total' => $daysTotal,
            'daily' => $this->dailySeries($branchIds, $plan, $today),
        ];
    }

    /**
     * คาดการณ์ยอดสิ้นเดือน
     *
     * ถ้ามีเป้า ใช้ "จังหวะเทียบแผน" เพราะแผนรู้อยู่แล้วว่าเหลือวันเสาร์กี่วัน
     * ถ้ายังไม่ตั้งเป้า ก็ได้แค่เฉลี่ยต่อวันคูณจำนวนวัน ซึ่งหยาบกว่าแต่ยังพอใช้
     */
    protected function projection(
        float $net,
        float $targetToDate,
        float $monthTarget,
        int $daysElapsed,
        int $daysTotal,
    ): float {
        if ($targetToDate > 0 && $monthTarget > 0) {
            return round($monthTarget * ($net / $targetToDate), 2);
        }

        if ($daysElapsed <= 0) {
            return 0.0;
        }

        return round($net / $daysElapsed * $daysTotal, 2);
    }

    /**
     * ยอดจริงเทียบเป้า รายวันตลอดเดือน — วันที่ยังไม่ถึงส่งเป็น null
     * เพื่อให้กราฟหยุดเส้นยอดจริงไว้ที่วันนี้ ไม่ลากดิ่งลงศูนย์จนดูเหมือนยอดตก
     *
     * @param  list<int>  $branchIds
     * @param  array<string, float>  $plan
     */
    protected function dailySeries(array $branchIds, array $plan, Carbon $today): array
    {
        $todayKey = $today->toDateString();
        $dates = array_keys($plan);

        if ($dates === []) {
            return [];
        }

        $actual = DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('business_date', [$dates[0], $dates[count($dates) - 1]])
            ->selectRaw('
                  business_date
                , COALESCE(SUM(grand_total), 0) AS amount
            ')
            ->groupBy('business_date')
            ->get()
            // SQL Server คืนคอลัมน์ date มาพร้อมเวลา ('2026-09-01 00:00:00.000')
            // ตัดให้เหลือ Y-m-d ก่อน ไม่งั้นคีย์จะไม่ตรงกับแผนแล้วกราฟจะว่างทั้งเดือน
            ->mapWithKeys(fn ($r) => [substr((string) $r->business_date, 0, 10) => (float) $r->amount]);

        $rows = [];
        $running = 0.0;
        $runningTarget = 0.0;

        foreach ($plan as $date => $target) {
            $isPast = $date <= $todayKey;
            $amount = (float) ($actual[$date] ?? 0);

            if ($isPast) {
                $running += $amount;
            }

            $runningTarget += $target;

            $rows[] = [
                'date' => $date,
                'day' => (int) substr($date, 8, 2),
                'actual' => $isPast ? round($amount, 2) : null,
                'target' => round($target, 2),
                'cumulative' => $isPast ? round($running, 2) : null,
                'cumulative_target' => round($runningTarget, 2),
            ];
        }

        return $rows;
    }

    /* ---------- วิเคราะห์ต่อ ---------- */

    /** ยอดขายรายชั่วโมงของวันนี้ — บอกว่าช่วงไหนยังเงียบ พอจะดันได้ */
    protected function hourlySales(array $branchIds, string $date): array
    {
        $expr = $this->hourExpression('closed_at');

        $rows = DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::Paid->value)
            ->where('business_date', $date)
            ->whereNotNull('closed_at')
            ->selectRaw("
                  {$expr} AS hour
                , COALESCE(SUM(grand_total), 0) AS amount
                , COUNT(*) AS bill_count
            ")
            ->groupByRaw($expr)
            ->get()
            ->keyBy(fn ($r) => (int) $r->hour);

        return collect(range(0, 23))->map(fn (int $h) => [
            'hour' => $h,
            'label' => sprintf('%02d:00', $h),
            'amount' => (float) ($rows[$h]->amount ?? 0),
            'bill_count' => (int) ($rows[$h]->bill_count ?? 0),
        ])->all();
    }

    protected function topProducts(array $branchIds, string $date, int $limit = 6): array
    {
        $oi = $this->a('oi');

        return DB::table('order_items AS oi')
            ->join('orders AS o', 'o.id', '=', 'oi.order_id')
            ->selectRaw("
                  {$oi}.product_name
                , {$oi}.category_name
                , SUM({$oi}.qty) AS qty
                , SUM({$oi}.line_total) AS amount
            ")
            ->whereIn('o.branch_id', $branchIds)
            ->where('o.business_date', $date)
            ->where('o.status', OrderStatus::Paid->value)
            ->where('oi.status', '!=', 'void')
            ->groupBy('oi.product_name', 'oi.category_name')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->product_name,
                'category' => $r->category_name,
                'qty' => (float) $r->qty,
                'amount' => (float) $r->amount,
            ])->all();
    }

    /**
     * ของที่ต่ำกว่าจุดสั่งซื้อ — สะพานไปหน้าจัดซื้อ
     *
     * เรียงตาม "ขาดไปกี่เท่าของจุดสั่งซื้อ" ไม่ใช่จำนวนดิบ
     * เพราะพริก 100 กรัมกับเนื้อ 100 กรัม เร่งด่วนไม่เท่ากัน
     */
    protected function purchasingWatch(array $branchIds, int $limit = 8): array
    {
        $items = Ingredient::whereIn('branch_id', $branchIds)
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->whereColumn('stock_qty', '<=', 'reorder_level')
            ->orderByRaw('stock_qty / reorder_level')
            ->limit($limit)
            ->get();

        return [
            'low_stock_count' => Ingredient::whereIn('branch_id', $branchIds)
                ->where('is_active', true)
                ->where('reorder_level', '>', 0)
                ->whereColumn('stock_qty', '<=', 'reorder_level')
                ->count(),
            'items' => $items->map(fn (Ingredient $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'unit_label' => $i->unitLabel(),
                'stock_qty' => (float) $i->stock_qty,
                'reorder_level' => (float) $i->reorder_level,
                'shortfall' => round(max(0, (float) $i->reorder_level - (float) $i->stock_qty), 3),
                'is_out' => (float) $i->stock_qty <= 0,
            ])->all(),
        ];
    }

    /** เป้าต้นทุนวัตถุดิบ % — เฉลี่ยของสาขาที่ตั้งไว้ ถ้าไม่มีใครตั้งเลยคืน null */
    protected function foodCostTarget(array $branchIds, int $year, int $month): ?float
    {
        $values = [];

        foreach ($branchIds as $id) {
            $percent = $this->targets->forMonth($id, $year, $month)?->food_cost_percent;

            if ($percent !== null) {
                $values[] = (float) $percent;
            }
        }

        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }

    /* ---------- ภายใน ---------- */

    /**
     * รวมแผนรายวันของหลายสาขาเป็นก้อนเดียว
     *
     * @param  list<int>  $branchIds
     * @return array<string, float>
     */
    protected function mergedPlan(array $branchIds, int $year, int $month): array
    {
        $merged = [];

        foreach ($branchIds as $id) {
            foreach ($this->targets->dailyPlan($id, $year, $month) as $date => $amount) {
                $merged[$date] = ($merged[$date] ?? 0.0) + $amount;
            }
        }

        ksort($merged);

        return $merged;
    }

    /** @param  array<string, float>  $plan */
    protected function sumUpTo(array $plan, string $date): float
    {
        $sum = 0.0;

        foreach ($plan as $key => $amount) {
            if ($key <= $date) {
                $sum += $amount;
            }
        }

        return $sum;
    }

    protected function sales(array $branchIds, string $from, string $to): array
    {
        $row = DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('business_date', [$from, $to])
            ->selectRaw('
                  COUNT(*) AS bill_count
                , COALESCE(SUM(grand_total), 0) AS net_sales
                , COALESCE(SUM(cost_total), 0) AS cost_total
                , COALESCE(SUM(guest_count), 0) AS guest_count
            ')
            ->first();

        return [
            'bill_count' => (int) ($row->bill_count ?? 0),
            'net_sales' => (float) ($row->net_sales ?? 0),
            'cost_total' => (float) ($row->cost_total ?? 0),
            'guest_count' => (int) ($row->guest_count ?? 0),
        ];
    }

    /** เปอร์เซ็นต์ที่หารศูนย์ไม่พัง — ไม่มีฐานให้เทียบก็คืน null ให้หน้าบ้านซ่อนไปเลย */
    protected function share(float $value, float $base): ?float
    {
        return $base > 0 ? round($value / $base * 100, 1) : null;
    }

    /** เทียบกับวันอื่น คืนทั้งส่วนต่างและเปอร์เซ็นต์ */
    protected function change(float $now, float $before): array
    {
        return [
            'base' => round($before, 2),
            'diff' => round($now - $before, 2),
            'percent' => $before > 0 ? round(($now - $before) / $before * 100, 1) : null,
        ];
    }
}
