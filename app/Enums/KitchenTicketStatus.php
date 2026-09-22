<?php

namespace App\Enums;

enum KitchenTicketStatus: string
{
    case Queued = 'queued';         // เข้าคิวรอทำ
    case Preparing = 'preparing';   // กำลังทำ
    case Ready = 'ready';           // ทำเสร็จ รอเสิร์ฟ
    case Served = 'served';         // เสิร์ฟแล้ว
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'รอทำ',
            self::Preparing => 'กำลังทำ',
            self::Ready => 'รอเสิร์ฟ',
            self::Served => 'เสิร์ฟแล้ว',
            self::Cancelled => 'ยกเลิก',
        };
    }

    /** สถานะถัดไปเมื่อกดปุ่มหลักบนหน้าจอครัว */
    public function next(): ?self
    {
        return match ($this) {
            self::Queued => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Served,
            default => null,
        };
    }

    /** ยังค้างอยู่บนหน้าจอครัวหรือไม่ */
    public function isOpen(): bool
    {
        return in_array($this, [self::Queued, self::Preparing, self::Ready], true);
    }
}
