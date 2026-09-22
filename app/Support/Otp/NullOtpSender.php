<?php

namespace App\Support\Otp;

use App\Contracts\OtpSender;

/** ไม่ส่งอะไรเลย — ใช้ตอนรันเทส */
class NullOtpSender implements OtpSender
{
    public function send(string $phone, string $code, int $ttlMinutes): void
    {
        //
    }

    public function name(): string
    {
        return 'null';
    }
}
