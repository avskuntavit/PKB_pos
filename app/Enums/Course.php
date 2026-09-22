<?php

namespace App\Enums;

/**
 * คอร์ส — ลำดับที่อาหารควรออกจากครัว
 *
 * ── ปัญหาที่แก้ ─────────────────────────────────────────────
 * ลูกค้าสั่งทีเดียวจบ แต่ไม่ได้อยากได้พร้อมกันทั้งหมด
 * ถ้าส่งครัวรวดเดียว ของหวานจะมาถึงโต๊ะพร้อมจานหลักแล้วเย็นคาโต๊ะ
 * ส่วนครัวก็เจองานกองเดียวทั้งที่ทยอยทำได้
 *
 * คอร์สจึงเป็นแค่ "ป้ายบอกว่ารายการนี้อยู่กองไหน" พนักงานเลือกส่งทีละกอง
 * ไม่ใช่ระบบจับเวลาอัตโนมัติ — คนที่รู้ว่าโต๊ะพร้อมหรือยังคือพนักงานที่ยืนอยู่ตรงนั้น
 *
 * null = ไม่จัดคอร์ส ซึ่งเป็นค่าเริ่มต้นและเหมาะกับร้านส่วนใหญ่
 * ร้านตามสั่งกับลานเบียร์ไม่ต้องใช้เลยก็ได้ ระบบไม่บังคับ
 */
enum Course: int
{
    case Starter = 1;

    case Main = 2;

    case Dessert = 3;

    public function label(): string
    {
        return match ($this) {
            self::Starter => 'เรียกน้ำย่อย',
            self::Main => 'จานหลัก',
            self::Dessert => 'ของหวาน',
        };
    }

    /** สีบนหน้าจอ POS ให้กวาดตาแยกกองได้เร็ว */
    public function color(): string
    {
        return match ($this) {
            self::Starter => 'var(--series-3)',
            self::Main => 'var(--series-1)',
            self::Dessert => 'var(--series-5)',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label(), 'color' => $c->color()],
            self::cases(),
        );
    }
}
