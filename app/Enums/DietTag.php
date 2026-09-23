<?php

namespace App\Enums;

/**
 * ป้ายบอกข้อมูลอาหารบนเมนู
 *
 * ── ทำไมไม่ใช้ช่องข้อความอิสระ ─────────────────────────────
 * ถ้าปล่อยให้ร้านพิมพ์เอง จะได้ "เผ็ด" "เผ็ดมาก" "เผ็ดนิดหน่อย" "พริก 3 เม็ด"
 * ปนกันจนกรองไม่ได้ และแปลเป็นภาษาอื่นทีหลังไม่ได้ด้วย
 *
 * ── ทำไมแยกเป็นสามกลุ่ม ───────────────────────────────────
 * ลูกค้าอ่านสามกลุ่มนี้คนละจังหวะ
 *   ความเผ็ด   — อยากรู้ก่อนสั่ง เพราะเปลี่ยนใจง่าย
 *   ประเภท     — คนที่ถือศีลหรือกินเจต้องกรองทั้งเมนู
 *   ต้องระวัง  — คนแพ้อาหารต้องเห็นก่อนกด ไม่ใช่หลังจานมาถึง
 *
 * สีจึงแยกตามกลุ่ม ไม่ใช่แยกตามป้าย เพื่อให้กวาดตาทีเดียวรู้ว่าอันไหนเป็นคำเตือน
 *
 * ── ข้อจำกัดที่ต้องบอกร้าน ────────────────────────────────
 * ป้าย "ต้องระวัง" เป็นข้อมูลที่ร้านกรอกเอง ระบบไม่ได้ตรวจสูตรให้
 * ไม่ควรใช้แทนการถามพนักงานสำหรับคนที่แพ้รุนแรง
 */
enum DietTag: string
{
    case Spicy = 'spicy';

    case VerySpicy = 'very_spicy';

    case Vegetarian = 'vegetarian';

    case Vegan = 'vegan';

    case Halal = 'halal';

    case ContainsNut = 'contains_nut';

    case ContainsSeafood = 'contains_seafood';

    case ContainsDairy = 'contains_dairy';

    case ContainsGluten = 'contains_gluten';

    public function label(): string
    {
        return match ($this) {
            self::Spicy => 'เผ็ด',
            self::VerySpicy => 'เผ็ดมาก',
            self::Vegetarian => 'มังสวิรัติ',
            self::Vegan => 'เจ',
            self::Halal => 'ฮาลาล',
            self::ContainsNut => 'มีถั่ว',
            self::ContainsSeafood => 'มีอาหารทะเล',
            self::ContainsDairy => 'มีนม',
            self::ContainsGluten => 'มีแป้งสาลี',
        };
    }

    /** heat = ความเผ็ด, diet = ประเภทอาหาร, allergen = สิ่งที่ต้องระวัง */
    public function kind(): string
    {
        return match ($this) {
            self::Spicy, self::VerySpicy => 'heat',
            self::Vegetarian, self::Vegan, self::Halal => 'diet',
            default => 'allergen',
        };
    }

    /** @return array<int, array{value: string, label: string, kind: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label(), 'kind' => $t->kind()],
            self::cases(),
        );
    }

    /** ป้ายชื่อกลุ่ม — ใช้บนหน้าจัดการเมนู */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'heat' => 'ความเผ็ด',
            'diet' => 'ประเภทอาหาร',
            default => 'สิ่งที่ต้องระวัง',
        };
    }

    /**
     * คำอธิบายกลุ่มที่โชว์ให้คนตั้งค่าเห็น
     *
     * ข้อความของกลุ่ม allergen สำคัญที่สุด — เป็นข้อจำกัดที่เดิมเขียนไว้แต่ในคอมเมนต์
     * ของไฟล์นี้ ซึ่งคนที่กำลังติ๊กช่องอยู่ไม่มีทางเห็น ที่ที่ควรอยู่คือตรงหน้าจอตอนติ๊ก
     */
    public static function kindHint(string $kind): string
    {
        return match ($kind) {
            'heat' => 'ลูกค้าอยากรู้ก่อนสั่ง เพราะเป็นเรื่องที่เปลี่ยนใจง่ายที่สุด',
            'diet' => 'คนถือศีลหรือกินเจใช้กรองทั้งเมนู ติ๊กผิดคือให้ข้อมูลผิด',
            default => 'ร้านกรอกเอง ระบบไม่ได้ตรวจสูตรให้ — ไม่ควรใช้แทนการถามพนักงานสำหรับคนที่แพ้รุนแรง',
        };
    }

    /**
     * ป้ายทั้งหมดจัดเป็นกลุ่มตามที่ลูกค้าอ่าน — ใช้สร้างตัวเลือกบนหน้าจัดการเมนู
     *
     * @return array<int, array{kind: string, label: string, hint: string, tags: array<int, array{value: string, label: string}>}>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $tag) {
            $groups[$tag->kind()][] = ['value' => $tag->value, 'label' => $tag->label()];
        }

        return array_map(
            fn (string $kind) => [
                'kind' => $kind,
                'label' => self::kindLabel($kind),
                'hint' => self::kindHint($kind),
                'tags' => $groups[$kind],
            ],
            array_keys($groups),
        );
    }

    /**
     * แปลงค่าที่เก็บไว้ให้เหลือเฉพาะป้ายที่ระบบรู้จัก
     *
     * ค่าในคอลัมน์ json อาจค้างจากรุ่นก่อนหรือถูกแก้มือในฐานข้อมูล
     * ถ้าไม่กรอง หน้าเมนูจะพังทั้งหน้าเพราะป้ายเดียวที่สะกดผิด
     *
     * @return array<int, self>
     */
    public static function parse(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? self::tryFrom($v) : null,
            $values,
        )));
    }
}
