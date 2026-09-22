<?php

namespace App\Support\Otp;

use App\Contracts\OtpSender;
use Illuminate\Support\Facades\Log;

/**
 * เขียนรหัส OTP ลง log แทนการส่ง SMS
 *
 * ใช้ตอนพัฒนาและตอนยังไม่ได้ซื้อบริการ SMS
 * ลูกค้าจะไม่ได้รับอะไรเลย — ต้องไปเปิด storage/logs/laravel.log เอา
 */
class LogOtpSender implements OtpSender
{
    public function send(string $phone, string $code, int $ttlMinutes): void
    {
        Log::info('[FoodPOS OTP] ยังไม่ได้ต่อบริการ SMS — รหัสถูกเขียนลง log เท่านั้น', [
            'phone' => $phone,
            'code' => $code,
            'expires_in_minutes' => $ttlMinutes,
        ]);
    }

    public function name(): string
    {
        return 'log (ยังไม่ได้ต่อ SMS จริง)';
    }
}
