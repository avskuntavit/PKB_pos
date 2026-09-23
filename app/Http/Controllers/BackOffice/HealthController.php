<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\BackupKind;
use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Models\ErrorEvent;
use App\Services\BackupService;
use App\Services\SystemHealthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * สุขภาพระบบ — สำรองข้อมูล ข้อผิดพลาด และค่าตั้งค่าที่อันตราย
 *
 * หน้านี้ตอบคำถามเดียว: "ตอนนี้มีอะไรที่ต้องไปแก้ไหม"
 * เป็นหน้าของทั้งระบบ ไม่ใช่ของสาขา จึงไม่มีตัวกรองสาขาและให้เฉพาะเจ้าของระบบเข้า
 */
class HealthController extends Controller
{
    /** จำนวนบันทึกการสำรองที่แสดงย้อนหลัง — พอเห็นหนึ่งสัปดาห์กว่า ๆ */
    protected const RECENT_RUNS = 20;

    public function index(Request $request, BackupService $backups, SystemHealthService $health): Response
    {
        $showAll = $request->input('errors') === 'all';

        return Inertia::render('BackOffice/Health/Index', [
            'backups' => $backups->summary(),
            'warnings' => $health->configWarnings(),

            'runs' => BackupRun::with('triggeredBy:id,name')
                ->orderByDesc('id')
                ->limit(self::RECENT_RUNS)
                ->get()
                ->map(fn (BackupRun $run) => [
                    'id' => $run->id,
                    'kind' => $run->kind->value,
                    'kind_label' => $run->kind->label(),
                    'slot' => $run->slot,
                    'status' => $run->status,
                    'size' => $run->sizeLabel(),
                    'duration' => $run->durationLabel(),
                    'verified' => (bool) $run->verified_at,
                    'path' => $run->path,
                    'error' => $run->error,
                    'finished_at' => $run->finished_at?->toIso8601String(),
                    'by' => $run->triggeredBy?->name,
                ]),

            'errors' => ErrorEvent::query()
                ->when(! $showAll, fn ($q) => $q->open())
                ->orderByDesc('last_seen_at')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (ErrorEvent $event) => [
                    'id' => $event->id,
                    'level' => $event->level,
                    'class' => $event->shortClass(),
                    'message' => $event->message,
                    'location' => $event->location(),
                    'url' => $event->url,
                    'method' => $event->method,
                    'occurrences' => $event->occurrences,
                    'first_seen_at' => $event->first_seen_at?->toIso8601String(),
                    'last_seen_at' => $event->last_seen_at?->toIso8601String(),
                    'resolved_at' => $event->resolved_at?->toIso8601String(),
                    'trace' => $event->trace,
                ]),

            'openErrorCount' => ErrorEvent::open()->count(),
            'filters' => ['errors' => $showAll ? 'all' : 'open'],

            'scheduler' => [
                'last_ping_at' => $health->schedulerLastPing()?->toIso8601String(),
                'is_down' => $health->schedulerIsDown(),
                'stale_minutes' => (int) config('monitoring.scheduler.stale_minutes'),
            ],

            'settings' => [
                'enabled' => (bool) config('monitoring.backup.enabled'),
                'time' => (string) config('monitoring.backup.time'),
                'sql_path' => (string) config('monitoring.backup.sql_path'),
                'files_path' => (string) config('monitoring.backup.files_path'),
                'verify' => (bool) config('monitoring.backup.verify'),
                'stale_hours' => (int) config('monitoring.backup.stale_hours'),
                'kinds' => BackupKind::options(),
            ],
        ]);
    }

    /**
     * กดสำรองเดี๋ยวนี้
     *
     * รันตรง ๆ ในคำขอนี้เลย ไม่ผ่านคิว เพราะระบบนี้ไม่มีตัวรันคิวเดินอยู่
     * (supervisor มีแค่ php-fpm, nginx และ schedule:work) งานที่โยนเข้าคิว
     * จะนอนอยู่ในตารางเงียบ ๆ ตลอดไป ซึ่งแย่กว่าการรอหน้าจอสักครู่
     */
    public function backup(Request $request, BackupService $backups, SystemHealthService $health): RedirectResponse
    {
        $data = $request->validate([
            'only' => ['nullable', 'string', 'in:database,uploads,exports'],
        ]);

        $kinds = isset($data['only']) ? [BackupKind::from($data['only'])] : null;

        // ฐานข้อมูลใหญ่ ๆ ใช้เวลาเป็นนาที — ตัวจับเวลาของ PHP ต้องไม่มาตัดกลางคัน
        @set_time_limit(0);

        $runs = $backups->run($kinds, $request->user()?->getKey());
        $health->forgetAlerts();

        $failed = array_filter($runs, fn (BackupRun $run) => $run->status === BackupRun::FAILED);

        if ($failed !== []) {
            $first = array_values($failed)[0];

            return back()->with('error', 'สำรองไม่สำเร็จ ('.$first->kind->label().'): '.$first->error);
        }

        $done = implode(' · ', array_map(fn (BackupRun $run) => $run->kind->label(), $runs));

        return back()->with('success', 'สำรองเรียบร้อย — '.$done);
    }

    /** ปิดเคส — ถ้าบั๊กตัวเดิมกลับมาเกิดอีก ระบบจะเปิดกลับมาเองอัตโนมัติ */
    public function resolveError(Request $request, ErrorEvent $event, SystemHealthService $health): RedirectResponse
    {
        $event->update([
            'resolved_at' => Carbon::now(),
            'resolved_by' => $request->user()?->getKey(),
        ]);

        $health->forgetAlerts();

        return back()->with('success', 'ปิดเคสแล้ว — ถ้ายังเกิดซ้ำระบบจะเปิดกลับมาให้เอง');
    }

    public function reopenError(ErrorEvent $event, SystemHealthService $health): RedirectResponse
    {
        $event->update(['resolved_at' => null, 'resolved_by' => null]);

        $health->forgetAlerts();

        return back()->with('success', 'เปิดเคสกลับมาแล้ว');
    }
}
