<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Enums\StockUnit;
use App\Models\Branch;
use App\Models\BranchStockItem;
use App\Models\StockItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ของในคลังเป็นแม่แบบกลาง แต่ยอดคงเหลือแยกรายสาขา
 *
 * ── สองอย่างนี้ต้องจริงพร้อมกัน ────────────────────────────
 * ถ้าของไม่กลาง เปิดสาขาใหม่ทีต้องคีย์รายการของทั้งคลังใหม่
 * ถ้าสต๊อกไม่แยก สาขาที่สองจะทับยอดของสาขาแรกทันทีที่รับของ
 *
 * เทสต์ชุดนี้จับทั้งสองฝั่ง เพราะการแก้ให้ฝั่งหนึ่งถูกแล้วอีกฝั่งพังเป็นเรื่องที่เกิดง่าย
 */
class CentralStockTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $a;

    protected Branch $b;

    protected StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeBranch('AA', 'สาขาหนึ่ง');
        $this->b = $this->makeBranch('BB', 'สาขาสอง');
        $this->stock = app(StockService::class);
    }

    /* ---------- แม่แบบกลาง ---------- */

    public function test_a_central_item_is_usable_at_every_branch(): void
    {
        $central = $this->makeItem('น้ำซุป', branch: null);

        $this->assertContains($central->id, StockItem::forCatalog($this->a->id)->pluck('id')->all());
        $this->assertContains($central->id, StockItem::forCatalog($this->b->id)->pluck('id')->all());
    }

    public function test_an_item_that_belongs_to_one_branch_is_not_visible_at_another(): void
    {
        $mine = $this->makeItem('เส้นพิเศษของสาขาหนึ่ง', branch: $this->a);

        $this->assertContains($mine->id, StockItem::forCatalog($this->a->id)->pluck('id')->all());
        $this->assertNotContains($mine->id, StockItem::forCatalog($this->b->id)->pluck('id')->all());
    }

    public function test_two_central_items_cannot_share_a_code(): void
    {
        /*
        | SQL ถือว่า NULL ไม่เท่ากับ NULL — unique(branch_id, code) จึงกันรหัสซ้ำ
        | ของของกลางไม่ได้เลย คอลัมน์เงา branch_key (NULL -> 0) มีไว้แก้ข้อนี้
        | ถ้าวันหนึ่งมีคนถอด hook ที่เติม branch_key ออก เทสต์นี้จะจับได้
        */
        $this->makeItem('ผงชูรส', branch: null, code: 'STK001');

        $this->expectException(QueryException::class);

        $this->makeItem('ผงชูรสอีกตัว', branch: null, code: 'STK001');
    }

    public function test_a_branch_item_may_reuse_a_central_code(): void
    {
        // คนละ branch_key จึงไม่ชนกัน — สาขาตั้งรหัสของตัวเองได้โดยไม่ต้องรู้ว่ากลางใช้อะไรไปบ้าง
        $this->makeItem('น้ำตาลกลาง', branch: null, code: 'SUGAR');
        $mine = $this->makeItem('น้ำตาลของสาขาหนึ่ง', branch: $this->a, code: 'SUGAR');

        $this->assertSame($this->a->id, $mine->branch_id);
        $this->assertSame($this->a->id, (int) $mine->branch_key);
    }

    /* ---------- ยอดคงเหลือแยกสาขา ---------- */

    public function test_stock_is_separate_per_branch(): void
    {
        $item = $this->makeItem('หมูหมัก', branch: null);

        $this->stock->move($item, $this->a->id, StockMovementType::Adjust, 1000);

        $this->assertSame(1000.0, $item->qtyAt($this->a->id));
        $this->assertSame(0.0, $item->qtyAt($this->b->id), 'รับของที่สาขาหนึ่ง ต้องไม่ไปเพิ่มให้สาขาสอง');

        $this->stock->move($item, $this->b->id, StockMovementType::Adjust, 250);

        $this->assertSame(1000.0, $item->qtyAt($this->a->id));
        $this->assertSame(250.0, $item->qtyAt($this->b->id));
    }

    public function test_selling_deducts_only_the_branch_that_sold_it(): void
    {
        $item = $this->makeItem('เส้นเล็ก', branch: null);

        $this->stock->move($item, $this->a->id, StockMovementType::Adjust, 500);
        $this->stock->move($item, $this->b->id, StockMovementType::Adjust, 500);

        $this->stock->move($item, $this->a->id, StockMovementType::Usage, -120);

        $this->assertSame(380.0, $item->qtyAt($this->a->id));
        $this->assertSame(500.0, $item->qtyAt($this->b->id));
    }

    public function test_weighted_average_cost_is_separate_per_branch(): void
    {
        $item = $this->makeItem('เนื้อสด', branch: null);

        // สาขาหนึ่งซื้อ 100 หน่วยที่ 10 บาท แล้วซื้ออีก 100 ที่ 20 บาท -> เฉลี่ย 15
        $this->stock->move($item, $this->a->id, StockMovementType::Purchase, 100, 10);
        $this->stock->move($item, $this->a->id, StockMovementType::Purchase, 100, 20);

        // สาขาสองซื้อ 100 หน่วยที่ 30 บาท -> เฉลี่ย 30 ไม่เกี่ยวกับสาขาหนึ่ง
        $this->stock->move($item, $this->b->id, StockMovementType::Purchase, 100, 30);

        $this->assertSame(15.0, $item->costAt($this->a->id));
        $this->assertSame(30.0, $item->costAt($this->b->id), 'ของชิ้นเดียวกันคนละสาขาซื้อมาคนละราคาได้');
    }

    public function test_the_first_movement_creates_the_branch_row_by_itself(): void
    {
        $item = $this->makeItem('ผักบุ้ง', branch: null);

        $this->assertDatabaseMissing('branch_stock_items', [
            'branch_id' => $this->a->id,
            'stock_item_id' => $item->id,
        ]);

        $this->stock->move($item, $this->a->id, StockMovementType::Adjust, 10);

        $this->assertDatabaseHas('branch_stock_items', [
            'branch_id' => $this->a->id,
            'stock_item_id' => $item->id,
        ]);
    }

    /* ---------- หน่วยซื้อ ---------- */

    public function test_receiving_in_purchase_units_converts_to_base_units(): void
    {
        // ซื้อเป็น กก. 1 กก. = 1000 กรัม — กรอก 2 กก. ต้องได้ 2000 กรัม และราคาต่อกรัมถูกหารให้
        $item = $this->makeItem('เส้นหมี่', branch: null, purchaseUnit: 'กก.', purchaseFactor: 1000);

        $this->stock->receive($item, $this->a->id, 2, costPerPurchaseUnit: 45);

        $this->assertSame(2000.0, $item->qtyAt($this->a->id));
        $this->assertSame(0.045, $item->costAt($this->a->id));
    }

    /* ---------- ร่องรอย ---------- */

    public function test_a_movement_records_the_branch_it_was_told_not_the_items_branch(): void
    {
        /*
        | เมื่อก่อน branch_id ของความเคลื่อนไหวอ่านจาก $ingredient->branch_id ได้
        | เพราะวัตถุดิบผูกสาขาอยู่แล้ว ตอนนี้ของเป็นกลาง (branch_id = NULL)
        | ถ้าใครเผลอกลับไปอ่านจากตัวของ ความเคลื่อนไหวทุกแถวจะไม่มีสาขา
        */
        $item = $this->makeItem('น้ำแข็ง', branch: null);

        $this->stock->move($item, $this->b->id, StockMovementType::Adjust, 5);

        $movement = StockMovement::where('stock_item_id', $item->id)->firstOrFail();

        $this->assertSame($this->b->id, $movement->branch_id);
        $this->assertSame(5.0, (float) $movement->balance_after);
    }

    /* ---------- สูตรและต้นทุน ---------- */

    public function test_the_recipe_of_one_branch_does_not_leak_into_another(): void
    {
        /*
        | สูตรยังแยกรายสาขาโดยตั้งใจ — ปริมาณต่อจานเป็นเรื่องของครัวแต่ละที่
        | แต่ทั้งสองสาขาชี้ไปของกลางชิ้นเดียวกันได้แล้ว ไม่ต้องสร้าง "เส้นเล็ก" ซ้ำ
        */
        $noodle = $this->makeItem('เส้นเล็ก', branch: null);
        $product = Product::create(['branch_id' => null, 'name' => 'ก๋วยเตี๋ยว', 'price' => 50, 'track_stock' => true]);

        RecipeItem::create([
            'branch_id' => $this->a->id, 'product_id' => $product->id,
            'stock_item_id' => $noodle->id, 'qty' => 120,
        ]);
        RecipeItem::create([
            'branch_id' => $this->b->id, 'product_id' => $product->id,
            'stock_item_id' => $noodle->id, 'qty' => 150,
        ]);

        $this->assertSame([$noodle->id => 120.0], $this->stock->usageFor($product, [], $this->a->id));
        $this->assertSame([$noodle->id => 150.0], $this->stock->usageFor($product, [], $this->b->id));
    }

    public function test_unit_cost_uses_the_cost_of_the_branch_that_is_selling(): void
    {
        $noodle = $this->makeItem('เส้นเล็ก', branch: null);
        $product = Product::create(['branch_id' => null, 'name' => 'ก๋วยเตี๋ยว', 'price' => 50, 'track_stock' => true]);

        RecipeItem::create([
            'branch_id' => $this->a->id, 'product_id' => $product->id,
            'stock_item_id' => $noodle->id, 'qty' => 100,
        ]);
        RecipeItem::create([
            'branch_id' => $this->b->id, 'product_id' => $product->id,
            'stock_item_id' => $noodle->id, 'qty' => 100,
        ]);

        // สาขาหนึ่งซื้อมาหน่วยละ 0.05 สาขาสองซื้อมาหน่วยละ 0.10
        $this->stock->move($noodle, $this->a->id, StockMovementType::Purchase, 1000, 0.05);
        $this->stock->move($noodle, $this->b->id, StockMovementType::Purchase, 1000, 0.10);

        $this->assertSame(5.0, $this->stock->unitCostForSelection($product, [], $this->a->id));
        $this->assertSame(10.0, $this->stock->unitCostForSelection($product, [], $this->b->id));
    }

    public function test_items_that_do_not_scale_with_portion_stay_at_one(): void
    {
        $bag = $this->makeItem('ถุงหูหิ้ว', branch: null);
        $product = Product::create(['branch_id' => null, 'name' => 'ข้าวกล่อง', 'price' => 50, 'track_stock' => true]);

        RecipeItem::create([
            'branch_id' => $this->a->id, 'product_id' => $product->id,
            'stock_item_id' => $bag->id, 'qty' => 1, 'scales_with_portion' => false,
        ]);

        // สั่ง 3 กล่อง = 3 ถุง แต่ถ้ามีตัวคูณขนาดก็ยังเป็น 3 ไม่ใช่ 6
        $this->assertSame([$bag->id => 3.0], $this->stock->usageFor($product, [], $this->a->id, 3));
    }

    /* ---------- ตัวช่วย ---------- */

    protected function makeBranch(string $code, string $name): Branch
    {
        return Branch::create([
            'code' => $code,
            'name' => $name,
            'vat_rate' => 7,
            'vat_included' => true,
            'service_charge_rate' => 0,
            'rounding_mode' => 0,
            'business_day_start' => '05:00:00',
        ]);
    }

    protected function makeItem(
        string $name,
        ?Branch $branch,
        ?string $code = null,
        ?string $purchaseUnit = null,
        float $purchaseFactor = 1,
    ): StockItem {
        return StockItem::create([
            'branch_id' => $branch?->id,
            'code' => $code,
            'name' => $name,
            'unit' => StockUnit::Gram,
            'purchase_unit' => $purchaseUnit,
            'purchase_factor' => $purchaseFactor,
        ]);
    }
}
