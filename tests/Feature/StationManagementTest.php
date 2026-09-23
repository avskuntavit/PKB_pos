<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchImage;
use App\Models\BranchProduct;
use App\Models\BranchStockItem;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * หน้าจัดการสถานี — เปิดสถานีใหม่ ปิดสถานีเก่า และข้อมูลแบรนด์
 *
 * ── สิ่งที่เทสต์ชุดนี้คุมเป็นหลัก ─────────────────────────────
 * 1. สถานีใหม่ที่เปิดแล้ว "ใช้งานได้จริง" ไม่ใช่เปลือกเปล่า —
 *    สูตรและต้นทุนต้องตามมา แต่ยอดคงเหลือต้องเป็น 0
 * 2. การคัดลอกต้องไม่ลากของเฉพาะสถานีต้นทางข้ามมา
 * 3. ปิดสถานีแล้วต้องไม่ทำให้เจ้าของระบบล็อกตัวเองออกจากหลังบ้าน
 */
class StationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->a = $this->makeBranch('AA', 'สาขาหนึ่ง');
    }

    /* ---------- สิทธิ์ ---------- */

    public function test_owner_can_open_the_stations_page(): void
    {
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $this->get('/backoffice/stations')->assertOk();
    }

    public function test_a_manager_cannot_open_the_stations_page(): void
    {
        // ผู้จัดการเข้าหลังบ้านได้ แต่โครงสร้างของกิจการไม่ใช่ของเขา
        $this->actingAs($this->makeUser($this->a, 'manager'));

        $this->get('/backoffice/stations')->assertForbidden();
    }

    public function test_a_manager_cannot_open_a_new_station(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));

        $this->post('/backoffice/stations', ['code' => 'BB', 'name' => 'สาขาสอง'])->assertForbidden();

        $this->assertDatabaseMissing('branches', ['code' => 'BB']);
    }

    /* ---------- เปิดสถานีใหม่ ---------- */

    public function test_owner_opens_a_station_without_copying_anything(): void
    {
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $this->post('/backoffice/stations', [
            'code' => 'BB',
            'name' => 'สาขาสอง',
            'store_type' => 'noodle_shop',
            'theme_color' => '#ff8800',
        ])->assertRedirect('/backoffice/stations');

        $this->assertDatabaseHas('branches', [
            'code' => 'BB',
            'name' => 'สาขาสอง',
            'store_type' => 'noodle_shop',
            'theme_color' => '#ff8800',
            'is_active' => true,
        ]);
    }

    public function test_a_station_code_with_thai_letters_or_spaces_is_refused(): void
    {
        // รหัสสถานีอยู่ใน URL ที่ลูกค้าเปิด และเคยทำให้ชื่อไฟล์ส่งออกบัญชีชนกันมาแล้ว
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $this->post('/backoffice/stations', ['code' => 'สาขาสอง', 'name' => 'สาขาสอง'])
            ->assertSessionHasErrors('code');

        $this->post('/backoffice/stations', ['code' => 'BR 02', 'name' => 'สาขาสอง'])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Branch::count());
    }

    public function test_a_duplicate_station_code_is_refused(): void
    {
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $this->post('/backoffice/stations', ['code' => 'AA', 'name' => 'ซ้ำ'])
            ->assertSessionHasErrors('code');
    }

    /* ---------- การคัดลอกตอนเปิดสถานี ---------- */

    public function test_recipes_and_costs_follow_the_new_station_but_stock_starts_at_zero(): void
    {
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $product = Product::create(['branch_id' => null, 'name' => 'ก๋วยเตี๋ยว', 'price' => 60, 'track_stock' => true]);
        $item = StockItem::create(['branch_id' => null, 'code' => 'STK001', 'name' => 'เส้นเล็ก', 'unit' => 'g']);

        RecipeItem::create([
            'branch_id' => $this->a->id,
            'product_id' => $product->id,
            'stock_item_id' => $item->id,
            'qty' => 120,
            'scales_with_portion' => true,
        ]);

        BranchStockItem::create([
            'branch_id' => $this->a->id,
            'stock_item_id' => $item->id,
            'stock_qty' => 30000,
            'cost_per_unit' => 0.045,
            'reorder_level' => 5000,
        ]);

        $this->post('/backoffice/stations', [
            'code' => 'BB',
            'name' => 'สาขาสอง',
            'copy_from' => $this->a->id,
            'copy' => ['recipes'],
        ])->assertRedirect();

        $new = Branch::where('code', 'BB')->firstOrFail();

        $this->assertDatabaseHas('recipe_items', [
            'branch_id' => $new->id,
            'product_id' => $product->id,
            'stock_item_id' => $item->id,
        ]);

        $level = BranchStockItem::where('branch_id', $new->id)
            ->where('stock_item_id', $item->id)
            ->firstOrFail();

        // ต้นทุนคัดลอกมาได้ — สถานีใหม่ซื้อของจากเจ้าเดิมราคาใกล้กัน
        $this->assertEquals(0.045, (float) $level->cost_per_unit);
        $this->assertEquals(5000, (float) $level->reorder_level);

        // แต่ของจริงในมือคือศูนย์ ถ้าคัดลอกมาจะได้สต๊อกผีที่ตัดจนติดลบโดยไม่มีใครเอะใจ
        $this->assertEquals(0, (float) $level->stock_qty, 'ยอดคงเหลือของสถานีใหม่ต้องเริ่มที่ 0');
    }

    public function test_things_that_belong_only_to_the_source_station_are_not_copied(): void
    {
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $central = Product::create(['branch_id' => null, 'name' => 'น้ำเปล่า', 'price' => 10]);
        $ownOnly = Product::create(['branch_id' => $this->a->id, 'name' => 'เมนูลับสาขาหนึ่ง', 'price' => 99]);

        BranchProduct::create(['branch_id' => $this->a->id, 'product_id' => $central->id, 'price' => 12]);
        BranchProduct::create(['branch_id' => $this->a->id, 'product_id' => $ownOnly->id, 'price' => 88]);

        $this->post('/backoffice/stations', [
            'code' => 'BB',
            'name' => 'สาขาสอง',
            'copy_from' => $this->a->id,
            'copy' => ['menu_overrides'],
        ])->assertRedirect();

        $new = Branch::where('code', 'BB')->firstOrFail();
        $copied = BranchProduct::where('branch_id', $new->id)->pluck('product_id')->all();

        $this->assertSame([$central->id], $copied, 'เมนูเฉพาะสถานีต้นทางต้องไม่ถูกลากข้ามมา');
    }

    public function test_copied_tables_get_brand_new_qr_tokens(): void
    {
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $zone = Zone::create(['branch_id' => $this->a->id, 'name' => 'ชั้น 1', 'sort_order' => 1]);
        $table = DiningTable::create(['branch_id' => $this->a->id, 'zone_id' => $zone->id, 'name' => 'A1', 'seats' => 4]);

        $this->post('/backoffice/stations', [
            'code' => 'BB',
            'name' => 'สาขาสอง',
            'copy_from' => $this->a->id,
            'copy' => ['tables'],
        ])->assertRedirect();

        $new = Branch::where('code', 'BB')->firstOrFail();
        $copy = DiningTable::where('branch_id', $new->id)->firstOrFail();

        $this->assertSame('A1', $copy->name);
        // QR ของสถานีเดิมยังติดอยู่บนโต๊ะจริง ถ้าซ้ำกันลูกค้าจะถูกพาไปผิดสถานี
        $this->assertNotSame($table->qr_token, $copy->qr_token);
        $this->assertNotNull($copy->qr_token);

        // โซนต้องถูกสร้างใหม่และชี้ไป id ใหม่ ไม่ใช่โซนของสถานีต้นทาง
        $this->assertNotSame($zone->id, $copy->zone_id);
        $this->assertSame($new->id, Zone::find($copy->zone_id)->branch_id);
    }

    /* ---------- เปิด/ปิดใช้งาน ---------- */

    public function test_the_last_active_station_cannot_be_switched_off(): void
    {
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $this->post('/backoffice/stations/'.$this->a->id.'/active')->assertRedirect();

        $this->assertTrue($this->a->fresh()->is_active, 'ปิดสถานีสุดท้ายไม่ได้');
    }

    public function test_a_station_with_open_bills_cannot_be_switched_off(): void
    {
        $b = $this->makeBranch('BB', 'สาขาสอง');
        $this->actingAs($this->makeUser($this->a, 'owner'));

        Order::create(['branch_id' => $b->id, 'order_no' => 'BB0001', 'business_date' => '2026-09-21']);

        $this->post('/backoffice/stations/'.$b->id.'/active')->assertRedirect();

        $this->assertTrue($b->fresh()->is_active, 'ปิดสถานีที่ยังมีบิลค้างไม่ได้');
    }

    public function test_switching_off_the_station_you_are_viewing_moves_you_somewhere_else(): void
    {
        /*
        | accessibleBranchIds() คืนเฉพาะสถานีที่ยัง is_active
        | ถ้าไม่ย้าย session ให้ request ถัดไปจะโดน ResolveCurrentBranch เด้ง 403
        | แล้วเจ้าของระบบเข้าหลังบ้านไม่ได้เลยจนกว่าจะล้าง session
        */
        $b = $this->makeBranch('BB', 'สาขาสอง');
        $this->actingAs($this->makeUser($b, 'owner'));

        $response = $this->withSession(['branch_id' => $b->id])
            ->post('/backoffice/stations/'.$b->id.'/active');

        $response->assertRedirect();
        $response->assertSessionHas('branch_id', $this->a->id);

        $this->assertFalse($b->fresh()->is_active);
    }

    public function test_an_owner_whose_home_station_was_switched_off_can_still_use_the_back_office(): void
    {
        /*
        | ต่อจากเทสต์ข้างบน แต่เป็นคนละ session (เช่นล็อกอินจากอีกเครื่อง)
        | users.branch_id ยังชี้สาขาที่ถูกปิดไปแล้ว ถ้า middleware เด้ง 403 ตรงนี้
        | เจ้าของร้านจะเข้าหลังบ้านไม่ได้เลยและแก้เองไม่ได้ด้วย
        |
        | ค่าที่ "ตกยุคได้เอง" ต้องถอยไปสาขาที่เข้าได้ ต่างจาก ?branch_id
        | ที่เป็นเจตนา ณ ตอนนั้นและต้อง 403 เสมอ (คุมไว้ที่ BranchIsolationTest)
        */
        $b = $this->makeBranch('BB', 'สาขาสอง');
        $b->update(['is_active' => false]);

        $this->actingAs($this->makeUser($b, 'owner'));

        $this->get('/backoffice/stations')->assertOk();
    }

    public function test_a_switched_off_station_can_be_switched_back_on(): void
    {
        $b = $this->makeBranch('BB', 'สาขาสอง');
        $b->update(['is_active' => false]);

        $this->actingAs($this->makeUser($this->a, 'owner'));

        $this->post('/backoffice/stations/'.$b->id.'/active')->assertRedirect();

        $this->assertTrue($b->fresh()->is_active);
    }

    /* ---------- ข้อมูลแบรนด์ ---------- */

    public function test_the_station_code_cannot_be_changed_after_it_is_created(): void
    {
        // รหัสอยู่ใน QR บนโต๊ะและลิงก์ที่แจกออกไปแล้ว เปลี่ยนแล้วของเก่าพังเงียบ ๆ
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $this->put('/backoffice/stations/'.$this->a->id, [
            'name' => 'สาขาหนึ่ง ปรับปรุงใหม่',
            'code' => 'ZZ',
        ])->assertRedirect();

        $this->a->refresh();

        $this->assertSame('AA', $this->a->code);
        $this->assertSame('สาขาหนึ่ง ปรับปรุงใหม่', $this->a->name);
    }

    public function test_coordinates_outside_the_map_are_refused(): void
    {
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $this->put('/backoffice/stations/'.$this->a->id, [
            'name' => 'สาขาหนึ่ง',
            'latitude' => 999,
            'longitude' => 100.5,
        ])->assertSessionHasErrors('latitude');
    }

    /* ---------- รูปบรรยากาศร้าน ---------- */

    public function test_owner_adds_and_removes_a_gallery_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->makeUser($this->a, 'owner'));

        $this->post('/backoffice/stations/'.$this->a->id.'/images', [
            'image' => UploadedFile::fake()->image('front.jpg', 800, 600),
            'caption' => 'หน้าร้าน',
        ])->assertRedirect();

        $image = BranchImage::where('branch_id', $this->a->id)->firstOrFail();
        $this->assertSame('หน้าร้าน', $image->caption);

        $this->delete('/backoffice/stations/'.$this->a->id.'/images/'.$image->id)->assertRedirect();

        $this->assertDatabaseMissing('branch_images', ['id' => $image->id]);
    }

    public function test_an_image_cannot_be_deleted_through_another_station(): void
    {
        $b = $this->makeBranch('BB', 'สาขาสอง');
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $image = BranchImage::create(['branch_id' => $b->id, 'path' => '/storage/branches/x.jpg', 'sort_order' => 0]);

        $this->delete('/backoffice/stations/'.$this->a->id.'/images/'.$image->id)->assertForbidden();

        $this->assertDatabaseHas('branch_images', ['id' => $image->id]);
    }

    public function test_reordering_only_touches_images_of_that_station(): void
    {
        $b = $this->makeBranch('BB', 'สาขาสอง');
        $this->actingAs($this->makeUser($this->a, 'owner'));

        $mine = BranchImage::create(['branch_id' => $this->a->id, 'path' => '/storage/branches/a.jpg', 'sort_order' => 5]);
        $theirs = BranchImage::create(['branch_id' => $b->id, 'path' => '/storage/branches/b.jpg', 'sort_order' => 9]);

        $this->put('/backoffice/stations/'.$this->a->id.'/images/order', [
            'order' => [$theirs->id, $mine->id],
        ])->assertRedirect();

        $this->assertSame(1, $mine->fresh()->sort_order);
        $this->assertSame(9, $theirs->fresh()->sort_order, 'รูปของสถานีอื่นต้องไม่ถูกย้าย');
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

    protected function makeUser(Branch $branch, string $role): User
    {
        return User::create([
            'branch_id' => $branch->id,
            'name' => "ผู้ใช้ {$role} {$branch->code}",
            'email' => strtolower($role).'.'.strtolower($branch->code).'@test.local',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
