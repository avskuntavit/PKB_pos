<?php

namespace App\Enums;

/** เงื่อนไขที่ทำให้โปรโมชั่นทำงาน */
enum PromotionTrigger: string
{
    /** ไม่มีเงื่อนไขนับ — แค่มีเมนูที่ร่วมรายการอยู่ในบิลก็พอ */
    case None = 'none';

    /** ซื้อเมนูที่ร่วมรายการครบตามจำนวนชิ้น */
    case Qty = 'qty';

    /** ซื้อเมนูที่ร่วมรายการครบตามยอดเงิน */
    case Amount = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::None => 'ไม่มีเงื่อนไข',
            self::Qty => 'ซื้อครบตามจำนวนชิ้น',
            self::Amount => 'ซื้อครบตามยอดเงิน',
        };
    }

    /** หน่วยของ trigger_value ไว้เขียนกำกับช่องกรอก */
    public function unit(): ?string
    {
        return match ($this) {
            self::None => null,
            self::Qty => 'ชิ้น',
            self::Amount => 'บาท',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label(), 'unit' => $c->unit()],
            self::cases(),
        );
    }
}
