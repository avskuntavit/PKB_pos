<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\PrintGroup;
use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\ActivityLogger;
use App\Services\PrintService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * เครื่องพิมพ์และคิวงานพิมพ์ของสาขา
 *
 * หน้านี้มีสองเรื่องอยู่ด้วยกันโดยตั้งใจ — เวลากระดาษไม่ออก คนจะมาที่นี่
 * แล้วอยากเห็นพร้อมกันว่า "ตั้งค่าไว้ยังไง" กับ "ใบไหนค้าง เพราะอะไร"
 * ถ้าแยกสองหน้า ต้องสลับไปมาตอนที่กำลังรีบที่สุด
 */
class PrinterController extends Controller
{
    /** จำนวนงานพิมพ์ล่าสุดที่โชว์ — พอให้เห็นปัญหาโดยไม่ต้องแบ่งหน้า */
    public const RECENT_JOBS = 40;

    public function index(): Response
    {
        $branchId = CurrentBranch::id();

        return Inertia::render('BackOffice/Printers/Index', [
            'printers' => Printer::where('branch_id', $branchId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (Printer $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'host' => $p->host,
                    'port' => $p->port,
                    'columns' => $p->columns,
                    'print_groups' => array_map('intval', $p->print_groups ?? []),
                    'prints_receipt' => $p->prints_receipt,
                    'opens_cash_drawer' => $p->opens_cash_drawer,
                    'copies' => $p->copies,
                    'is_active' => $p->is_active,
                    'sort_order' => $p->sort_order,
                    // ตั้งค่าไม่ครบ = ยังยิงงานไปไม่ได้ ต้องบอกให้เห็นในตาราง
                    'is_configured' => $p->isConfigured(),
                ]),

            'jobs' => PrintJob::where('branch_id', $branchId)
                ->with('printer:id,name')
                ->orderByDesc('id')
                ->limit(self::RECENT_JOBS)
                ->get()
                ->map(fn (PrintJob $j) => [
                    'id' => $j->id,
                    'kind' => $j->kind,
                    'kind_label' => $this->kindLabel($j->kind),
                    'title' => $j->title,
                    'printer' => $j->printer?->name,
                    'status' => $j->status,
                    'attempts' => $j->attempts,
                    'max_attempts' => $j->max_attempts,
                    'last_error' => $j->last_error,
                    'printed_at' => $j->printed_at?->format('d/m/Y H:i'),
                    'created_at' => $j->created_at?->format('d/m/Y H:i'),
                    // ลองใหม่ได้เฉพาะใบที่ยอมแพ้ไปแล้ว ใบที่รอคิวอยู่เดี๋ยวมันไปเอง
                    'can_retry' => $j->status === PrintJob::STATUS_FAILED,
                ]),

            'stuck' => PrintJob::where('branch_id', $branchId)
                ->where('status', PrintJob::STATUS_FAILED)
                ->count(),

            'printGroups' => PrintGroup::options(),
        ]);
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $printer = Printer::create($this->validated($request) + ['branch_id' => CurrentBranch::id()]);

        $logger->log('printer.update', $printer, ['mode' => 'create']);

        return back()->with('success', 'เพิ่มเครื่องพิมพ์แล้ว');
    }

    public function update(Request $request, Printer $printer, ActivityLogger $logger): RedirectResponse
    {
        $this->guard($printer);

        $printer->update($this->validated($request));
        $logger->log('printer.update', $printer, ['mode' => 'update']);

        return back()->with('success', 'บันทึกเครื่องพิมพ์แล้ว');
    }

    public function destroy(Printer $printer, ActivityLogger $logger): RedirectResponse
    {
        $this->guard($printer);

        $printer->delete();
        $logger->log('printer.update', $printer, ['mode' => 'delete']);

        return back()->with('success', 'ลบเครื่องพิมพ์แล้ว');
    }

    /**
     * พิมพ์ใบทดสอบ — ตัวเดียวที่บอกได้ว่าตั้งค่าถูกจริงไหม
     *
     * ตอบผลทันทีว่ากระดาษออกหรือไม่ ไม่ใช่แค่ "ส่งเข้าคิวแล้ว" เพราะคนกดปุ่มนี้
     * ยืนอยู่หน้าเครื่องพิมพ์ เขาอยากรู้เดี๋ยวนี้ว่าต้องไปแก้ไอพีหรือไม่
     */
    public function test(Printer $printer, PrintService $printing): RedirectResponse
    {
        $this->guard($printer);

        if (! $printer->isConfigured()) {
            return back()->with('error', 'ยังไม่ได้กรอกหมายเลขไอพีของเครื่องพิมพ์');
        }

        $job = $printing->queueTest($printer);

        return $job->status === PrintJob::STATUS_DONE
            ? back()->with('success', 'ส่งใบทดสอบแล้ว — ถ้าอ่านภาษาไทยได้ครบและแถวจุดเต็มบรรทัดพอดี แปลว่าตั้งค่าถูก')
            : back()->with('error', 'พิมพ์ไม่สำเร็จ: '.$job->last_error);
    }

    /** สั่งพิมพ์ใบที่ค้างใหม่ — รีเซ็ตจำนวนครั้งให้เริ่มนับใหม่ */
    public function retry(PrintJob $job, PrintService $printing): RedirectResponse
    {
        abort_unless($job->branch_id === CurrentBranch::id(), 403);

        $job->forceFill([
            'status' => PrintJob::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => null,
            'last_error' => null,
        ])->save();

        return $printing->attempt($job)
            ? back()->with('success', 'พิมพ์สำเร็จแล้ว')
            : back()->with('error', 'ยังพิมพ์ไม่ได้: '.$job->fresh()->last_error);
    }

    /** ลองพิมพ์ใบที่ค้างทั้งหมดรวดเดียว — ใช้หลังเปลี่ยนกระดาษหรือเสียบสายใหม่ */
    public function retryAll(PrintService $printing): RedirectResponse
    {
        $branchId = CurrentBranch::id();

        PrintJob::where('branch_id', $branchId)
            ->where('status', PrintJob::STATUS_FAILED)
            ->update([
                'status' => PrintJob::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => null,
                'last_error' => null,
            ]);

        $result = $printing->work($branchId, 100);

        return back()->with(
            $result['failed'] ? 'error' : 'success',
            "พิมพ์สำเร็จ {$result['done']} ใบ ยังไม่สำเร็จ {$result['failed']} ใบ",
        );
    }

    /* ---------- ภายใน ---------- */

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // ไอพีเท่านั้น ไม่รับชื่อโฮสต์ — ชื่อโฮสต์ในวงแลนพึ่งพา mDNS ซึ่งวินโดวส์
            // บางเครื่องแปลไม่ออก แล้วจะกลายเป็นพิมพ์ไม่ออกโดยหาสาเหตุไม่เจอ
            'host' => ['required', 'ip'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'columns' => ['required', 'integer', Rule::in([32, 42, 48])],
            'print_groups' => ['array'],
            'print_groups.*' => ['integer', Rule::enum(PrintGroup::class)],
            'prints_receipt' => ['boolean'],
            'opens_cash_drawer' => ['boolean'],
            'copies' => ['required', 'integer', 'between:1,5'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'host.ip' => 'กรอกเป็นหมายเลขไอพี เช่น 192.168.1.50 (ดูได้จากใบ self-test ของเครื่องพิมพ์)',
        ]);

        $data['driver'] = 'escpos_network';
        $data['print_groups'] = array_values(array_unique(array_map('intval', $data['print_groups'] ?? [])));
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    protected function guard(Printer $printer): void
    {
        abort_unless($printer->branch_id === CurrentBranch::id(), 403, 'เครื่องพิมพ์นี้เป็นของสาขาอื่น');
    }

    protected function kindLabel(string $kind): string
    {
        return match ($kind) {
            'kitchen_ticket' => 'ใบสั่งครัว',
            'receipt' => 'ใบเสร็จ',
            'drawer' => 'เปิดลิ้นชัก',
            'test' => 'ใบทดสอบ',
            default => $kind,
        };
    }
}
