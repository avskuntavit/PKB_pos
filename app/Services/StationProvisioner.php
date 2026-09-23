<?php

namespace App\Services;

use App\Enums\TableStatus;
use App\Models\Branch;
use App\Models\BranchPaymentMethod;
use App\Models\BranchProduct;
use App\Models\BranchStockItem;
use App\Models\DiningTable;
use App\Models\FloorPlanObject;
use App\Models\Modifier;
use App\Models\ModifierRecipeItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\StockItem;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/**
 * เปิดสถานีใหม่ พร้อมคัดลอกข้อมูลตั้งต้นจากสถานีที่เปิดอยู่แล้ว
 *
 * ── ทำไมต้องคัดลอก ─────────────────────────────────────────
 * เมนู หมวด เซ็ตตัวเลือก และของในคลัง เป็น "ของกลาง" อยู่แล้ว สถานีใหม่เห็นทันที
 * แต่ของสี่อย่างนี้ผูกสาขา ถ้าไม่คัดลอกให้ สถานีใหม่จะเปิดมาแล้วใช้งานไม่ได้จริง:
 *
 *   1. สูตรอาหาร (recipe_items / modifier_recipe_items) — ไม่มีสูตร = ขายแล้วไม่ตัดสต๊อก
 *      และต้นทุนตามสูตรเป็น 0 ทั้งร้าน รายงานกำไรจะบอกว่ากำไร 100%
 *   2. ต้นทุน/จุดสั่งซื้อรายสาขา (branch_stock_items)
 *   3. ราคาและการเปิด-ปิดเมนูรายสาขา (branch_product)
 *   4. โซน โต๊ะ ผังร้าน และช่องทางชำระเงิน
 *
 * ── สิ่งที่ตั้งใจ "ไม่" คัดลอก ────────────────────────────
 *   • stock_qty — ของจริงในมือของสถานีใหม่คือศูนย์ ต้องรับของเข้าเอง
 *     คัดลอกมาจะได้สต๊อกผีที่ตัดไปเรื่อย ๆ จนติดลบโดยไม่มีใครเอะใจ
 *   • qr_token ของโต๊ะ — unique ทั้งระบบ และ QR ที่ติดโต๊ะสถานีเดิมต้องไม่พาลูกค้ามาผิดที่
 *   • สถานะโต๊ะ — โต๊ะสถานีใหม่เริ่มที่ "ว่าง" เสมอ ไม่ใช่สถานะของโต๊ะต้นทาง ณ ตอนกด
 *   • unavailable_until — "ของหมดวันนี้" เป็นเรื่องของวันนั้นที่สาขานั้น
 *   • บิล พนักงาน โปรโมชั่น เครื่องพิมพ์ — เป็นของหน้างานจริง ต้องตั้งเองที่สถานีนั้น
 *
 * ── ข้อจำกัดที่ตั้งใจ ────────────────────────────────────
 * คัดลอกได้เฉพาะ "ตอนเปิดสถานีใหม่" เท่านั้น ไม่มีปุ่มคัดลอกซ้ำทีหลัง
 * เพราะการคัดลอกทับสถานีที่ขายอยู่แล้วตอบยากว่าควรทับหรือควรข้าม
 * และถ้าตอบผิดจะพังเงียบ ๆ (สูตรซ้ำ = ตัดสต๊อกสองเท่า)
 */
class StationProvisioner
{
    /** หมวดข้อมูลที่คัดลอกได้ ใช้เป็นรายการอ้างอิงของทั้ง controller และหน้าเว็บ */
    public const COPYABLE = ['recipes', 'menu_overrides', 'tables', 'payment_methods'];

    public static function copyLabels(): array
    {
        return [
            'recipes' => 'สูตรอาหาร + ต้นทุนวัตถุดิบ (ยอดคงเหลือเริ่มที่ 0)',
            'menu_overrides' => 'ราคาและการเปิด-ปิดเมนูรายสถานี',
            'tables' => 'โซน โต๊ะ และผังร้าน (สร้าง QR ใหม่ให้)',
            'payment_methods' => 'ช่องทางชำระเงินที่เปิดรับ',
        ];
    }

    /**
     * เปิดสถานีใหม่ทั้งชุดในธุรกรรมเดียว
     *
     * ถ้าคัดลอกพังกลางทาง สถานีที่สร้างไปแล้วต้องหายไปด้วย
     * ไม่งั้นจะเหลือสถานีเปล่าที่ดูเหมือนพร้อมขายแต่ไม่มีสูตรสักอัน
     *
     * @param  array<string, mixed>  $attributes  ค่าที่ผ่าน validate แล้ว
     * @param  array<int, string>  $copy  หมวดที่จะคัดลอก (ดู COPYABLE)
     * @return array{branch: Branch, copied: array<string, int>}
     */
    public function create(array $attributes, ?Branch $source = null, array $copy = []): array
    {
        return DB::transaction(function () use ($attributes, $source, $copy) {
            $branch = Branch::create($attributes);

            return [
                'branch' => $branch,
                'copied' => $source ? $this->copyFrom($branch, $source, $copy) : [],
            ];
        });
    }

    /**
     * คัดลอกตามหมวดที่เลือก
     *
     * @param  array<int, string>  $copy
     * @return array<string, int> จำนวนแถวที่คัดลอกจริง แยกตามหมวด
     */
    public function copyFrom(Branch $target, Branch $source, array $copy): array
    {
        // กันค่าที่ส่งมาจากหน้าเว็บที่ไม่อยู่ในรายการ และกันเลือกซ้ำ
        $want = array_values(array_intersect(self::COPYABLE, array_unique($copy)));

        // คัดลอกเข้าตัวเองไม่มีความหมาย และจะสร้างแถวซ้ำที่ชน unique
        if ($target->id === $source->id || $want === []) {
            return [];
        }

        $out = [];

        if (in_array('menu_overrides', $want, true)) {
            $out['menu_overrides'] = $this->copyMenuOverrides($target, $source);
        }

        if (in_array('recipes', $want, true)) {
            $out['recipes'] = $this->copyRecipes($target, $source);
            $out['stock_items'] = $this->copyStockLevels($target, $source);
        }

        if (in_array('tables', $want, true)) {
            $out['tables'] = $this->copyTables($target, $source);
        }

        if (in_array('payment_methods', $want, true)) {
            $out['payment_methods'] = $this->copyPaymentMethods($target, $source);
        }

        return $out;
    }

    /**
     * เมนูที่สถานีปลายทางเห็นได้ = เมนูกลาง + เมนูของสถานีนั้นเอง
     *
     * ต้องกรองด้วยชุดนี้เสมอ เพราะสถานีต้นทางอาจมีเมนูพิเศษของตัวเอง
     * ถ้าคัดลอกราคาหรือสูตรของเมนูนั้นไปด้วย สถานีใหม่จะได้แถวที่ชี้เมนูที่ตัวเองไม่มีสิทธิ์ขาย
     *
     * @return array<int, int>
     */
    protected function usableProductIds(Branch $target): array
    {
        return Product::forCatalog($target->id)->pluck('id')->all();
    }

    /** ของในคลังที่สถานีปลายทางใช้ได้ — ของกลาง + ของสถานีนั้นเอง (เหตุผลเดียวกับเมนู) */
    protected function usableStockItemIds(Branch $target): array
    {
        return StockItem::forCatalog($target->id)->pluck('id')->all();
    }

    protected function copyMenuOverrides(Branch $target, Branch $source): int
    {
        $rows = BranchProduct::where('branch_id', $source->id)
            ->whereIn('product_id', $this->usableProductIds($target))
            ->get();

        foreach ($rows as $row) {
            BranchProduct::create([
                'branch_id' => $target->id,
                'product_id' => $row->product_id,
                // null ต้องยังเป็น null — null แปลว่า "ใช้ค่ากลาง" ไม่ใช่ 0
                'price' => $row->price,
                'is_active' => $row->is_active,
                'sort_order' => $row->sort_order,
                'print_group' => $row->print_group,
            ]);
        }

        return $rows->count();
    }

    protected function copyRecipes(Branch $target, Branch $source): int
    {
        $products = $this->usableProductIds($target);
        $stockItems = $this->usableStockItemIds($target);

        $recipes = RecipeItem::where('branch_id', $source->id)
            ->whereIn('product_id', $products)
            ->whereIn('stock_item_id', $stockItems)
            ->get();

        foreach ($recipes as $item) {
            RecipeItem::create([
                'branch_id' => $target->id,
                'product_id' => $item->product_id,
                'stock_item_id' => $item->stock_item_id,
                'qty' => $item->qty,
                'scales_with_portion' => $item->scales_with_portion,
            ]);
        }

        // ตัวเลือกที่เพิ่ม/ลดวัตถุดิบ เช่น "เพิ่มเส้น" — ไม่คัดลอกก็ตัดสต๊อกส่วนที่เพิ่มไม่ได้
        $modifierIds = Modifier::whereHas('group', fn ($q) => $q->forCatalog($target->id))
            ->pluck('id')
            ->all();

        $extras = ModifierRecipeItem::where('branch_id', $source->id)
            ->whereIn('modifier_id', $modifierIds)
            ->whereIn('stock_item_id', $stockItems)
            ->get();

        foreach ($extras as $extra) {
            ModifierRecipeItem::create([
                'branch_id' => $target->id,
                'modifier_id' => $extra->modifier_id,
                'stock_item_id' => $extra->stock_item_id,
                'qty' => $extra->qty,
            ]);
        }

        return $recipes->count() + $extras->count();
    }

    /**
     * ต้นทุนต่อหน่วยและจุดสั่งซื้อ — **ยอดคงเหลือเริ่มที่ 0 เสมอ**
     *
     * ต้นทุนคัดลอกได้เพราะสถานีใหม่ซื้อของจากเจ้าเดิมในราคาใกล้เคียงกัน
     * และมีค่าตั้งต้นดีกว่าให้รายงานกำไรอ่านศูนย์ไปก่อน
     * แต่ของในมือคัดลอกไม่ได้เด็ดขาด — สถานีใหม่ยังไม่มีอะไรอยู่ในสต๊อกจริง
     */
    protected function copyStockLevels(Branch $target, Branch $source): int
    {
        $levels = BranchStockItem::where('branch_id', $source->id)
            ->whereIn('stock_item_id', $this->usableStockItemIds($target))
            ->get();

        foreach ($levels as $level) {
            BranchStockItem::create([
                'branch_id' => $target->id,
                'stock_item_id' => $level->stock_item_id,
                'stock_qty' => 0,
                'cost_per_unit' => $level->cost_per_unit,
                'reorder_level' => $level->reorder_level,
                'is_active' => $level->is_active,
            ]);
        }

        return $levels->count();
    }

    /** คืนจำนวนโต๊ะที่คัดลอก (โซนกับอ็อบเจ็กต์ผังร้านไปด้วยแต่ไม่นับซ้ำในตัวเลขที่รายงาน) */
    protected function copyTables(Branch $target, Branch $source): int
    {
        // โซนต้องสร้างก่อน เพราะโต๊ะและอ็อบเจ็กต์ชี้ไปที่ id ของโซน
        // เก็บ map id เดิม -> id ใหม่ ไว้แปลง ไม่ใช่ยกค่าเดิมไปทั้งดุ้น
        $zoneMap = [];

        foreach (Zone::where('branch_id', $source->id)->orderBy('id')->get() as $zone) {
            $zoneMap[$zone->id] = Zone::create([
                'branch_id' => $target->id,
                'name' => $zone->name,
                'sort_order' => $zone->sort_order,
            ])->id;
        }

        $tables = DiningTable::where('branch_id', $source->id)->orderBy('id')->get();

        foreach ($tables as $table) {
            DiningTable::create([
                'branch_id' => $target->id,
                'zone_id' => $table->zone_id ? ($zoneMap[$table->zone_id] ?? null) : null,
                'name' => $table->name,
                // ไม่ส่ง qr_token — ปล่อยให้โมเดลสุ่มใบใหม่ให้
                'seats' => $table->seats,
                'pos_x' => $table->pos_x,
                'pos_y' => $table->pos_y,
                'width' => $table->width,
                'height' => $table->height,
                'shape' => $table->shape,
                'status' => TableStatus::Available,
                'is_active' => $table->is_active,
            ]);
        }

        foreach (FloorPlanObject::where('branch_id', $source->id)->orderBy('id')->get() as $object) {
            FloorPlanObject::create([
                'branch_id' => $target->id,
                'zone_id' => $object->zone_id ? ($zoneMap[$object->zone_id] ?? null) : null,
                'type' => $object->type,
                'name' => $object->name,
                'pos_x' => $object->pos_x,
                'pos_y' => $object->pos_y,
                'width' => $object->width,
                'height' => $object->height,
                'color' => $object->color,
                'icon' => $object->icon,
                'is_active' => $object->is_active,
            ]);
        }

        return $tables->count();
    }

    protected function copyPaymentMethods(Branch $target, Branch $source): int
    {
        $rows = BranchPaymentMethod::where('branch_id', $source->id)->get();

        foreach ($rows as $row) {
            BranchPaymentMethod::create([
                'branch_id' => $target->id,
                'method' => $row->method,
                'is_enabled' => $row->is_enabled,
                'show_on_storefront' => $row->show_on_storefront,
                'sort_order' => $row->sort_order,
                'label_override' => $row->label_override,
                'note' => $row->note,
            ]);
        }

        return $rows->count();
    }
}
