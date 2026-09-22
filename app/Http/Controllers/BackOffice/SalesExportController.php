<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\ExportStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesExport;
use App\Services\SalesExportService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ส่งข้อมูลการขายให้ระบบบัญชี — หน้าติดตามฝั่งหลังบ้าน
 *
 * ── หน้านี้มีไว้ตอบสามคำถาม ─────────────────────────────────
 *   วันไหนยังไม่ได้ส่ง · วันไหนส่งไม่สำเร็จ · วันไหนส่งแล้วแต่ข้อมูลเปลี่ยนทีหลัง
 * ปกติตัวตั้งเวลาจะเรียก `php artisan sales:export` ให้ทุกเช้าอยู่แล้ว
 * ปุ่มบนหน้านี้มีไว้สำหรับวันที่พลาด หรือวันที่ต้องส่งใหม่หลังแก้บิลย้อนหลัง
 */
class SalesExportController extends Controller
{
    public function __construct(protected SalesExportService $exports) {}

    public function index(Request $request): Response
    {
        $branch = CurrentBranch::getOrFail();
        [$from, $to] = $this->range($request, $branch);

        $rows = SalesExport::with('createdBy:id,name')
            ->where('branch_id', $branch->id)
            ->whereBetween('business_date', [$from, $to])
            ->orderByDesc('business_date')
            ->get();

        $byDate = $rows->keyBy(fn (SalesExport $e) => $e->business_date->toDateString());

        return Inertia::render('BackOffice/SalesExport/Index', [
            'rows' => $rows->map(fn (SalesExport $e) => [
                'id' => $e->id,
                'business_date' => $e->business_date->toDateString(),
                'status' => $e->status->value,
                'status_label' => $e->status->label(),
                'driver' => $e->driver,
                'format' => $e->format,
                'detail' => $e->detail,
                'bill_count' => (int) $e->bill_count,
                'grand_total' => (float) $e->grand_total,
                'attempts' => (int) $e->attempts,
                'reference' => $e->reference,
                'files' => $e->files(),
                'last_error' => $e->last_error,
                'sent_at' => $e->sent_at?->toIso8601String(),
                'created_by' => $e->createdBy?->name,
                // ส่งไปแล้วแต่มีคนแก้บิลของวันนั้นทีหลัง — ต้องส่งใหม่
                'is_stale' => $this->exports->isStale($e, $branch),
            ])->values(),
            // วันที่มีการขายแต่ยังไม่มีแถวส่งเลย — อันตรายกว่าวันที่ส่งแล้วล้มเหลว
            'missing' => $this->missingDays($branch, $from, $to, $byDate->keys()->all()),
            'config' => [
                'driver' => (string) config('pos.export.driver'),
                'detail' => (string) config('pos.export.detail'),
                'format' => (string) config('pos.export.file.format'),
                'disk' => (string) config('pos.export.file.disk'),
                'path' => (string) config('pos.export.file.path'),
            ],
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /** ส่งข้อมูลของวันที่เลือก — ใช้กับวันที่พลาด หรือวันที่ต้องส่งใหม่ */
    public function store(Request $request): RedirectResponse
    {
        $branch = CurrentBranch::getOrFail();

        $data = $request->validate([
            'business_date' => ['required', 'date_format:Y-m-d'],
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            $export = $this->exports->run(
                $branch,
                $data['business_date'],
                $request->user(),
                (bool) ($data['force'] ?? false),
            );
        } catch (\RuntimeException $e) {
            // ตั้งค่า driver ผิด — เป็นเรื่องของ config ไม่ใช่ของข้อมูล จึงไม่บันทึกเป็นการส่งที่ล้มเหลว
            return back()->withErrors(['business_date' => $e->getMessage()]);
        }

        return $export->status === ExportStatus::Failed
            ? back()->withErrors(['business_date' => 'ส่งไม่สำเร็จ: '.$export->last_error])
            : back()->with('success', 'ส่งข้อมูลวันที่ '.$data['business_date'].' แล้ว');
    }

    /**
     * ดาวน์โหลดไฟล์ที่ส่งออกไป
     *
     * รับเฉพาะเส้นทางที่อยู่ในรายการไฟล์ของแถวนี้ ไม่ใช่เส้นทางอะไรก็ได้จาก query string
     * ไม่งั้นหน้านี้จะกลายเป็นช่องอ่านไฟล์ทั้งดิสก์
     */
    public function download(Request $request, SalesExport $export)
    {
        abort_unless($export->branch_id === CurrentBranch::id(), 403);

        $files = $export->files();
        $path = (string) $request->query('path', $files[0] ?? '');

        abort_unless(in_array($path, $files, true), 404);

        $disk = Storage::disk((string) config('pos.export.file.disk', 'local'));

        abort_unless($disk->exists($path), 404);

        return $disk->download($path);
    }

    /**
     * วันที่มีบิลปิดแล้วแต่ยังไม่มีแถวส่งเลย
     *
     * @param  array<int, string>  $known
     * @return array<int, array{business_date: string, bill_count: int}>
     */
    protected function missingDays(Branch $branch, string $from, string $to, array $known): array
    {
        $rows = DB::table('orders')
            ->where('branch_id', $branch->id)
            ->whereBetween('business_date', [$from, $to])
            ->whereIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::Void->value,
                OrderStatus::Refunded->value,
            ])
            // ไม่มี join จึงอ้างชื่อคอลัมน์เปล่า ๆ ได้ ไม่ต้องกังวลเรื่อง prefix
            ->selectRaw('business_date, COUNT(*) AS bill_count')
            ->groupBy('business_date')
            ->orderByDesc('business_date')
            ->get();

        $known = array_flip($known);
        $out = [];

        foreach ($rows as $row) {
            $date = substr((string) $row->business_date, 0, 10);

            if (! isset($known[$date])) {
                $out[] = ['business_date' => $date, 'bill_count' => (int) $row->bill_count];
            }
        }

        return $out;
    }

    /**
     * ช่วงวันขายที่ดูอยู่ — ตั้งต้น 30 วันล่าสุด
     *
     * @return array{0: string, 1: string}
     */
    protected function range(Request $request, Branch $branch): array
    {
        $today = $branch->businessDateFor();

        $from = $request->date('from')?->toDateString() ?? $today->copy()->subDays(30)->toDateString();
        $to = $request->date('to')?->toDateString() ?? $today->toDateString();

        return $from <= $to ? [$from, $to] : [$to, $from];
    }
}
