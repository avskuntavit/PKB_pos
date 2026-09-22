<?php

namespace App\Support;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;

/**
 * รูปร่างของ "เมนูหนึ่งรายการ" ที่ส่งไปให้หน้าลูกค้า
 *
 * มีที่เดียวโดยตั้งใจ เพราะตอนนี้มีสองที่ที่ต้องใช้ข้อมูลชุดเดียวกันเป๊ะ ๆ:
 *   1. หน้าสั่งอาหารจริง (Storefront\MenuController)
 *   2. แผ่นตัวอย่างในหลังบ้าน (BackOffice\ProductPreviewController)
 *
 * ถ้าปล่อยให้ต่างคนต่าง map ฟิลด์ วันหนึ่งหลังบ้านจะโชว์อย่างหนึ่ง
 * แล้วลูกค้าเห็นอีกอย่าง ซึ่งแย่กว่าไม่มีตัวอย่างให้ดูเลย
 */
class StorefrontProduct
{
    /**
     * @param  bool  $showStaffPrice  ลูกค้าคนนี้ได้สิทธิ์ราคาพนักงานหรือยัง
     * @param  int|null  $branchId  สาขาที่กำลังดู — ตัดสินราคาที่ลูกค้าเห็น (null = สาขาปัจจุบัน)
     */
    public static function payload(Product $product, bool $showStaffPrice = false, ?int $branchId = null): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category_id' => $product->category_id,
            'price' => $product->priceAt($branchId),
            'staff_price' => $showStaffPrice ? $product->staffPrice() : null,
            'is_alcohol' => (bool) $product->is_alcohol,
            'image_path' => $product->image_path,
            'promo_label' => $product->promo_label,
            'modifier_groups' => $product->activeModifierGroups
                ->map(fn (ModifierGroup $g) => [
                    'id' => $g->id,
                    // ลูกค้าเห็นชื่อหน้าบ้าน ไม่ใช่ชื่อภายในของเซ็ต
                    'name' => $g->displayName(),
                    'min_select' => $g->min_select,
                    'max_select' => $g->max_select,
                    'is_required' => $g->is_required,
                    'modifiers' => $g->modifiers
                        ->where('is_active', true)
                        ->values()
                        ->map(fn (Modifier $m) => [
                            'id' => $m->id,
                            'name' => $m->name,
                            'price_delta' => (float) $m->price_delta,
                            // ติ๊กไว้ให้ตั้งแต่เปิดหน้าต่างสั่ง เช่น ขนาด "ธรรมดา"
                            'is_default' => $m->is_default,
                        ]),
                ]),
        ];
    }

    /** ความสัมพันธ์ที่ต้อง eager load ก่อนเรียก payload() ไม่งั้นยิง query รายเมนู */
    public static function eagerLoad(): array
    {
        return [
            'category:id,name,color',
            'activeModifierGroups.modifiers' => fn ($q) => $q->where('is_active', true),
        ];
    }
}
