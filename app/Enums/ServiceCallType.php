<?php

namespace App\Enums;

enum ServiceCallType: string
{
    case Bill = 'bill';       // เรียกเก็บเงิน
    case Assist = 'assist';   // เรียกพนักงาน
    case Water = 'water';     // ขอน้ำ/อุปกรณ์

    /**
     * ลูกค้าสแกน QR แล้วโต๊ะยังไม่ถูกเปิด
     *
     * ไม่เหมือนสามชนิดข้างบนตรงที่ลูกค้ายังไม่มีบิล และยังสั่งอะไรไม่ได้เลย
     * นั่งรออยู่เฉย ๆ จนกว่าพนักงานจะเดินมาเปิดโต๊ะให้
     */
    case OpenTable = 'open_table';

    public function label(): string
    {
        return match ($this) {
            self::Bill => 'เรียกเก็บเงิน',
            self::Assist => 'เรียกพนักงาน',
            self::Water => 'ขอน้ำ / อุปกรณ์',
            self::OpenTable => 'ขอเปิดโต๊ะ',
        };
    }

    /**
     * ตัวเลือกที่ลูกค้าซึ่งนั่งอยู่แล้วกดเองได้
     *
     * ไม่รวม "ขอเปิดโต๊ะ" เพราะถ้ากดปุ่มพวกนี้ได้ก็แปลว่าโต๊ะเปิดไปแล้ว
     * คำขอชนิดนั้นเกิดจากหน้าที่ลูกค้ายังสั่งอะไรไม่ได้เท่านั้น
     */
    public static function options(): array
    {
        return array_values(array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label()],
            array_filter(self::cases(), fn (self $t) => $t !== self::OpenTable),
        ));
    }
}
