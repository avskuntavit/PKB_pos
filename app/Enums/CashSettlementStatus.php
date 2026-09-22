<?php

namespace App\Enums;

/**
 * สถานะการนำส่งเงินสดประจำวัน
 *
 * ── ทำไมต้องมี verified แยกจาก submitted ──────────────────
 * "พนักงานบอกว่าโอนแล้ว" กับ "เงินเข้าบัญชีบริษัทจริง" เป็นคนละเหตุการณ์
 * ถ้ารวมเป็นสถานะเดียว ระบบจะเชื่อคำบอกเล่าของคนที่ถือเงินอยู่
 * ซึ่งทำให้การกระทบยอดกับธนาคารไม่มีความหมาย
 */
enum CashSettlementStatus: string
{
    case Pending = 'pending';

    case Submitted = 'submitted';

    case Verified = 'verified';

    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'รอนำส่ง',
            self::Submitted => 'แจ้งโอนแล้ว รอตรวจ',
            self::Verified => 'ตรวจกับธนาคารแล้ว',
            self::Disputed => 'ยอดไม่ตรง',
        };
    }

    /** ยังไม่จบเรื่อง — ใช้กรองรายการที่ผู้จัดการต้องตามต่อ */
    public function isOpen(): bool
    {
        return $this !== self::Verified;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label()],
            self::cases(),
        );
    }
}
