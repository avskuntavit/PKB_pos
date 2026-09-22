<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case PromptPay = 'promptpay';
    case CreditCard = 'credit_card';
    case Transfer = 'transfer';
    case EWallet = 'ewallet';
    case DeliveryApp = 'delivery_app';
    case KhonLaKhrueng = 'khon_la_khrueng';
    case ThaiChuayThai = 'thai_chuay_thai';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'เงินสด',
            self::PromptPay => 'พร้อมเพย์',
            self::CreditCard => 'บัตรเครดิต',
            self::Transfer => 'โอนเงิน',
            self::EWallet => 'e-Wallet',
            self::DeliveryApp => 'แอปเดลิเวอรี่',
            self::KhonLaKhrueng => 'คนละครึ่ง',
            self::ThaiChuayThai => 'ไทยช่วยไทย',
        };
    }

    public function isCash(): bool
    {
        return $this === self::Cash;
    }

    /**
     * ช่องทางของโครงการรัฐ — ยอดส่วนที่รัฐช่วยจ่ายจะมาทีหลัง
     * ร้านมักบันทึกเป็นคนละบรรทัดกับส่วนที่ลูกค้าจ่ายเอง จึงใช้ split payment ที่มีอยู่แล้ว
     */
    public function isGovernmentScheme(): bool
    {
        return in_array($this, [self::KhonLaKhrueng, self::ThaiChuayThai], true);
    }

    public static function options(): array
    {
        return array_map(
            fn (self $m) => ['value' => $m->value, 'label' => $m->label()],
            self::cases()
        );
    }
}
