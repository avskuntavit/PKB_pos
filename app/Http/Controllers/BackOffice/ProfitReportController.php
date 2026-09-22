<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * รายงานต้นทุน–กำไรแยกรายเมนู
 *
 * ต้นทุนใช้ค่าที่ snapshot ไว้ใน order_items ตอนขาย (unit_cost)
 * ไม่ใช่ต้นทุนปัจจุบันของสินค้า — เพราะต้นทุนวัตถุดิบขยับตลอด
 * ถ้าใช้ค่าปัจจุบัน กำไรของเดือนที่แล้วจะเปลี่ยนไปเรื่อย ๆ ทุกครั้งที่ของขึ้นราคา
 */
class ProfitReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);
        $branchIds = $this->branchIds($request);

        // Laravel เติม prefix ให้ alias ด้วย ('oi' -> 'POS2_oi')
        // SQL ดิบจึงต้องอ้างชื่อที่ผ่าน prefix แล้ว ไม่ใช่ oi เปล่า ๆ
        $oi = DB::getTablePrefix().'oi';

        $rows = DB::table('order_items AS oi')
            ->join('orders AS o', 'o.id', '=', 'oi.order_id')
            ->selectRaw("
                  {$oi}.product_id
                , {$oi}.product_name
                , {$oi}.category_name
                , SUM({$oi}.qty) AS qty
                , SUM({$oi}.line_total) AS revenue
                , SUM({$oi}.unit_cost * {$oi}.qty) AS cost
            ")
            ->whereIn('o.branch_id', $branchIds)
            ->whereBetween('o.business_date', [$from, $to])
            ->where('o.status', OrderStatus::Paid->value)
            ->where('oi.status', '!=', 'void')
            ->groupBy('oi.product_id', 'oi.product_name', 'oi.category_name')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($r) {
                $revenue = (float) $r->revenue;
                $cost = (float) $r->cost;
                $profit = round($revenue - $cost, 2);

                return [
                    'product_id' => $r->product_id,
                    'name' => $r->product_name,
                    'category' => $r->category_name ?? 'ไม่ระบุหมวดหมู่',
                    'qty' => (float) $r->qty,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
                ];
            });

        // รวมตามหมวดหมู่ ใช้ดูว่าเงินมาจากกลุ่มไหนจริง ๆ
        $byCategory = $rows->groupBy('category')->map(fn ($items, $name) => [
            'name' => $name,
            'revenue' => round($items->sum('revenue'), 2),
            'cost' => round($items->sum('cost'), 2),
            'profit' => round($items->sum('profit'), 2),
        ])->sortByDesc('profit')->values();

        $revenue = round($rows->sum('revenue'), 2);
        $cost = round($rows->sum('cost'), 2);

        return Inertia::render('BackOffice/Reports/Profit', [
            'filters' => ['from' => $from, 'to' => $to, 'branch_ids' => $branchIds],
            'rows' => $rows,
            'byCategory' => $byCategory,
            'totals' => [
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => round($revenue - $cost, 2),
                'margin' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : 0.0,
            ],
        ]);
    }
}
