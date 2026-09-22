<?php

namespace App\Providers;

use App\Contracts\OtpSender;
use App\Support\Otp\LogOtpSender;
use App\Support\Otp\NullOtpSender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ช่องทางส่ง OTP — ยังไม่ได้ต่อผู้ให้บริการ SMS จริง
        // ถ้าจะต่อ: สร้าง class ที่ implement OtpSender แล้วเปลี่ยน binding ตรงนี้
        $this->app->bind(OtpSender::class, function () {
            return match (config('foodpos.otp.driver')) {
                'null' => new NullOtpSender,
                default => new LogOtpSender,
            };
        });
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // จับ mass assignment ที่หลุดตั้งแต่ตอน dev
        // (ถ้าอยากจับ N+1 ด้วย เปิด Model::preventLazyLoading() เพิ่มได้ แต่ต้อง eager load ให้ครบก่อน)
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal());
    }
}
