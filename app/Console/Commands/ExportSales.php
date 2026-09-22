<?php

namespace App\Console\Commands;

use App\Enums\ExportStatus;
use App\Models\Branch;
use App\Services\SalesExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ส่งข้อมูลการขายให้ระบบบัญชี
 *
 * ── ทำไมค่าตั้งต้นเป็น "เมื่อวาน" ไม่ใช่ "วันนี้" ─────────────
 * วันนี้ยังขายไม่จบ ส่งไปแล้วต้องส่งใหม่ทุกครั้งที่มีบิลเพิ่ม
 * คำสั่งนี้ออกแบบมาให้ตัวตั้งเวลาเรียกตอนเช้า แล้วส่งของวันที่ปิดไปแล้ว
 *
 *   php artisan sales:export                       ส่งของเมื่อวาน ทุกสาขา
 *   php artisan sales:export --date=2026-09-21     ส่งของวันที่ระบุ
 *   php artisan sales:export --from=... --to=...   ส่งย้อนหลังเป็นช่วง
 *   php artisan sales:export --dry-run             ดูว่าจะส่งอะไร โดยไม่ส่งจริง
 */
class ExportSales extends Command
{
    /** กันพิมพ์ช่วงวันที่ผิดแล้วรันข้ามปีโดยไม่ตั้งใจ */
    protected const MAX_DAYS = 400;

    protected $signature = 'sales:export
        {--branch= : รหัสสาขา (code) หรือ id — ไม่ระบุ = ทุกสาขาที่เปิดใช้งาน}
        {--date= : วันขายเดียว เช่น 2026-09-21}
        {--from= : วันขายเริ่มต้น (ใช้คู่กับ --to)}
        {--to= : วันขายสิ้นสุด}
        {--force : ส่งซ้ำแม้ส่งสำเร็จไปแล้วและข้อมูลไม่เปลี่ยน}
        {--dry-run : แสดงสรุปอย่างเดียว ไม่ส่งและไม่บันทึกสถานะ}';

    protected $description = 'ส่งข้อมูลการขายรายวันออกไปให้ระบบบัญชี (SAM)';

    public function handle(SalesExportService $exports): int
    {
        $branches = $this->branches();

        if ($branches->isEmpty()) {
            $this->error('ไม่พบสาขาที่ตรงกับที่ระบุ');

            return self::FAILURE;
        }

        try {
            $driver = $exports->driver();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('ช่องทางส่ง: '.$driver->name().' · ระดับข้อมูล: '.config('pos.export.detail'));

        $rows = [];
        $failed = 0;

        foreach ($branches as $branch) {
            foreach ($this->dates($branch) as $date) {
                if ($this->option('dry-run')) {
                    $payload = $exports->payload($branch, $date);

                    $rows[] = [
                        $branch->code,
                        $date,
                        $payload['summary']['bill_count'],
                        number_format((float) $payload['summary']['grand_total'], 2),
                        'ไม่ได้ส่ง (dry-run)',
                    ];

                    continue;
                }

                $export = $exports->run($branch, $date, null, (bool) $this->option('force'));

                if ($export->status === ExportStatus::Failed) {
                    $failed++;
                }

                $rows[] = [
                    $branch->code,
                    $date,
                    $export->bill_count,
                    number_format((float) $export->grand_total, 2),
                    $export->status === ExportStatus::Failed
                        ? 'ล้มเหลว: '.$export->last_error
                        : $export->status->label().($export->reference ? ' → '.$export->reference : ''),
                ];
            }
        }

        $this->table(['สาขา', 'วันขาย', 'บิล', 'ยอดรวม', 'ผล'], $rows);

        if ($failed) {
            $this->error("ส่งไม่สำเร็จ {$failed} รายการ — ดูรายละเอียดในคอลัมน์ผล หรือหน้า /backoffice/sales-export");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Branch> */
    protected function branches(): Collection
    {
        $query = Branch::query()->orderBy('code');

        if ($value = $this->option('branch')) {
            // รับได้ทั้งรหัสสาขาและ id เพราะคนเรียกมือกับสคริปต์ถนัดคนละแบบ
            $query->where(fn ($q) => $q->where('code', $value)->orWhere('id', (int) $value));
        } else {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * วันขายที่จะส่ง
     *
     * @return array<int, string>
     */
    protected function dates(Branch $branch): array
    {
        if ($date = $this->option('date')) {
            return [Carbon::parse($date)->toDateString()];
        }

        $from = $this->option('from');
        $to = $this->option('to');

        if ($from || $to) {
            $start = Carbon::parse($from ?: $to)->startOfDay();
            $end = Carbon::parse($to ?: $from)->startOfDay();

            if ($start->gt($end)) {
                [$start, $end] = [$end, $start];
            }

            $days = min(self::MAX_DAYS, (int) $start->diffInDays($end) + 1);
            $dates = [];

            for ($i = 0; $i < $days; $i++) {
                $dates[] = $start->copy()->addDays($i)->toDateString();
            }

            return $dates;
        }

        // วันขายล่าสุดที่ปิดไปแล้ว — ตัดรอบตาม business_day_start ของสาขา
        return [$branch->businessDateFor()->subDay()->toDateString()];
    }
}
