<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\ActivityLog;
use App\Models\BranchStockItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Concerns\SqlDateExpressions;
use Illuminate\Support\Facades\DB;

/**
 * รายงานสรุปหน้าแรกของหลังบ้าน
 *
 * ทุกเมธอดรับ branchIds (array) + ช่วงวันขาย เพื่อให้ดูรวมหลายสาขาได้
 * และอิงกับ business_date เสมอ ไม่ใช่ created_at
 *
 * ── เรื่อง alias กับ table prefix ──────────────────────────────────
 * Laravel เติม prefix ให้ alias ด้วย ไม่ใช่แค่ชื่อตาราง
 *
 *   DB::table('orders AS o')   ->   "POS2_orders" as "POS2_o"
 *
 * ตัว query builder รู้เรื่องนี้เอง where('o.branch_id') จึงถูกต้อง
 * แต่ SQL ดิบใน selectRaw ไม่มีใครเติมให้ ต้องอ้าง POS2_o เอง
 * จึงใช้ $this->a('o') แทนการพิมพ์ o. ตรง ๆ ทุกที่ที่เป็น raw
 */
class SummaryReportService
{
    use SqlDateExpressions;

    public function __construct(
        protected array $branchIds,
        protected string $from,
        protected string $to,
    ) {}

    public static function make(array $branchIds, string $from, string $to): static
    {
        return new static($branchIds, $from, $to);
    }

    /** การ์ดยอดขายสุทธิด้านบนสุด */
    public function salesSummary(): array
    {
        $o = $this->a('o');

        $row = $this->paidOrders()
            ->selectRaw("
                  COUNT(*) AS bill_count
                , COALESCE(SUM({$o}.subtotal), 0) AS gross_sales
                , COALESCE(SUM({$o}.item_discount), 0) AS item_discount
                , COALESCE(SUM({$o}.bill_discount + {$o}.promotion_discount + {$o}.voucher_discount + {$o}.staff_discount), 0) AS bill_discount
                , COALESCE(SUM({$o}.service_charge), 0) AS service_charge
                , COALESCE(SUM({$o}.delivery_fee), 0) AS delivery_fee
                , COALESCE(SUM({$o}.tax_amount), 0) AS tax_amount
                , COALESCE(SUM({$o}.rounding), 0) AS rounding
                , COALESCE(SUM({$o}.grand_total), 0) AS net_sales
                , COALESCE(SUM({$o}.cost_total), 0) AS cost_total
                , COALESCE(SUM({$o}.guest_count), 0) AS guest_count
            ")
            ->first();

        $netSales = (float) $row->net_sales;

        return [
            'bill_count' => (int) $row->bill_count,
            'gross_sales' => (float) $row->gross_sales,
            'item_discount' => (float) $row->item_discount,
            'bill_discount' => (float) $row->bill_discount,
            'service_charge' => (float) $row->service_charge,
            'delivery_fee' => (float) $row->delivery_fee,
            'tax_amount' => (float) $row->tax_amount,
            'rounding' => (float) $row->rounding,
            'net_sales' => $netSales,
            'cost_total' => (float) $row->cost_total,
            'gross_profit' => round($netSales - (float) $row->cost_total, 2),
            'guest_count' => (int) $row->guest_count,
            'avg_per_bill' => $row->bill_count > 0 ? round($netSales / $row->bill_count, 2) : 0.0,
            'avg_per_guest' => $row->guest_count > 0 ? round($netSales / $row->guest_count, 2) : 0.0,
        ];
    }

    /** สัดส่วนช่องทางการชำระเงิน — เงินสด vs อื่น ๆ */
    public function paymentBreakdown(): array
    {
        $pm = $this->a('pm');

        $rows = DB::table('payments AS pm')
            ->join('orders AS o', 'o.id', '=', 'pm.order_id')
            ->selectRaw("
                  {$pm}.method
                , SUM({$pm}.amount) AS amount
                , COUNT(*) AS count
            ")
            ->whereIn('o.branch_id', $this->branchIds)
            ->whereBetween('o.business_date', [$this->from, $this->to])
            ->where('o.status', OrderStatus::Paid->value)
            ->groupBy('pm.method')
            ->orderByDesc('amount')
            ->get();

        $total = (float) $rows->sum('amount');

        return $rows->map(fn ($r) => [
            'method' => $r->method,
            'label' => \App\Enums\PaymentMethod::tryFrom($r->method)?->label() ?? $r->method,
            'amount' => (float) $r->amount,
            'count' => (int) $r->count,
            'percent' => $total > 0 ? round($r->amount / $total * 100, 2) : 0.0,
        ])->all();
    }

    /** บิลแยกตามประเภท — ทานที่ร้าน / ซื้อกลับบ้าน / จัดส่ง */
    public function billsByType(): array
    {
        $o = $this->a('o');

        $rows = $this->paidOrders()
            ->selectRaw("
                  {$o}.type
                , COUNT(*) AS bill_count
                , COALESCE(SUM({$o}.grand_total), 0) AS amount
            ")
            ->groupBy('o.type')
            ->get()
            ->keyBy('type');

        return collect(OrderType::cases())->map(fn (OrderType $type) => [
            'type' => $type->value,
            'label' => $type->label(),
            'bill_count' => (int) ($rows[$type->value]->bill_count ?? 0),
            'amount' => (float) ($rows[$type->value]->amount ?? 0),
        ])->all();
    }

    /** บิลที่ยกเลิก — คืนเงิน / ทำลายบิล */
    public function cancelledBills(): array
    {
        $o = $this->a('o');
        $r = $this->a('r');

        $void = $this->baseOrders()
            ->where('o.status', OrderStatus::Void->value)
            ->selectRaw("COUNT(*) AS c, COALESCE(SUM({$o}.grand_total), 0) AS amount")
            ->first();

        $refund = DB::table('refunds AS r')
            ->join('orders AS o', 'o.id', '=', 'r.order_id')
            ->whereIn('o.branch_id', $this->branchIds)
            ->whereBetween('o.business_date', [$this->from, $this->to])
            ->selectRaw("COUNT(*) AS c, COALESCE(SUM({$r}.amount), 0) AS amount")
            ->first();

        return [
            'refund' => ['count' => (int) $refund->c, 'amount' => (float) $refund->amount],
            'void' => ['count' => (int) $void->c, 'amount' => (float) $void->amount],
        ];
    }

    /** กราฟยอดขายรายวัน */
    public function dailySales(): array
    {
        $o = $this->a('o');

        return $this->paidOrders()
            ->selectRaw("
                  {$o}.business_date AS date
                , COUNT(*) AS bill_count
                , COALESCE(SUM({$o}.grand_total), 0) AS amount
            ")
            ->groupBy('o.business_date')
            ->orderBy('o.business_date')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->date,
                'bill_count' => (int) $r->bill_count,
                'amount' => (float) $r->amount,
            ])->all();
    }

    /** ยอดขายแยกตามช่วงเวลา (ราย ชม.) — ใช้ดูว่าชั่วโมงไหนคนแน่น */
    public function salesByHour(string $basis = 'closed_at'): array
    {
        $o = $this->a('o');
        $column = $basis === 'opened_at' ? "{$o}.opened_at" : "{$o}.closed_at";
        $hourExpr = $this->hourExpression($column);

        $rows = $this->paidOrders()
            ->selectRaw("{$hourExpr} AS hour, COUNT(*) AS bill_count, COALESCE(SUM({$o}.grand_total), 0) AS amount")
            ->groupByRaw($hourExpr)
            ->get()
            ->keyBy(fn ($r) => (int) $r->hour);

        // เติมชั่วโมงที่ไม่มียอดให้ครบ 24 ช่อง กราฟจะได้ไม่ขาด
        return collect(range(0, 23))->map(fn (int $h) => [
            'hour' => $h,
            'label' => sprintf('%02d:00', $h),
            'bill_count' => (int) ($rows[$h]->bill_count ?? 0),
            'amount' => (float) ($rows[$h]->amount ?? 0),
        ])->all();
    }

    /** ยอดขายแยกตามวันในสัปดาห์ */
    public function salesByWeekday(): array
    {
        $o = $this->a('o');
        $labels = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
        $expr = $this->weekdayExpression("{$o}.business_date");

        $rows = $this->paidOrders()
            ->selectRaw("{$expr} AS weekday, COALESCE(SUM({$o}.grand_total), 0) AS amount, COUNT(*) AS bill_count")
            ->groupByRaw($expr)
            ->get()
            ->keyBy(fn ($r) => (int) $r->weekday);

        return collect(range(0, 6))->map(fn (int $d) => [
            'weekday' => $d,
            'label' => $labels[$d],
            'amount' => (float) ($rows[$d]->amount ?? 0),
            'bill_count' => (int) ($rows[$d]->bill_count ?? 0),
        ])->all();
    }

    /** อันดับสินค้าขายดี */
    public function topProducts(int $limit = 10): array
    {
        $oi = $this->a('oi');

        return DB::table('order_items AS oi')
            ->join('orders AS o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('products AS p', 'p.id', '=', 'oi.product_id')
            ->selectRaw("
                  {$oi}.product_id
                , {$oi}.product_name
                , {$oi}.category_name
                , SUM({$oi}.qty) AS qty
                , SUM({$oi}.line_total) AS amount
            ")
            ->whereIn('o.branch_id', $this->branchIds)
            ->whereBetween('o.business_date', [$this->from, $this->to])
            ->where('o.status', OrderStatus::Paid->value)
            ->where('oi.status', '!=', 'void')
            ->groupBy('oi.product_id', 'oi.product_name', 'oi.category_name')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'name' => $r->product_name,
                'category' => $r->category_name,
                'qty' => (float) $r->qty,
                'amount' => (float) $r->amount,
            ])->all();
    }

    /** หมวดหมู่ขายดี */
    public function topCategories(int $limit = 10): array
    {
        $oi = $this->a('oi');

        $rows = DB::table('order_items AS oi')
            ->join('orders AS o', 'o.id', '=', 'oi.order_id')
            ->selectRaw("
                  {$oi}.category_name
                , SUM({$oi}.qty) AS qty
                , SUM({$oi}.line_total) AS amount
            ")
            ->whereIn('o.branch_id', $this->branchIds)
            ->whereBetween('o.business_date', [$this->from, $this->to])
            ->where('o.status', OrderStatus::Paid->value)
            ->where('oi.status', '!=', 'void')
            ->groupBy('oi.category_name')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get();

        $total = (float) $rows->sum('amount');

        return $rows->map(fn ($r) => [
            'name' => $r->category_name ?? 'ไม่ระบุหมวดหมู่',
            'qty' => (float) $r->qty,
            'amount' => (float) $r->amount,
            'percent' => $total > 0 ? round($r->amount / $total * 100, 2) : 0.0,
        ])->all();
    }

    /** การ์ด "สินค้า" — มีเมนูกี่รายการที่ขายได้จริงในช่วงนี้ */
    public function productCoverage(): array
    {
        $soldCount = DB::table('order_items AS oi')
            ->join('orders AS o', 'o.id', '=', 'oi.order_id')
            ->whereIn('o.branch_id', $this->branchIds)
            ->whereBetween('o.business_date', [$this->from, $this->to])
            ->where('o.status', OrderStatus::Paid->value)
            ->where('oi.status', '!=', 'void')
            ->distinct()
            ->count('oi.product_id');

        $totalCount = Product::whereIn('branch_id', $this->branchIds)
            ->where('is_active', true)
            ->count();

        return [
            'sold' => $soldCount,
            'total' => $totalCount,
            'percent' => $totalCount > 0 ? round($soldCount / $totalCount * 100, 2) : 0.0,
        ];
    }

    /** การ์ดสินค้าคงคลัง — มูลค่าเติมของ / ของเสีย */
    public function inventorySummary(): array
    {
        // ไม่ได้ตั้ง alias จึงอ้างชื่อคอลัมน์เปล่า ๆ ได้ตรง ๆ
        $rows = StockMovement::whereIn('branch_id', $this->branchIds)
            ->whereBetween('business_date', [$this->from, $this->to])
            ->selectRaw('
                  type
                , COUNT(*) AS count
                , COALESCE(SUM(cost), 0) AS cost
            ')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        /*
        | ของใกล้หมดนับที่ยอดของสาขา ไม่ใช่ที่แม่แบบกลาง
        |
        | scope low() ข้ามของที่ยังไม่ได้ตั้งจุดสั่งซื้อ (0) ด้วย — ของเดิมนับรวม
        | ทำให้ทุกชิ้นที่ยอดเป็น 0 และยังไม่ตั้งจุดสั่งซื้อถูกนับว่าใกล้หมดตลอดเวลา
        */
        $lowStock = BranchStockItem::whereIn('branch_id', $this->branchIds)->low()->count();

        return [
            'purchase' => [
                'count' => (int) ($rows['purchase']->count ?? 0),
                'amount' => (float) ($rows['purchase']->cost ?? 0),
            ],
            'waste' => [
                'count' => (int) ($rows['waste']->count ?? 0),
                'amount' => (float) ($rows['waste']->cost ?? 0),
            ],
            'low_stock_count' => $lowStock,
        ];
    }

    /** การ์ดโปรโมชั่น */
    public function promotionSummary(): array
    {
        $o = $this->a('o');

        $row = $this->paidOrders()
            ->selectRaw("
                  COUNT(*) AS bill_count
                , SUM(CASE WHEN {$o}.promotion_discount + {$o}.voucher_discount > 0 THEN 1 ELSE 0 END) AS promo_bill_count
                , COALESCE(SUM({$o}.promotion_discount + {$o}.voucher_discount), 0) AS discount_amount
            ")
            ->first();

        return [
            'bill_count' => (int) $row->bill_count,
            'promo_bill_count' => (int) $row->promo_bill_count,
            'discount_amount' => (float) $row->discount_amount,
            'percent' => $row->bill_count > 0
                ? round($row->promo_bill_count / $row->bill_count * 100, 2)
                : 0.0,
        ];
    }

    /** การ์ดโต๊ะ — รอบการใช้โต๊ะและเวลานั่งเฉลี่ย */
    public function tableSummary(): array
    {
        $o = $this->a('o');
        $diff = $this->minutesDiffExpression("{$o}.opened_at", "{$o}.closed_at");

        $row = $this->paidOrders()
            ->where('o.type', OrderType::DineIn->value)
            ->whereNotNull('o.closed_at')
            ->whereNotNull('o.dining_table_id')
            ->selectRaw("
                  COUNT(*) AS bill_count
                , COUNT(DISTINCT {$o}.dining_table_id) AS table_count
                , COUNT(DISTINCT {$o}.business_date) AS day_count
                , COALESCE(AVG({$diff}), 0) AS avg_minutes
                , COALESCE(MAX({$diff}), 0) AS max_minutes
                , COALESCE(AVG({$o}.guest_count), 0) AS avg_guests
            ")
            ->first();

        $itemsPerTable = DB::table('order_items AS oi')
            ->join('orders AS o', 'o.id', '=', 'oi.order_id')
            ->whereIn('o.branch_id', $this->branchIds)
            ->whereBetween('o.business_date', [$this->from, $this->to])
            ->where('o.status', OrderStatus::Paid->value)
            ->where('oi.status', '!=', 'void')
            ->selectRaw("COUNT(*) AS item_count, COUNT(DISTINCT {$o}.id) AS bill_count")
            ->first();

        $tables = max(1, (int) $row->table_count);
        $days = max(1, (int) $row->day_count);

        return [
            'turnover_per_table_per_day' => round($row->bill_count / $tables / $days, 2),
            'avg_minutes' => round((float) $row->avg_minutes, 2),
            'max_minutes' => round((float) $row->max_minutes, 2),
            'avg_guests' => round((float) $row->avg_guests, 2),
            'guest_count' => (int) $this->paidOrders()->sum('o.guest_count'),
            'avg_items_per_bill' => $itemsPerTable->bill_count > 0
                ? round($itemsPerTable->item_count / $itemsPerTable->bill_count, 2)
                : 0.0,
        ];
    }

    /** การ์ดลูกค้า */
    public function customerSummary(): array
    {
        $summary = $this->salesSummary();
        $dayCount = max(1, DB::table('orders AS o')
            ->whereIn('o.branch_id', $this->branchIds)
            ->whereBetween('o.business_date', [$this->from, $this->to])
            ->distinct()
            ->count('o.business_date'));

        return [
            'total' => $summary['guest_count'],
            'avg_per_day' => round($summary['guest_count'] / $dayCount, 2),
            'avg_spend' => $summary['avg_per_guest'],
        ];
    }

    /** การ์ดพนักงาน — นับ action จาก activity_logs */
    public function staffActivity(): array
    {
        // ไม่ได้ตั้ง alias จึงอ้างชื่อคอลัมน์เปล่า ๆ ได้ตรง ๆ
        $rows = ActivityLog::whereIn('branch_id', $this->branchIds)
            ->whereBetween('business_date', [$this->from, $this->to])
            ->selectRaw('action, COUNT(*) AS count')
            ->groupBy('action')
            ->pluck('count', 'action');

        $labels = ActivityLog::actionLabels();
        $result = [];

        foreach ($labels as $action => $label) {
            $result[] = [
                'action' => $action,
                'label' => $label,
                'count' => (int) ($rows[$action] ?? 0),
            ];
        }

        return [
            'items' => $result,
            'total' => (int) $rows->sum(),
        ];
    }

    /** ประกอบทุกส่วนเป็นก้อนเดียวให้หน้า Summary */
    public function all(): array
    {
        return [
            'sales' => $this->salesSummary(),
            'payments' => $this->paymentBreakdown(),
            'bills_by_type' => $this->billsByType(),
            'cancelled' => $this->cancelledBills(),
            'daily_sales' => $this->dailySales(),
            'sales_by_hour' => $this->salesByHour(),
            'sales_by_weekday' => $this->salesByWeekday(),
            'top_products' => $this->topProducts(),
            'top_categories' => $this->topCategories(5),
            'product_coverage' => $this->productCoverage(),
            'inventory' => $this->inventorySummary(),
            'promotions' => $this->promotionSummary(),
            'customers' => $this->customerSummary(),
            'tables' => $this->tableSummary(),
            'staff' => $this->staffActivity(),
        ];
    }

    /* ---------- helpers ---------- */

    /**
     * ใช้ query builder ดิบ (ไม่ผ่าน Eloquent) เพราะรายงานอ่านอย่างเดียว
     * และไม่ต้องการให้ cast enum มายุ่งกับผลลัพธ์ที่ group แล้ว
     *
     * ตัวที่ส่งเข้า where/groupBy/orderBy ใช้ 'o.xxx' ตามปกติ
     * builder เติม prefix ให้เอง มีแต่ selectRaw ที่ต้องใช้ $this->a('o')
     */
    protected function baseOrders()
    {
        return DB::table('orders AS o')
            ->whereIn('o.branch_id', $this->branchIds)
            ->whereBetween('o.business_date', [$this->from, $this->to]);
    }

    protected function paidOrders()
    {
        return $this->baseOrders()->where('o.status', OrderStatus::Paid->value);
    }
}
