<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * รายงานสวัสดิการพนักงานองค์กร
 *
 * ตัวเลขในหน้านี้คือ "มูลค่าส่วนลดที่บริษัทออกให้พนักงาน"
 * บัญชีเอาไปตั้งเป็นค่าใช้จ่ายสวัสดิการต่อ จึงต้องแยกรายคนและรายเดือนให้ชัด
 */
class StaffBenefitReportController extends Controller
{
    public function index(Request $request): Response
    {
        $period = $this->period($request);
        $branchIds = $this->branchIds($request);

        return Inertia::render('BackOffice/StaffBenefit/Index', [
            'filters' => ['period' => $period, 'branch_ids' => $branchIds],
            'periods' => $this->availablePeriods($branchIds),
            'summary' => $this->summary($branchIds, $period),
            'rows' => $this->rows($branchIds, $period),
            'monthly' => $this->monthlyTrend($branchIds),
        ]);
    }

    /** ไฟล์ CSV ให้บัญชีเอาไปตั้งเบิก */
    public function export(Request $request): StreamedResponse
    {
        $period = $this->period($request);
        $rows = $this->rows($this->branchIds($request), $period);

        $filename = "staff-benefit-{$period}.csv";

        return response()->streamDownload(function () use ($rows, $period) {
            $out = fopen('php://output', 'w');

            // BOM ให้ Excel ภาษาไทยอ่านออก
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['รอบเดือน', 'รหัสพนักงาน', 'ชื่อ', 'เบอร์โทร', 'จำนวนบิล', 'ยอดที่จ่ายเอง', 'มูลค่าส่วนลด (บริษัทออกให้)']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $period,
                    $row['employee_code'],
                    $row['name'],
                    $row['phone'],
                    $row['order_count'],
                    number_format($row['paid_total'], 2, '.', ''),
                    number_format($row['discount_total'], 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ---------- ภายใน ---------- */

    protected function period(Request $request): string
    {
        $period = (string) $request->input('period', now()->format('Y-m'));

        return preg_match('/^\d{4}-\d{2}$/', $period) ? $period : now()->format('Y-m');
    }

    protected function rows(array $branchIds, string $period): array
    {
        // Laravel เติม prefix ให้ alias ด้วย SQL ดิบจึงต้องอ้างชื่อที่ผ่าน prefix แล้ว
        $sbu = DB::getTablePrefix().'sbu';
        $c = DB::getTablePrefix().'c';

        return DB::table('staff_benefit_usages AS sbu')
            ->leftJoin('customers AS c', 'c.id', '=', 'sbu.customer_id')
            ->selectRaw("
                  {$sbu}.customer_id
                , {$sbu}.employee_code
                , {$c}.name
                , {$c}.phone
                , COUNT(*) AS order_count
                , SUM({$sbu}.discount_amount) AS discount_total
                , SUM({$sbu}.order_total) AS paid_total
            ")
            ->whereIn('sbu.branch_id', $branchIds)
            ->where('sbu.period', $period)
            ->groupBy('sbu.customer_id', 'sbu.employee_code', 'c.name', 'c.phone')
            ->orderByDesc('discount_total')
            ->get()
            ->map(fn ($r) => [
                'customer_id' => $r->customer_id,
                'employee_code' => $r->employee_code ?? '-',
                'name' => $r->name ?? 'ไม่ระบุ',
                'phone' => $r->phone ?? '-',
                'order_count' => (int) $r->order_count,
                'discount_total' => (float) $r->discount_total,
                'paid_total' => (float) $r->paid_total,
            ])
            ->all();
    }

    protected function summary(array $branchIds, string $period): array
    {
        // Laravel เติม prefix ให้ alias ด้วย SQL ดิบจึงต้องอ้างชื่อที่ผ่าน prefix แล้ว
        $sbu = DB::getTablePrefix().'sbu';

        $row = DB::table('staff_benefit_usages AS sbu')
            ->selectRaw("
                  COUNT(*) AS order_count
                , COUNT(DISTINCT {$sbu}.customer_id) AS employee_count
                , COALESCE(SUM({$sbu}.discount_amount), 0) AS discount_total
                , COALESCE(SUM({$sbu}.order_total), 0) AS paid_total
            ")
            ->whereIn('sbu.branch_id', $branchIds)
            ->where('sbu.period', $period)
            ->first();

        return [
            'order_count' => (int) $row->order_count,
            'employee_count' => (int) $row->employee_count,
            'discount_total' => (float) $row->discount_total,
            'paid_total' => (float) $row->paid_total,
            'avg_per_employee' => $row->employee_count > 0
                ? round($row->discount_total / $row->employee_count, 2)
                : 0.0,
        ];
    }

    /** กราฟย้อนหลัง 12 เดือน ให้เห็นแนวโน้มงบสวัสดิการ */
    protected function monthlyTrend(array $branchIds): array
    {
        // Laravel เติม prefix ให้ alias ด้วย SQL ดิบจึงต้องอ้างชื่อที่ผ่าน prefix แล้ว
        $sbu = DB::getTablePrefix().'sbu';

        return DB::table('staff_benefit_usages AS sbu')
            ->selectRaw("
                  {$sbu}.period
                , COALESCE(SUM({$sbu}.discount_amount), 0) AS discount_total
            ")
            ->whereIn('sbu.branch_id', $branchIds)
            ->groupBy('sbu.period')
            ->orderBy('sbu.period')
            ->limit(12)
            ->get()
            ->map(fn ($r) => ['period' => $r->period, 'amount' => (float) $r->discount_total])
            ->all();
    }

    protected function availablePeriods(array $branchIds): array
    {
        $periods = DB::table('staff_benefit_usages')
            ->whereIn('branch_id', $branchIds)
            ->distinct()
            ->orderByDesc('period')
            ->pluck('period')
            ->all();

        $now = now()->format('Y-m');

        return in_array($now, $periods, true) ? $periods : array_merge([$now], $periods);
    }
}
