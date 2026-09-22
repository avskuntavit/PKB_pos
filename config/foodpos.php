<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OTP สำหรับล็อกอินลูกค้า
    |--------------------------------------------------------------------------
    |
    | driver = log   -> เขียนรหัสลง storage/logs (ใช้ตอน dev / ยังไม่ได้ต่อ SMS)
    | driver = null  -> ไม่ส่งอะไรเลย
    |
    | ถ้าจะต่อผู้ให้บริการ SMS จริง ให้สร้าง class ที่ implement
    | App\Contracts\OtpSender แล้วผูกใน AppServiceProvider
    |
    */
    'otp' => [
        'driver' => env('FOODPOS_OTP_DRIVER', 'log'),
        'length' => 6,
        'ttl_minutes' => env('FOODPOS_OTP_TTL', 5),
        'max_attempts' => 5,          // กรอกผิดได้กี่ครั้งต่อ 1 รหัส
        'max_per_hour' => 5,          // ขอรหัสใหม่ได้กี่ครั้งต่อเบอร์ต่อชั่วโมง
        'resend_cooldown_seconds' => 60,

        // โหมด dev: ส่งรหัสกลับไปแสดงบนหน้าจอเลย จะได้ทดสอบได้โดยไม่ต้องมี SMS
        // ห้ามเปิดบน production
        'expose_in_response' => env('FOODPOS_OTP_EXPOSE', false),
    ],

    'queue_number' => [
        'reset_daily' => true,
        'start_at' => 1,
    ],
];
