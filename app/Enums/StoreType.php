<?php

namespace App\Enums;

/**
 * ประเภทของสถานี
 *
 * ใช้บอกลูกค้าบนหน้าเลือกร้าน และใช้จัดกลุ่มในรายงานเมื่อมีหลายสถานี
 * เก็บเป็นข้อความในคอลัมน์ `branches.store_type` และ **null ได้** —
 * สถานีที่ยังไม่ได้เลือกประเภทต้องใช้งานได้ปกติ ไม่ใช่บังคับกรอกตั้งแต่วันแรก
 *
 * เพิ่มประเภทใหม่ได้โดยไม่ต้องแก้ฐานข้อมูล แต่ห้ามเปลี่ยนค่า string ของตัวเดิม
 * เพราะข้อมูลที่บันทึกไว้แล้วจะอ่านกลับไม่ได้
 */
enum StoreType: string
{
    case Restaurant = 'restaurant';
    case NoodleShop = 'noodle_shop';
    case Cafe = 'cafe';
    case FoodCourt = 'food_court';
    case StreetCart = 'street_cart';
    case CloudKitchen = 'cloud_kitchen';

    public function label(): string
    {
        return match ($this) {
            self::Restaurant => 'ร้านอาหาร',
            self::NoodleShop => 'ร้านก๋วยเตี๋ยว',
            self::Cafe => 'คาเฟ่ / ร้านกาแฟ',
            self::FoodCourt => 'ฟู้ดคอร์ท / เคาน์เตอร์ในศูนย์',
            self::StreetCart => 'รถเข็น / ซุ้มริมทาง',
            self::CloudKitchen => 'ครัวกลาง (ไม่มีหน้าร้าน)',
        };
    }

    /** ส่งให้หน้าเว็บทำ dropdown */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
