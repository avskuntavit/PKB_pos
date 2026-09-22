<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\PaymentMethod;
use App\Enums\ReconcileStatus;
use App\Http\Controllers\Controller;
use App\Models\BankReconciliation;
use App\Models\Branch;
use App\Services\BankReconciliationService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * กระทบยอดเงินกับธนาคาร
 *
 * ── ทำไมช่วงวันที่ตั้งต้นเป็นทั้งเดือน ไม่ใช่วันนี้ ─────────
 * งานนี้ไม่มีใครทำรายวัน คนทำเปิดหน้านี้ตอนปิดเดือนพร้อมสเตทเมนต์ในมือ
 * ถ้าเปิดมาเห็นแค่วันนี้ ทุกคนต้องกดเปลี่ยนช่วงวันที่ก่อนเสมอ
 *
 * ── ทำไมล็อกสาขาเดียว ─────────────────────────────────────
 * การติ๊กว่าเงินเข้าแล้วผูกกับบัญชีธนาคารของสาขานั้น
 * ถ้าดูรวมหลายสาขาได้ คนกดจะติ๊กข้ามสาขาโดยไม่รู้ตัว
 */
class BankReconciliationController extends Controller
{
    public function __construct(protected BankReconciliationService $reconciliation) {}

    public function index(Request $request): Response
    {
        $branch = CurrentBranch::getOrFail();
        [$from, $to] = $this->range($request, $branch);

        $days = $this->reconciliation->daily($branch, $from, $to);

        return Inertia::render('BackOffice/BankReconciliation/Index', [
            'days' => $days,
            'channel_totals' => $this->reconciliation->channelTotals($days),
            'totals' => $this->reconciliation->totals($days),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /** ติ๊กว่ากระทบแล้ว — ยอดจริงกรอกเฉพาะตอนที่สเตทเมนต์ไม่ตรงกับที่ระบบคิด */
    public function reconcile(Request $request): RedirectResponse
    {
        $branch = CurrentBranch::getOrFail();

        $data = $request->validate([
            'business_date' => ['required', 'date_format:Y-m-d'],
            'channel' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'actual_amount' => ['nullable', 'numeric', 'between:-9999999999.99,9999999999.99'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'channel.in' => 'ช่องทางรับเงินไม่ถูกต้อง',
        ]);

        $record = $this->reconciliation->reconcile(
            $branch,
            $data['business_date'],
            $data['channel'],
            isset($data['actual_amount']) ? (float) $data['actual_amount'] : null,
            $data['reference'] ?? null,
            $data['note'] ?? null,
            $request->user(),
        );

        return back()->with(
            'success',
            $record->status === ReconcileStatus::Matched
                ? 'กระทบยอดแล้ว ตรงกับที่ระบบคำนวณ'
                : 'บันทึกแล้ว — ยอดไม่ตรง ต่างอยู่ '.number_format((float) $record->diff_amount, 2).' บาท',
        );
    }

    /** ยกเลิกการติ๊ก — กดผิดวันหรือผิดช่องทางต้องแก้ได้ */
    public function unreconcile(Request $request, BankReconciliation $reconciliation): RedirectResponse
    {
        abort_unless($reconciliation->branch_id === CurrentBranch::id(), 403);

        $this->reconciliation->unreconcile($reconciliation, $request->user());

        return back()->with('success', 'ยกเลิกการกระทบยอดแล้ว');
    }

    /**
     * ดาวน์โหลดตารางกระทบยอด — หนึ่งบรรทัดต่อวันต่อช่องทาง
     *
     * BOM ข้างหน้าเพราะ Excel บนวินโดวส์เดาว่าไฟล์เป็น TIS-620 ถ้าไม่มี
     * แล้วภาษาไทยจะกลายเป็นตัวขยะทั้งไฟล์
     */
    public function export(Request $request): StreamedResponse
    {
        $branch = CurrentBranch::getOrFail();
        [$from, $to] = $this->range($request, $branch);

        $days = $this->reconciliation->daily($branch, $from, $to);
        $filename = "bank-reconciliation-{$from}-to-{$to}.csv";

        return response()->streamDownload(function () use ($days) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'วันขาย', 'ช่องทาง', 'ยอดรับจากลูกค้า', 'ค่าธรรมเนียม', 'คืนเงิน',
                'ควรเข้าบัญชี', 'เข้าบัญชีจริง', 'ส่วนต่าง', 'สถานะ', 'เลขอ้างอิง', 'ผู้กระทบ', 'หมายเหตุ',
            ]);

            foreach ($days as $day) {
                foreach ($day['channels'] as $row) {
                    fputcsv($out, [
                        $day['business_date'],
                        $row['label'],
                        number_format($row['gross'], 2, '.', ''),
                        number_format($row['fee'], 2, '.', ''),
                        number_format($row['refund'], 2, '.', ''),
                        number_format($row['expected'], 2, '.', ''),
                        $row['actual'] === null ? '' : number_format($row['actual'], 2, '.', ''),
                        number_format($row['diff'], 2, '.', ''),
                        $row['status_label'],
                        $row['reference'],
                        $row['reconciled_by'],
                        $row['note'],
                    ]);
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * ช่วงวันขายที่ดูอยู่ — ตั้งต้นเป็นเดือนปัจจุบันถึงวันนี้
     *
     * @return array{0: string, 1: string}
     */
    protected function range(Request $request, Branch $branch): array
    {
        $today = $branch->businessDateFor();

        $from = $request->date('from')?->toDateString() ?? $today->copy()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? $today->toDateString();

        return $from <= $to ? [$from, $to] : [$to, $from];
    }
}
