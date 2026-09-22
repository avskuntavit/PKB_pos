<?php

namespace App\Support;

/**
 * ตัวช่วยคำนวณเงิน — ปัดทศนิยม 2 ตำแหน่งเสมอ กัน floating point เพี้ยน
 */
class Money
{
    public static function round(float|int|string $value): float
    {
        return round((float) $value, 2);
    }

    /** ปัดเศษท้ายบิลตามการตั้งค่าสาขา */
    public static function applyRounding(float $amount, int $mode): float
    {
        return match ($mode) {
            1 => ceil($amount),                 // ปัดขึ้นเป็นจำนวนเต็ม
            2 => floor($amount),                // ปัดลง
            3 => round($amount),                // ปัดใกล้สุด
            default => static::round($amount),  // ไม่ปัด
        };
    }

    /** แยกภาษีออกจากราคาที่รวม VAT แล้ว */
    public static function extractVat(float $amountIncludingVat, float $vatRate): float
    {
        if ($vatRate <= 0) {
            return 0.0;
        }

        return static::round($amountIncludingVat * $vatRate / (100 + $vatRate));
    }

    /** คิดภาษีเพิ่มจากราคาที่ยังไม่รวม VAT */
    public static function addVat(float $amountExcludingVat, float $vatRate): float
    {
        return static::round($amountExcludingVat * $vatRate / 100);
    }

    public static function percent(float $amount, float $percent): float
    {
        return static::round($amount * $percent / 100);
    }
}
