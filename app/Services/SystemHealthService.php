<?php

namespace App\Services;

use App\Models\ErrorEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * สรุปว่า "ตอนนี้ระบบมีอะไรต้องดูไหม"
 *
 * มีผู้เรียกสองที่ที่ต่างกันมาก:
 *   - แถบเตือนบนหลังบ้าน เรียกทุกครั้งที่โหลดหน้า จึงต้องเบาและแคชไว้
 *   - หน้า /backoffice/health เรียกครั้งเดียว ต้องการรายละเอียดครบ
 */
class SystemHealthService
{
    public const ALERT_CACHE_KEY = 'monitoring:alerts';

    /** แคชสั้น ๆ พอให้กดดูแล้วเห็นของใหม่ไว แต่ไม่ยิงคิวรีทุกครั้งที่เปลี่ยนหน้า */
    public const ALERT_TTL = 60;

    public function __construct(protected BackupService $backups) {}

    /**
     * ตัวเลขไม่กี่ตัวสำหรับแถบเตือน
     *
     * @return array{backup_stale: int, open_errors: int, scheduler_down: bool}
     */
    public function alerts(): array
    {
        return Cache::remember(self::ALERT_CACHE_KEY, self::ALERT_TTL, function () {
            $stale = 0;

            foreach ($this->backups->summary() as $row) {
                if ($row['is_stale']) {
                    $stale++;
                }
            }

            return [
                'backup_stale' => $stale,
                'open_errors' => ErrorEvent::open()->count(),
                'scheduler_down' => $this->schedulerIsDown(),
            ];
        });
    }

    /** เรียกหลังจากมีอะไรเปลี่ยน (สำรองเสร็จ / ปิดเคส) ไม่งั้นแถบเตือนจะค้างอีกนาที */
    public function forgetAlerts(): void
    {
        Cache::forget(self::ALERT_CACHE_KEY);
    }

    /*
    |--------------------------------------------------------------------------
    | ตัวตั้งเวลา
    |--------------------------------------------------------------------------
    |
    | ตัวตั้งเวลาตายแล้วหน้าเว็บยังใช้ได้ทุกอย่าง — ความพังชนิดที่ไม่มีใครสังเกต
    | แต่ของที่หยุดตามคือ งานพิมพ์ที่ค้าง การส่งข้อมูลบัญชี และการสำรองข้อมูล
    | มันจึงเคาะเวลาไว้ทุก 5 นาที (ดู routes/console.php) แล้วเราดูว่าเคาะล่าสุดเมื่อไหร่
    */

    public function schedulerLastPing(): ?Carbon
    {
        $raw = Cache::get((string) config('monitoring.scheduler.ping_key'));

        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public function schedulerIsDown(): bool
    {
        $last = $this->schedulerLastPing();

        if (! $last) {
            /*
            | ยังไม่เคยเคาะเลย = เพิ่งติดตั้งหรือเพิ่งล้างแคช ยังไม่ถือว่าตาย
            | รอให้ถึงรอบแรกก่อน ไม่งั้นจะขึ้นเตือนทุกครั้งที่ deploy ใหม่
            */
            return false;
        }

        return abs((float) Carbon::now()->diffInMinutes($last))
            > (int) config('monitoring.scheduler.stale_minutes');
    }

    /*
    |--------------------------------------------------------------------------
    | ค่าตั้งค่าที่อันตรายถ้าปล่อยไว้บนเครื่องจริง
    |--------------------------------------------------------------------------
    |
    | ค่าพวกนี้ทำให้ระบบ "ใช้งานได้" แต่ผิดอยู่เงียบ ๆ จนกว่าจะเกิดเรื่อง
    | เอามาขึ้นหน้าเดียวกับสุขภาพระบบ เพราะเป็นคำถามเดียวกัน: ตอนนี้มีอะไรต้องแก้ไหม
    |
    | @return array<int, array{key: string, label: string, detail: string, level: string}>
    */
    public function configWarnings(): array
    {
        $warnings = [];
        $production = app()->environment('production');

        if ($production && config('app.debug')) {
            $warnings[] = [
                'key' => 'app_debug',
                'label' => 'APP_DEBUG ยังเปิดอยู่บนเครื่องจริง',
                'detail' => 'หน้า error จะแสดงค่า env ทั้งหมดรวมถึง APP_KEY และรหัสฐานข้อมูลให้คนที่เปิดเจอ',
                'level' => 'danger',
            ];
        }

        $url = (string) config('app.url');

        if ($production && (str_contains($url, 'localhost') || str_contains($url, '127.0.0.1'))) {
            $warnings[] = [
                'key' => 'app_url',
                'label' => 'APP_URL ยังเป็น localhost',
                'detail' => 'QR ที่โต๊ะและลิงก์ที่ระบบสร้างจะชี้กลับมาที่มือถือของลูกค้าเอง ใช้ไม่ได้ทั้งหมด — ต้องเป็น IP หรือชื่อเครื่องในวงแลน',
                'level' => 'danger',
            ];
        }

        if (config('foodpos.otp.expose_in_response')) {
            $warnings[] = [
                'key' => 'otp_expose',
                'label' => 'ระบบกำลังส่งรหัส OTP กลับไปแสดงบนหน้าจอ',
                'detail' => 'ใครก็ขอรหัสของเบอร์คนอื่นแล้วอ่านจากหน้าจอตัวเองได้ = ล็อกอินเป็นลูกค้าคนไหนก็ได้',
                'level' => 'danger',
            ];
        }

        if (! config('monitoring.backup.enabled')) {
            $warnings[] = [
                'key' => 'backup_off',
                'label' => 'ปิดการสำรองข้อมูลอัตโนมัติไว้',
                'detail' => 'ตัวตั้งเวลาจะไม่สำรองให้เลย ต้องกดเองทุกครั้ง',
                'level' => 'danger',
            ];
        }

        if ($this->schedulerIsDown()) {
            $last = $this->schedulerLastPing();

            $warnings[] = [
                'key' => 'scheduler',
                'label' => 'ตัวตั้งเวลาไม่ได้เคาะมาตั้งแต่ '.($last?->diffForHumans() ?? 'ไม่ทราบ'),
                'detail' => 'งานพิมพ์ที่ค้าง การส่งข้อมูลให้บัญชี และการสำรองข้อมูล หยุดทำงานพร้อมกันทั้งหมด — ตรวจ program:schedule ใน supervisor',
                'level' => 'danger',
            ];
        }

        return $warnings;
    }
}
