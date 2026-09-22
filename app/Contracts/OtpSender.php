<?php

namespace App\Contracts;

/**
 * ช่องทางส่งรหัส OTP ให้ลูกค้า
 *
 * ระบบนี้ยังไม่ได้ต่อผู้ให้บริการ SMS จริง
 * ถ้าจะต่อ ให้สร้าง class ที่ implement interface นี้ แล้วผูกใน AppServiceProvider
 */
interface OtpSender
{
    public function send(string $phone, string $code, int $ttlMinutes): void;

    /** ชื่อช่องทางที่ใช้อยู่ — เอาไว้แสดงให้ผู้ดูแลระบบรู้ว่ากำลังใช้อะไร */
    public function name(): string;
}
