<?php

namespace App\Enums;

/**
 * สถานะการกระทบยอดเงินเข้าบัญชีของแต่ละช่องทางต่อวัน
 *
 * ── ทำไมมีแค่สามสถานะ ─────────────────────────────────────
 * คำถามเดียวที่หน้านี้ต้องตอบคือ "เงินก้อนนี้เข้าบัญชีครบหรือยัง"
 * ยังไม่ได้ดู · ดูแล้วตรง · ดูแล้วไม่ตรง — พอแล้วสำหรับการปิดบัญชี
 * สถานะกลาง ๆ มากกว่านี้จะทำให้คนกดเลือกผิดโดยไม่มีใครได้ประโยชน์
 */
enum ReconcileStatus: string
{
    case Pending = 'pending';

    case Matched = 'matched';

    case Mismatched = 'mismatched';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'ยังไม่กระทบ',
            self::Matched => 'กระทบแล้ว ตรง',
            self::Mismatched => 'กระทบแล้ว ไม่ตรง',
        };
    }

    /** ยังต้องตามต่อไหม — ใช้กรองรายการที่ค้างอยู่ */
    public function isOpen(): bool
    {
        return $this !== self::Matched;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label()],
            self::cases(),
        );
    }
}
