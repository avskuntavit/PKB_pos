<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Open = 'open';           // เปิดบิลอยู่
    case Paid = 'paid';           // ปิดบิลแล้ว
    case Void = 'void';           // ทำลายบิล
    case Refunded = 'refunded';   // คืนเงิน

    public function label(): string
    {
        return match ($this) {
            self::Open => 'เปิดบิลอยู่',
            self::Paid => 'ปิดบิลแล้ว',
            self::Void => 'ทำลายบิล',
            self::Refunded => 'คืนเงิน',
        };
    }

    /** นับเป็นยอดขายหรือไม่ */
    public function countsAsSale(): bool
    {
        return $this === self::Paid;
    }
}
