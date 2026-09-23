<?php

namespace Tests\Feature;

use App\Enums\DietTag;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ป้ายข้อมูลอาหารในหลังบ้าน (gap-analysis 3.2)
 *
 * ── สิ่งที่เทสต์ชุดนี้คุมเป็นหลัก ───────────────────────────────────────
 * 1. ติ๊กออกหมดแล้วกดบันทึก ต้องล้างป้ายจริง — ฟอร์มส่งแบบ multipart
 *    ซึ่งแทน "อาร์เรย์ว่าง" ไม่ได้ ถ้าอ่านผิดจะเอาป้ายออกไม่ได้เลยตลอดชีวิตของเมนูนั้น
 *    และป้ายที่เอาออกไม่ได้เป็นเรื่องใหญ่กับกลุ่ม "ต้องระวัง" (เมนูเปลี่ยนสูตรจนไม่มีถั่วแล้ว
 *    แต่ยังเขียนว่ามีถั่ว = ลูกค้าที่แพ้ถั่วถูกกันออกจากเมนูที่กินได้)
 * 2. ค่าที่ระบบไม่รู้จักต้องไม่หลุดลงฐานข้อมูล เพราะหน้าเมนูของลูกค้าอ่านคอลัมน์นี้ตรง ๆ
 */
class ProductDietTagTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = Branch::create([
            'code' => 'AA',
            'name' => 'สาขาหนึ่ง',
            'vat_rate' => 7,
            'vat_included' => true,
            'service_charge_rate' => 0,
            'rounding_mode' => 0,
            'business_day_start' => '05:00:00',
        ]);

        $this->manager = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'ผู้จัดการ',
            'email' => 'manager@test.local',
            'password' => 'password',
            'role' => 'manager',
        ]);

        $this->actingAs($this->manager);
    }

    /* ---------- ตัวเลือกไปถึงหน้าจอ ---------- */

    public function test_the_products_page_receives_the_tag_options_grouped(): void
    {
        $this->get('/backoffice/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('dietTags', 3)
                ->where('dietTags.0.kind', 'heat')
                ->has('dietTags.0.tags')
                // คำเตือนของกลุ่ม "ต้องระวัง" ต้องไปถึงคนที่กำลังติ๊ก ไม่ใช่อยู่แต่ในคอมเมนต์ของโค้ด
                ->where('dietTags.2.hint', DietTag::kindHint('allergen'))
            );
    }

    /* ---------- บันทึก ---------- */

    public function test_a_new_product_can_be_saved_with_tags(): void
    {
        $this->post('/backoffice/products', [
            'name' => 'ส้มตำ',
            'price' => 60,
            'diet_tags' => ['very_spicy', 'contains_nut'],
        ])->assertRedirect();

        $product = Product::where('name', 'ส้มตำ')->firstOrFail();

        $this->assertSame(['very_spicy', 'contains_nut'], $product->diet_tags);
    }

    public function test_tags_are_replaced_not_merged_when_editing(): void
    {
        $product = $this->makeProduct(['spicy', 'contains_seafood']);

        $this->put('/backoffice/products/'.$product->id, [
            'name' => $product->name,
            'price' => 50,
            'diet_tags' => ['vegan'],
        ])->assertRedirect();

        $this->assertSame(['vegan'], $product->fresh()->diet_tags);
    }

    public function test_unticking_every_tag_actually_clears_them(): void
    {
        /*
        | นี่คือเคสที่พังเงียบที่สุดถ้าทำผิด
        |
        | ฟอร์มส่งแบบ multipart เพราะมีไฟล์รูป และ FormData ไม่มีทางแทนอาร์เรย์ว่างได้
        | ติ๊กออกหมดแล้วกดบันทึก คีย์ diet_tags จะหายไปทั้งคีย์ — เหมือนที่เทสต์นี้จำลอง
        */
        $product = $this->makeProduct(['spicy', 'contains_nut']);

        $this->put('/backoffice/products/'.$product->id, [
            'name' => $product->name,
            'price' => 50,
        ])->assertRedirect();

        $this->assertNull($product->fresh()->diet_tags, 'ติ๊กออกหมดแล้วต้องล้างจริง ไม่ใช่เก็บของเดิมไว้');
        $this->assertSame([], $product->fresh()->dietTags());
    }

    public function test_the_same_tag_sent_twice_is_stored_once(): void
    {
        $product = $this->makeProduct([]);

        $this->put('/backoffice/products/'.$product->id, [
            'name' => $product->name,
            'price' => 50,
            'diet_tags' => ['spicy', 'spicy', 'halal'],
        ])->assertRedirect();

        $this->assertSame(['spicy', 'halal'], $product->fresh()->diet_tags);
    }

    /* ---------- ค่าที่ไม่ควรผ่าน ---------- */

    public function test_an_unknown_tag_is_rejected_and_nothing_is_saved(): void
    {
        $product = $this->makeProduct(['spicy']);

        $this->put('/backoffice/products/'.$product->id, [
            'name' => $product->name,
            'price' => 50,
            'diet_tags' => ['spicy', 'เผ็ดนิดหน่อย'],
        ])->assertSessionHasErrors('diet_tags.1');

        $this->assertSame(['spicy'], $product->fresh()->diet_tags, 'คำขอที่ไม่ผ่านต้องไม่แก้ของเดิมเลย');
    }

    public function test_more_tags_than_exist_is_rejected(): void
    {
        $product = $this->makeProduct([]);

        $this->put('/backoffice/products/'.$product->id, [
            'name' => $product->name,
            'price' => 50,
            'diet_tags' => array_fill(0, 10, 'spicy'),
        ])->assertSessionHasErrors('diet_tags');
    }

    /* ---------- ต่อกับหน้าลูกค้า ---------- */

    public function test_saved_tags_come_back_in_the_shape_the_menu_page_expects(): void
    {
        $product = $this->makeProduct(['spicy', 'contains_nut']);

        $tags = $product->dietTags();

        $this->assertCount(2, $tags);
        $this->assertSame('เผ็ด', $tags[0]->label());
        $this->assertSame('heat', $tags[0]->kind());
        $this->assertSame('allergen', $tags[1]->kind());
    }

    public function test_junk_left_in_the_column_never_reaches_the_menu(): void
    {
        // แถวที่ถูกแก้มือในฐานข้อมูล หรือค้างมาจากรุ่นก่อน ต้องไม่ทำให้หน้าเมนูพัง
        $product = $this->makeProduct([]);
        $product->forceFill(['diet_tags' => ['spicy', 'ของเก่าที่เลิกใช้']])->save();

        $tags = $product->fresh()->dietTags();

        $this->assertCount(1, $tags);
        $this->assertSame(DietTag::Spicy, $tags[0]);
    }

    /* ---------- ตัวช่วย ---------- */

    /** @param  array<int, string>  $tags */
    protected function makeProduct(array $tags): Product
    {
        return Product::create([
            'branch_id' => $this->branch->id,
            'name' => 'หมี่ขาว',
            'price' => 50,
            'diet_tags' => $tags ?: null,
        ]);
    }
}
