<?php

namespace App\Enums;

enum OrderType: string
{
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::DineIn => 'ทานที่ร้าน',
            self::Takeaway => 'ซื้อกลับบ้าน',
            self::Delivery => 'การจัดส่ง',
        };
    }
}
