<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Services\SalesTaxReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * รายงานภาษีขาย — สรุปรายวัน รายใบกำกับ และเลขที่ขาดหาย
 */
class TaxController extends Controller
{
    public function __construct(protected SalesTaxReportService $report) {}

    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);
        $branchIds = $this->branchIds($request);

        $daily = $this->report->daily($branchIds, $from, $to);

        return Inertia::render('BackOffice/Tax/Index', [
            'rows' => $daily,
            'totals' => $this->report->totals($daily),
            'invoices' => $this->report->invoices($branchIds, $from, $to),
            'gaps' => $this->report->numberGaps($branchIds, $from, $to),
            'filters' => ['from' => $from, 'to' => $to, 'branch_ids' => $branchIds],
        ]);
    }

    /**
     * ดาวน์โหลดรายงานภาษีขายรายใบกำกับ
     *
     * ── ทำไมเป็น CSV ไม่ใช่ xlsx ───────────────────────────
     * โปรแกรมบัญชีและ Excel เปิด CSV ได้หมด และไม่ต้องลง library เพิ่ม
     * ซึ่งแปลว่าไม่มีอะไรให้พังตอนย้ายเครื่อง
     *
     * ── ทำไมต้องมี BOM ────────────────────────────────────
     * Excel บนวินโดวส์เดาว่าไฟล์เป็น TIS-620 ถ้าไม่มี BOM
     * ภาษาไทยจะกลายเป็นตัวขยะทั้งไฟล์ ซึ่งเป็นปัญหาที่เจอทุกครั้งที่ลืม
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);
        $branchIds = $this->branchIds($request);

        $invoices = $this->report->invoices($branchIds, $from, $to);
        $filename = "sales-tax-{$from}-to-{$to}.csv";

        return response()->streamDownload(function () use ($invoices) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'วันที่', 'เลขที่ใบกำกับ', 'เลขที่บิล', 'สาขา', 'ชื่อผู้ซื้อ',
                'มูลค่าก่อนภาษี', 'ภาษีมูลค่าเพิ่ม', 'ปัดเศษ', 'รวมทั้งสิ้น', 'สถานะ',
            ]);

            foreach ($invoices as $row) {
                fputcsv($out, [
                    $row['business_date'],
                    $row['receipt_no'],
                    $row['order_no'],
                    $row['branch'],
                    $row['customer'],
                    number_format($row['net_amount'], 2, '.', ''),
                    number_format($row['tax_amount'], 2, '.', ''),
                    number_format($row['rounding'], 2, '.', ''),
                    number_format($row['total_amount'], 2, '.', ''),
                    $row['status_label'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
