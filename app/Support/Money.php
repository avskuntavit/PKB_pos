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

    /*
    |--------------------------------------------------------------------------
    | แปลงหน่วยให้เกตเวย์ชำระเงิน
    |--------------------------------------------------------------------------
    |
    | Stripe และ Beam รับยอดเป็น **สตางค์** เป็นจำนวนเต็ม (100 บาท = 10000)
    | นี่คือจุดที่ผิดแล้วผิด 100 เท่า และผิดแบบที่เทสต์ในร้านมองไม่เห็น
    | ถ้า sandbox ใช้หน่วยเดียวกับที่เราเดาผิด
    |
    | จึงรวมไว้ที่นี่สองเมธอด ห้าม driver คูณ 100 เอง — ถ้าแต่ละ driver
    | แปลงหน่วยเอง จะมีวันที่ใครลืมคูณ แล้วลูกค้าจ่าย 1 บาทแทน 100 บาท
    |
    */

    /** บาท -> สตางค์ (จำนวนเต็ม) */
    public static function toSatang(float|int|string $baht): int
    {
        /*
        | ต้อง round ไม่ใช่ (int) เฉย ๆ
        |
        | 19.99 * 100 ใน IEEE ได้ 1998.9999999999998 แล้ว (int) จะตัดเหลือ 1998
        | = คิดเงินขาดไปหนึ่งสตางค์ทุกบิลที่ลงท้ายด้วยเลขแบบนี้
        */
        return (int) round(static::round($baht) * 100);
    }

    /** สตางค์ -> บาท */
    public static function fromSatang(int $satang): float
    {
        return static::round($satang / 100);
    }
}
