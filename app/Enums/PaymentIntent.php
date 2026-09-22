<?php

namespace App\Enums;

/**
 * วิธีที่ลูกค้า "ตั้งใจจะจ่าย" ตอนกดสั่งจากหน้าร้านออนไลน์
 *
 * ยังไม่ใช่การชำระเงินจริง — เงินเข้าจริงตอนพนักงานกดรับเงินที่เคาน์เตอร์
 * ตัวนี้มีไว้ให้ร้านเตรียมตัวถูก เช่น เตรียมออก QR โครงการรัฐรอไว้
 */
enum PaymentIntent: string
{
    case PayAtStore = 'pay_at_store';
    case PromptPay = 'promptpay';
    case KhonLaKhrueng = 'khon_la_khrueng';
    case ThaiChuayThai = 'thai_chuay_thai';

    public function label(): string
    {
        return match ($this) {
            self::PayAtStore => 'จ่ายตอนมารับ',
            self::PromptPay => 'พร้อมเพย์ (สแกนจ่ายได้เลย)',
            self::KhonLaKhrueng => 'คนละครึ่ง',
            self::ThaiChuayThai => 'ไทยช่วยไทย',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PayAtStore => 'เงินสดหรือโอนที่เคาน์เตอร์ตอนมารับของ',
            self::PromptPay => 'สแกน QR จ่ายล่วงหน้าได้เลย แล้วแสดงสลิปตอนมารับ',
            self::KhonLaKhrueng, self::ThaiChuayThai =>
                'แจ้งร้านไว้ล่วงหน้า พนักงานจะออก QR ของโครงการให้ที่เคาน์เตอร์ตอนมารับ',
        };
    }

    /** เป็นโครงการของรัฐที่ต้องให้พนักงานออก QR ที่เคาน์เตอร์ไหม */
    public function isGovernmentScheme(): bool
    {
        return in_array($this, [self::KhonLaKhrueng, self::ThaiChuayThai], true);
    }

    /** สร้าง QR พร้อมเพย์ให้ลูกค้าสแกนเองได้เลยไหม */
    public function showsPromptPayQr(): bool
    {
        return $this === self::PromptPay;
    }

    public static function options(): array
    {
        return array_map(fn (self $i) => [
            'value' => $i->value,
            'label' => $i->label(),
            'description' => $i->description(),
            'is_government_scheme' => $i->isGovernmentScheme(),
        ], self::cases());
    }
}
