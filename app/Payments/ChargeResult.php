<?php

namespace App\Payments;

use Illuminate\Support\Carbon;

/**
 * ผลของการออก QR หนึ่งใบ
 *
 * เป็น readonly เพราะเป็นคำตอบของเกตเวย์ ไม่ใช่สถานะที่แก้ไปเรื่อย ๆ
 * ถ้าปล่อยให้แก้ได้ จะมีคนแก้ยอดตรงนี้แล้วยอดที่ลูกค้าสแกนกับที่บันทึกไม่ตรงกัน
 */
final readonly class ChargeResult
{
    /**
     * @param  string|null  $providerChargeId  id ฝั่งเกตเวย์ — null ได้เฉพาะ driver ที่ไม่มีเกตเวย์
     * @param  string  $qrPayload  ข้อความ EMVCo ที่เอาไปวาดเป็น QR
     * @param  array<string, mixed>  $raw  คำตอบดิบ ไว้เถียงกับเกตเวย์ทีหลัง
     */
    public function __construct(
        public ?string $providerChargeId,
        public string $qrPayload,
        public ?Carbon $expiresAt = null,
        public array $raw = [],
    ) {}
}
