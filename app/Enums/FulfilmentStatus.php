<?php

namespace App\Enums;

/**
 * สถานะออเดอร์ล่วงหน้าที่ลูกค้าเห็นบนหน้าติดตาม
 *
 * แยกจาก OrderStatus (ซึ่งพูดถึงสถานะทางบัญชีของบิล: เปิด/ปิด/ทำลาย)
 * อันนี้พูดถึง "ของถึงมือลูกค้าหรือยัง"
 */
enum FulfilmentStatus: string
{
    case Placed = 'placed';         // ส่งออเดอร์แล้ว รอร้านกดรับ
    case Accepted = 'accepted';     // ร้านรับออเดอร์แล้ว
    case Preparing = 'preparing';   // กำลังทำ
    case Ready = 'ready';           // พร้อมให้มารับ
    case Completed = 'completed';   // รับของแล้ว
    case Rejected = 'rejected';     // ร้านปฏิเสธ
    case Cancelled = 'cancelled';   // ลูกค้ายกเลิกก่อนร้านรับ

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'ส่งออเดอร์แล้ว',
            self::Accepted => 'ร้านรับออเดอร์แล้ว',
            self::Preparing => 'กำลังปรุงอาหาร',
            self::Ready => 'พร้อมให้มารับ',
            self::Completed => 'รับของเรียบร้อย',
            self::Rejected => 'ร้านปฏิเสธออเดอร์',
            self::Cancelled => 'ยกเลิกแล้ว',
        };
    }

    /** ข้อความบรรทัดรองบนหน้าติดตาม */
    public function hint(): string
    {
        return match ($this) {
            self::Placed => 'รอร้านตอบรับสักครู่',
            self::Accepted => 'ร้านกำลังจัดคิวให้',
            self::Preparing => 'อีกสักครู่จะเสร็จแล้ว',
            self::Ready => 'มารับได้เลยที่เคาน์เตอร์',
            self::Completed => 'ขอบคุณที่ใช้บริการ',
            self::Rejected => 'ดูเหตุผลจากร้านด้านล่าง',
            self::Cancelled => 'ออเดอร์นี้ถูกยกเลิกแล้ว',
        };
    }

    /** ขั้นตอนที่โชว์เป็น stepper (ไม่รวมสถานะที่จบแบบไม่สำเร็จ) */
    public static function steps(): array
    {
        return [self::Placed, self::Accepted, self::Preparing, self::Ready, self::Completed];
    }

    public function stepIndex(): int
    {
        return array_search($this, self::steps(), true) ?: 0;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    /** ลูกค้ายังยกเลิกเองได้อยู่ไหม — ยกเลิกได้ก่อนร้านกดรับเท่านั้น */
    public function isCancellableByCustomer(): bool
    {
        return $this === self::Placed;
    }
}
