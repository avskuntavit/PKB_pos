<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveCurrentBranch;
use App\Services\ErrorMonitor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            ResolveCurrentBranch::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // ลูกค้ากับพนักงานใช้หน้าล็อกอินคนละหน้า ส่งกลับให้ถูกที่
        $middleware->redirectGuestsTo(function ($request) {
            return $request->is('order', 'order/*') ? '/order/login' : '/login';
        });

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'permission' => \App\Http\Middleware\EnsureUserHasPermission::class,
            // ใส่ให้เฉพาะ endpoint ที่ทำซ้ำแล้วเสียหาย ไม่ใส่ทั้งเว็บ
            'idempotent' => \App\Http\Middleware\IdempotentRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
        | เก็บ error ของระบบลงตาราง error_events เพื่อให้มีหน้าให้ดูย้อนหลังได้
        |
        | ── ทำไมไม่ return false ──────────────────────────────────────────
        | คืนค่าอะไรก็ตามที่ไม่ใช่ false แปลว่า "บันทึกของฉันเสร็จแล้ว ทำต่อตามเดิมด้วย"
        | log ไฟล์จึงยังเขียนครบเหมือนเดิม ตารางนี้เป็นของเพิ่ม ไม่ใช่ของแทน
        |
        | ErrorMonitor กรองพวก 4xx (กรอกฟอร์มไม่ครบ / ไม่มีสิทธิ์ / หน้าไม่มี) ออกเอง
        | และห้ามโยน exception ออกมาไม่ว่ากรณีใด — ดูคำอธิบายในคลาสนั้น
        */
        $exceptions->report(function (Throwable $e) {
            app(ErrorMonitor::class)->record($e);
        });
    })->create();
