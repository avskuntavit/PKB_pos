<?php

namespace App\Enums;

/**
 * สถานะใบโอนของระหว่างสถานี
 *
 * ไม่มีสถานะ "ร่าง" โดยตั้งใจ — ใบโอนเกิดขึ้นตอนของออกจากร้านจริงแล้วเท่านั้น
 * ใบร่างที่ยังไม่ตัดสต๊อกจะทำให้คนเข้าใจผิดว่าของถูกกันไว้แล้ว
 */
enum StockTransferStatus: string
{
    case InTransit = 'in_transit';   // ตัดออกจากต้นทางแล้ว ปลายทางยังไม่กดรับ
    case Received = 'received';      // ปลายทางรับเข้าสต๊อกแล้ว
    case Cancelled = 'cancelled';    // ยกเลิก ของถูกคืนกลับเข้าต้นทาง

    public function label(): string
    {
        return match ($this) {
            self::InTransit => 'อยู่ระหว่างทาง',
            self::Received => 'รับของแล้ว',
            self::Cancelled => 'ยกเลิก',
        };
    }

    /** ใช้เลือกสีป้ายบนหน้าจอ ให้ตรงกับ variant ของคอมโพเนนต์ Badge */
    public function badge(): string
    {
        return match ($this) {
            self::InTransit => 'warning',
            self::Received => 'success',
            self::Cancelled => 'secondary',
        };
    }

    /** ยังแก้ได้ไหม — รับหรือยกเลิกได้เฉพาะใบที่ยังอยู่ระหว่างทาง */
    public function isOpen(): bool
    {
        return $this === self::InTransit;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
