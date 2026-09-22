<?php

namespace App\Enums;

/**
 * สิ่งที่ลูกค้าได้เมื่อเข้าเงื่อนไขโปรโมชั่น
 *
 * แยกเป็นสองกลุ่มที่คิดคนละฐาน:
 *   item_* คิดจากยอดของ "เมนูที่ร่วมรายการ" เท่านั้น
 *   bill_* คิดจากยอดทั้งบิลหลังหักส่วนลดรายสินค้า
 */
enum PromotionReward: string
{
    case ItemPercent = 'item_percent';

    case ItemAmount = 'item_amount';

    /** ทุกชิ้นที่ร่วมรายการเหลือชิ้นละเท่านี้ — ช่อง "กำหนดเอง" บนหน้าจอ */
    case ItemFixedPrice = 'item_fixed_price';

    case FreeItem = 'free_item';

    case BillPercent = 'bill_percent';

    case BillAmount = 'bill_amount';

    public function label(): string
    {
        return match ($this) {
            self::ItemPercent => 'ลดราคาเมนูที่ร่วมรายการ (%)',
            self::ItemAmount => 'ลดราคาเมนูที่ร่วมรายการ (บาท)',
            self::ItemFixedPrice => 'กำหนดราคาพิเศษต่อชิ้น',
            self::FreeItem => 'แถมเมนูฟรี',
            self::BillPercent => 'ส่วนลดท้ายบิล (%)',
            self::BillAmount => 'ส่วนลดท้ายบิล (บาท)',
        };
    }

    /** หน่วยของ reward_value */
    public function unit(): ?string
    {
        return match ($this) {
            self::ItemPercent, self::BillPercent => '%',
            self::ItemAmount, self::BillAmount, self::ItemFixedPrice => 'บาท',
            self::FreeItem => null,
        };
    }

    /** คิดจากยอดทั้งบิล ไม่ใช่เฉพาะเมนูที่ร่วมรายการ */
    public function isBillWide(): bool
    {
        return in_array($this, [self::BillPercent, self::BillAmount], true);
    }

    /** ต้องตั้งรายการเมนูของแถมไว้ด้วย ไม่งั้นโปรใช้ไม่ได้ */
    public function needsFreeProducts(): bool
    {
        return $this === self::FreeItem;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label(), 'unit' => $c->unit()],
            self::cases(),
        );
    }
}
