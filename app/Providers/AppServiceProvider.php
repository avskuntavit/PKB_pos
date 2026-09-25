<?php

namespace App\Providers;

use App\Contracts\OtpSender;
use App\Support\Otp\LogOtpSender;
use App\Support\Otp\NullOtpSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        if (config('app.env') === 'production' || request()->header('X-Forwarded-Proto') === 'https') {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);

        // จับ mass assignment ที่หลุดตั้งแต่ตอน dev
        // (ถ้าอยากจับ N+1 ด้วย เปิด Model::preventLazyLoading() เพิ่มได้ แต่ต้อง eager load ให้ครบก่อน)
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal());

        $this->registerSelfOrderLimiter();
    }

    /**
     * ตัวจำกัดอัตราของหน้าลูกค้าที่โต๊ะ — นับแยกตาม "รอบการนั่งโต๊ะ" ไม่ใช่ตาม IP
     *
     * ── ทำไมนับตาม IP ไม่ได้ ──────────────────────────────────────────────
     * ทั้งร้านใช้ไวไฟตัวเดียวกัน มือถือทุกเครื่องจึงออกเน็ตด้วย IP เดียวกันหมด
     * พอตะกร้าร่วมทำให้แต่ละเครื่องต้องถามสถานะถี่ขึ้น (เพื่อให้เห็นนาฬิกานับถอยหลัง
     * พร้อมกันทั้งโต๊ะ) โควตาต่อ IP จะหมดเพราะโต๊ะอื่นใช้ไปแล้ว
     * แล้วลูกค้าโต๊ะที่เพิ่งเข้ามาจะกดอะไรไม่ได้เลย ทั้งที่ไม่ได้ทำอะไรผิด
     *
     * session_token อยู่ใน cookie ของแต่ละเครื่องอยู่แล้ว และเปลี่ยนเองไม่ได้
     * (ออกโดยเซิร์ฟเวอร์ตอนสแกน QR) จึงเป็นตัวนับที่ตรงกับ "หนึ่งโต๊ะ" มากที่สุด
     * ถ้าไม่มี token ก็ถอยไปนับตาม IP ตามเดิม
     */
    protected function registerSelfOrderLimiter(): void
    {
        RateLimiter::for('selforder', function (Request $request) {
            $session = $request->session();

            // โต๊ะที่ผูกไว้ตอนสแกน QR — ตัวนี้คือ "หนึ่งโต๊ะ" ที่ตรงที่สุด
            $table = $session->get('storefront.table');

            if (is_array($table) && isset($table['id'])) {
                return Limit::perMinute(300)->by('table:'.$table['id']);
            }

            // เส้นทางเก่า /t/{token} ถือ token ไว้ในคีย์ของตัวเอง
            $token = collect($session->all())
                ->filter(fn ($value, $name) => str_starts_with((string) $name, 'table_session_token.'))
                ->first();

            return Limit::perMinute(300)->by(is_string($token) ? 'ts:'.$token : 'ip:'.$request->ip());
        });
    }
}
