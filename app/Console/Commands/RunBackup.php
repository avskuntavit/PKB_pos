<?php

namespace App\Console\Commands;

use App\Enums\BackupKind;
use App\Models\BackupRun;
use App\Services\BackupService;
use App\Services\ErrorMonitor;
use App\Services\SystemHealthService;
use Illuminate\Console\Command;

/**
 * สำรองข้อมูล
 *
 *   php artisan backup:run                  สำรองทุกอย่างที่เปิดใช้งานไว้
 *   php artisan backup:run --only=database  เฉพาะฐานข้อมูล
 *   php artisan backup:run --only=uploads   เฉพาะรูปและสลิป
 *
 * ตัวตั้งเวลาเรียกตัวนี้คืนละครั้ง (ดู routes/console.php)
 * กดจากหน้า /backoffice/health ก็เรียกโค้ดชุดเดียวกันนี้
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run
        {--only= : สำรองเฉพาะชนิดที่ระบุ — database | uploads | exports}';

    protected $description = 'สำรองฐานข้อมูลและไฟล์ แล้วบันทึกผลไว้ให้ตรวจย้อนหลังได้';

    public function handle(BackupService $backups, ErrorMonitor $errors, SystemHealthService $health): int
    {
        if (! config('monitoring.backup.enabled')) {
            $this->warn('ปิดการสำรองข้อมูลไว้ที่ BACKUP_ENABLED=false — ไม่ได้ทำอะไร');

            return self::SUCCESS;
        }

        $kinds = null;

        if ($only = $this->option('only')) {
            $kind = BackupKind::tryFrom((string) $only);

            if (! $kind) {
                $this->error('ไม่รู้จักชนิด "'.$only.'" — ใช้ได้: database, uploads, exports');

                return self::FAILURE;
            }

            $kinds = [$kind];
        }

        $runs = $backups->run($kinds);

        $rows = [];
        $failed = 0;

        foreach ($runs as $run) {
            if ($run->status === BackupRun::FAILED) {
                $failed++;
            }

            $rows[] = [
                $run->kind->label(),
                $run->slot,
                match ($run->status) {
                    BackupRun::SUCCESS => 'สำเร็จ'.($run->verified_at ? ' (ตรวจไฟล์แล้ว)' : ''),
                    BackupRun::SKIPPED => 'ข้าม: '.$run->error,
                    default => 'ล้มเหลว: '.$run->error,
                },
                $run->sizeLabel(),
                $run->durationLabel(),
            ];
        }

        $this->table(['ชนิด', 'ช่อง', 'ผล', 'ขนาด', 'ใช้เวลา'], $rows);

        // เก็บกวาดเคส error เก่าที่ปิดไปแล้ว — ทำตรงนี้เพราะเป็นงานประจำคืนเหมือนกัน
        if ($pruned = $errors->prune()) {
            $this->line("ลบบันทึก error ที่ปิดแล้วและเก่ากว่ากำหนด {$pruned} รายการ");
        }

        $health->forgetAlerts();

        if ($failed) {
            $this->error("สำรองไม่สำเร็จ {$failed} รายการ — ดูรายละเอียดที่ /backoffice/health");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
