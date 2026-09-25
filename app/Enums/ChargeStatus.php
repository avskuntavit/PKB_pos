<?php

namespace App\Enums;

/**
 * สถานะของ QR หนึ่งใบที่ออกไปแล้ว
 *
 * ── สองสถานะที่ต้องมีคนตัดสิน ──────────────────────────────────────────
 * `mismatch` กับ `unmatched` คือเงินที่เข้ามาแล้วจริงแต่ลงบิลอัตโนมัติไม่ได้
 * เจตนาเดียวกับ `held` ของเงินสดตอนเน็ตหลุด — เงินอยู่ที่เราแล้ว
 * ระบบตัดสินใจแทนคนไม่ได้ และต้องไม่ทำให้มันหายไปเงียบ ๆ
 *
 * ทางที่ **ไม่** เลือก: ปิดบิลด้วยยอดที่เข้ามาจริงแล้วทิ้งส่วนต่างไว้
 * เพราะนั่นทำให้ยอดขายกับเงินที่ได้ไม่ตรงกันโดยไม่มีใครรู้ว่าตรงไหน
 */
enum ChargeStatus: string
{
    /** ออก QR แล้ว รอเงิน */
    case Pending = 'pending';

    /** เงินเข้าครบและลงบิลเรียบร้อย */
    case Paid = 'paid';

    /** หมดอายุโดยไม่มีเงินเข้า */
    case Expired = 'expired';

    /** เกตเวย์ตอบว่าล้มเหลว */
    case Failed = 'failed';

    /** ถูกยกเลิกก่อนมีเงินเข้า (พนักงานกดยกเลิก หรือเปลี่ยนไปจ่ายเงินสด) */
    case Cancelled = 'cancelled';

    /** เงินเข้าแล้วแต่ยอดไม่เท่าที่ขอ — ต้องมีคนตัดสิน */
    case Mismatch = 'mismatch';

    /** เงินเข้าแล้วแต่บิลถูกปิดไปด้วยวิธีอื่นแล้ว — เงินก้อนนี้ไม่มีบิลรองรับ */
    case Unmatched = 'unmatched';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'รอเงินเข้า',
            self::Paid => 'จ่ายแล้ว',
            self::Expired => 'หมดอายุ',
            self::Failed => 'ล้มเหลว',
            self::Cancelled => 'ยกเลิก',
            self::Mismatch => 'ยอดไม่ตรง รอตัดสิน',
            self::Unmatched => 'ไม่มีบิลรองรับ รอตัดสิน',
        };
    }

    /** ยังต้องไล่ถามเกตเวย์อยู่ไหม */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /** เงินเข้ามาแล้วจริง (ไม่ว่าจะลงบิลได้หรือไม่) */
    public function hasMoney(): bool
    {
        return in_array($this, [self::Paid, self::Mismatch, self::Unmatched], true);
    }

    /** เงินอยู่ที่เราแล้วแต่ยังไม่มีบิลรองรับ — ต้องมีคนตัดสิน */
    public function needsDecision(): bool
    {
        return in_array($this, [self::Mismatch, self::Unmatched], true);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
