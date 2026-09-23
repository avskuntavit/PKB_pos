<?php

namespace Database\Seeders;

use App\Enums\StockUnit;
use App\Models\Branch;
use App\Models\BranchStockItem;
use App\Models\Modifier;
use App\Models\ModifierRecipeItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\StockItem;
use Illuminate\Database\Seeder;

/**
 * ของในคลังเป็น "แม่แบบกลาง" สร้างชุดเดียวใช้ได้ทุกสาขา
 * ส่วนยอดคงเหลือ ต้นทุน และจุดสั่งซื้อสร้างแยกรายสาขา
 *
 * เดิม seeder นี้สร้างของซ้ำทุกสาขา — เปิดสาขาที่สามก็ได้ "เส้นเล็ก" เป็นตัวที่สาม
 * ซึ่งเป็นปัญหาที่การย้ายของขึ้นเป็นกลางมาแก้
 *
 * สต๊อกและสูตรเก็บด้วย "หน่วยฐาน" ที่เล็กที่สุด (กรัม / มล. / ชิ้น)
 * สูตรจึงเขียน 120 แทน 0.12 อ่านง่ายและกรอกพลาดยากกว่า
 *
 * ส่วนหน่วยซื้อตั้งไว้ให้ตรงกับที่ร้านซื้อจริง เช่น ซื้อเส้นเป็น กก.
 * ตอนรับของกรอก 5 กก. ระบบเพิ่มให้ 5,000 กรัม และหารราคาต่อกรัมให้เอง
 */
class InventorySeeder extends Seeder
{
    public function run(): void
    {
        // [ชื่อ, หน่วยฐาน, คงเหลือตั้งต้น, ต้นทุน/หน่วยฐาน, จุดสั่งซื้อ, หน่วยซื้อ, 1 หน่วยซื้อ = กี่หน่วยฐาน]
        $catalogue = [
            ['เส้นหมี่ขาว', StockUnit::Gram, 30000, 0.0450, 5000, 'กก.', 1000],
            ['เส้นเล็ก', StockUnit::Gram, 25000, 0.0420, 5000, 'กก.', 1000],
            ['บะหมี่', StockUnit::Gram, 20000, 0.0550, 4000, 'กก.', 1000],
            ['หมูหมัก', StockUnit::Gram, 15000, 0.1600, 3000, 'กก.', 1000],
            ['เนื้อสด', StockUnit::Gram, 10000, 0.2800, 2000, 'กก.', 1000],
            ['ลูกชิ้น', StockUnit::Gram, 12000, 0.1200, 3000, 'กก.', 1000],
            ['ผักบุ้ง', StockUnit::Gram, 8000, 0.0350, 2000, 'กก.', 1000],
            ['น้ำซุป', StockUnit::Milliliter, 40000, 0.0120, 10000, 'ลิตร', 1000],
            // บรรจุภัณฑ์ก็อยู่ในคลังและตัดสต๊อกเหมือนกัน
            ['ถุงหูหิ้ว', StockUnit::Piece, 500, 0.3000, 100, 'แพ็ค', 100],
        ];

        /** @var array<string, StockItem> $map */
        $map = [];

        // แม่แบบกลาง — branch_id = NULL สร้างครั้งเดียว
        foreach ($catalogue as $i => [$name, $unit, , , , $pUnit, $pFactor]) {
            $map[$name] = StockItem::create([
                'branch_id' => null,
                'code' => 'STK'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'unit' => $unit,
                'purchase_unit' => $pUnit,
                'purchase_factor' => $pFactor,
            ]);
        }

        foreach (Branch::all() as $branch) {
            // ยอดคงเหลือและต้นทุนของแต่ละสาขา — ตั้งต้นเท่ากันไว้ก่อน แล้วแยกกันเองเมื่อเริ่มขาย
            foreach ($catalogue as [$name, , $qty, $cost, $reorder]) {
                BranchStockItem::create([
                    'branch_id' => $branch->id,
                    'stock_item_id' => $map[$name]->id,
                    'stock_qty' => $qty,
                    'cost_per_unit' => $cost,
                    'reorder_level' => $reorder,
                ]);
            }

            /*
            | สูตรฐาน — ใส่เฉพาะส่วนที่ทุกแบบใช้เหมือนกัน
            | [ชื่อของในคลัง, ปริมาณต่อ 1 จาน (หน่วยฐาน), โตตามขนาดจานไหม]
            |
            | สูตรยังแยกรายสาขา เพราะปริมาณต่อจานเป็นเรื่องของครัวแต่ละที่
            | ต่างจากเดิมตรงที่ตอนนี้ทุกสาขาชี้ไปของกลางชิ้นเดียวกัน
            |
            | ถุงหูหิ้วตั้ง false ไว้ สั่งจัมโบ้ก็ยังใช้ใบเดียว ไม่ใช่สองใบ
            */
            $recipes = [
                'หมี่ขาว หมูหมัก' => [
                    ['เส้นหมี่ขาว', 120, true], ['หมูหมัก', 80, true],
                    ['ผักบุ้ง', 30, true], ['น้ำซุป', 350, true], ['ถุงหูหิ้ว', 1, false],
                ],
                'เส้นเล็ก หมูหมัก' => [
                    ['เส้นเล็ก', 120, true], ['หมูหมัก', 80, true],
                    ['ผักบุ้ง', 30, true], ['น้ำซุป', 350, true], ['ถุงหูหิ้ว', 1, false],
                ],
                'บะหมี่ หมูหมัก' => [
                    ['บะหมี่', 120, true], ['หมูหมัก', 80, true],
                    ['ผักบุ้ง', 30, true], ['น้ำซุป', 350, true], ['ถุงหูหิ้ว', 1, false],
                ],
                'หมี่ขาว ลูกชิ้น เนื้อสด' => [
                    ['เส้นหมี่ขาว', 120, true], ['ลูกชิ้น', 60, true],
                    ['เนื้อสด', 50, true], ['น้ำซุป', 350, true], ['ถุงหูหิ้ว', 1, false],
                ],
            ];

            foreach ($recipes as $productName => $items) {
                // เมนูเป็นของกลาง หาด้วยชื่ออย่างเดียว แต่สูตรที่สร้างผูกกับสาขานี้
                $product = Product::forCatalog($branch->id)->where('name', $productName)->first();

                if (! $product) {
                    continue;
                }

                $product->update(['track_stock' => true]);

                foreach ($items as [$itemName, $qty, $scales]) {
                    RecipeItem::create([
                        'branch_id' => $branch->id,
                        'product_id' => $product->id,
                        'stock_item_id' => $map[$itemName]->id,
                        'qty' => $qty,
                        'scales_with_portion' => $scales,
                    ]);
                }
            }

            $this->linkModifierRecipes($branch->id, $map);
        }
    }

    /**
     * ของที่ตัวเลือกเพิ่มเข้าไป (หน่วยฐาน)
     *
     * กลุ่ม "ปริมาณ" ไม่ต้องผูกของ เพราะใช้ตัวคูณขนาดแทน
     *
     * @param  array<string, StockItem>  $map
     */
    protected function linkModifierRecipes(int $branchId, array $map): void
    {
        $extras = [
            'เพิ่มเส้น' => ['เส้นเล็ก', 60],
            'เพิ่มเนื้อ' => ['หมูหมัก', 50],
            'เพิ่มลูกชิ้น' => ['ลูกชิ้น', 40],
        ];

        foreach ($extras as $modifierName => [$itemName, $qty]) {
            if (! isset($map[$itemName])) {
                continue;
            }

            $modifiers = Modifier::where('name', $modifierName)
                ->whereHas('group', fn ($q) => $q->forCatalog($branchId))
                ->get();

            foreach ($modifiers as $modifier) {
                ModifierRecipeItem::create([
                    'branch_id' => $branchId,
                    'modifier_id' => $modifier->id,
                    'stock_item_id' => $map[$itemName]->id,
                    'qty' => $qty,
                ]);
            }
        }
    }
}
