<?php

namespace App\Enums;

/**
 * หน่วยฐานของวัตถุดิบ — หน่วยที่ใช้เก็บสต๊อกและเขียนสูตร
 *
 * เลือกหน่วยเล็กสุดที่สูตรจะใช้ แล้วค่อยตั้งหน่วยซื้อไว้ตอนรับของ
 *   ถั่วงอก  -> กรัม  สูตรเขียน 10   (ไม่ใช่ 0.01 กก.)
 *   น้ำซุป   -> มล.   สูตรเขียน 250  (ไม่ใช่ 0.25 ลิตร)
 *   ถุงหูหิ้ว -> ใบ    สูตรเขียน 1
 *
 * เหตุผลที่ต้องเป็นหน่วยเล็ก: ต้นทุนต่อหน่วยเก็บทศนิยม 4 ตำแหน่ง
 * ถ้าเก็บเป็นกิโลกรัม ราคาต่อกรัมจะปัดหายจนต้นทุนเพี้ยน
 */
enum StockUnit: string
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Milliliter = 'ml';
    case Liter = 'l';
    case Piece = 'pc';
    case Bottle = 'bottle';
    case Can = 'can';
    case Bag = 'bag';
    case Box = 'box';
    case Pack = 'pack';
    case Sheet = 'sheet';

    public function label(): string
    {
        return match ($this) {
            self::Gram => 'กรัม',
            self::Kilogram => 'กิโลกรัม',
            self::Milliliter => 'มิลลิลิตร',
            self::Liter => 'ลิตร',
            self::Piece => 'ชิ้น',
            self::Bottle => 'ขวด',
            self::Can => 'กระป๋อง',
            self::Bag => 'ถุง',
            self::Box => 'กล่อง',
            self::Pack => 'แพ็ค',
            self::Sheet => 'แผ่น',
        };
    }

    /** ใช้จัดกลุ่มในหน้าเลือกหน่วย */
    public function group(): string
    {
        return match ($this) {
            self::Gram, self::Kilogram => 'น้ำหนัก',
            self::Milliliter, self::Liter => 'ปริมาตร',
            default => 'จำนวนนับ',
        };
    }

    /** ของที่นับเป็นชิ้นไม่ควรมีทศนิยม */
    public function decimals(): int
    {
        return $this->group() === 'จำนวนนับ' ? 0 : 2;
    }

    /** หน่วยซื้อที่มักคู่กับหน่วยฐานนี้ ใช้เป็นค่าแนะนำในฟอร์ม */
    public function suggestedPurchase(): array
    {
        return match ($this) {
            self::Gram => ['กก.', 1000],
            self::Milliliter => ['ลิตร', 1000],
            default => [null, 1],
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $u) => [
            'value' => $u->value,
            'label' => $u->label(),
            'group' => $u->group(),
            'decimals' => $u->decimals(),
            'suggested_purchase_unit' => $u->suggestedPurchase()[0],
            'suggested_purchase_factor' => $u->suggestedPurchase()[1],
        ], self::cases());
    }
}
