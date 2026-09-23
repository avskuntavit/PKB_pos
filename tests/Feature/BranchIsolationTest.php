<?php

namespace Tests\Feature;

use App\Models\BankReconciliation;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ข้อมูลของสาขาหนึ่งต้องไม่รั่วไปอีกสาขา
 *
 * ── ทำไมต้องมีเทสต์ชุดนี้ ──────────────────────────────────
 * `BelongsToBranch` ไม่ได้ใส่ global scope ทุก query จึงต้องกรองสาขาเอง
 * แปลว่าการกันข้อมูลรั่วขึ้นอยู่กับว่าคนเขียนจำได้ทุกจุดหรือเปล่า — ซึ่งจำไม่ได้
 * เทสต์ชุดนี้คือตัวที่จำแทน
 *
 * ── ทำไมมีทั้งเทสต์ "ต้องห้าม" และ "ต้องผ่าน" ────────────────
 * ถ้ามีแต่เทสต์ที่ยืนยันว่า 403 การแก้ให้ทุกอย่าง 403 ก็ผ่านเทสต์ได้หมด
 * ทั้งที่ระบบใช้งานไม่ได้เลย จึงต้องคู่กับเทสต์ที่ยืนยันว่าสาขาตัวเองยังเข้าได้
 *
 * ── เจอรูรั่วจริงตอนเขียนเทสต์ชุดนี้ ────────────────────────
 * `/pos/terminal/{order}` เดิมไม่เช็คสาขาเลย พิมพ์ id ของสาขาอื่นก็เห็นบิลได้
 * ทุก endpoint ที่แก้บิลเช็คอยู่แล้ว แต่หน้าอ่านหลุดไป
 */
class BranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $a;

    protected Branch $b;

    protected function setUp(): void
    {
        parent::setUp();

        // ไม่ต้องพึ่ง public/build ที่อาจยังไม่ได้ build ในเครื่องที่รันเทสต์
        $this->withoutVite();

        $this->a = $this->makeBranch('AA', 'สาขาหนึ่ง');
        $this->b = $this->makeBranch('BB', 'สาขาสอง');
    }

    /* ---------- หน้าขาย ---------- */

    public function test_pos_terminal_refuses_a_bill_from_another_branch(): void
    {
        $this->actingAs($this->makeUser($this->a, 'cashier'));

        $this->get('/pos/terminal/'.$this->makeOrder($this->b)->id)->assertForbidden();
    }

    public function test_pos_terminal_still_opens_a_bill_from_its_own_branch(): void
    {
        $this->actingAs($this->makeUser($this->a, 'cashier'));

        $this->get('/pos/terminal/'.$this->makeOrder($this->a)->id)->assertOk();
    }

    public function test_pos_terminal_opens_with_no_bill_selected(): void
    {
        // route เป็น {order?} — ตอนไม่ส่ง id มา Laravel ยัด model เปล่ามาให้ ไม่ใช่ null
        // ถ้าเช็คผิดจุดนี้ หน้าขายจะเปิดไม่ได้เลยทั้งสาขา
        $this->actingAs($this->makeUser($this->a, 'cashier'));

        $this->get('/pos/terminal')->assertOk();
    }

    public function test_printing_a_receipt_from_another_branch_is_refused(): void
    {
        $this->actingAs($this->makeUser($this->a, 'cashier'));

        $this->get('/pos/orders/'.$this->makeOrder($this->b)->id.'/receipt')->assertForbidden();
    }

    public function test_moving_another_branchs_bill_to_a_table_is_refused(): void
    {
        $this->actingAs($this->makeUser($this->a, 'cashier'));

        $this->post('/pos/orders/'.$this->makeOrder($this->b)->id.'/move-table', [])
            ->assertForbidden();
    }

    /* ---------- หลังบ้าน ---------- */

    public function test_back_office_sale_detail_refuses_another_branch(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));

        $this->get('/backoffice/sales/'.$this->makeOrder($this->b)->id)->assertForbidden();
    }

    public function test_undoing_another_branchs_bank_reconciliation_is_refused(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));

        $record = BankReconciliation::create([
            'branch_id' => $this->b->id,
            'business_date' => '2026-09-21',
            'channel' => 'cash',
        ]);

        $this->post("/backoffice/bank-reconciliation/{$record->id}/undo")->assertForbidden();
    }

    /* ---------- การสลับสาขา ---------- */

    public function test_asking_for_a_branch_you_cannot_access_is_refused(): void
    {
        $this->actingAs($this->makeUser($this->a, 'cashier'));

        $this->get('/pos?branch_id='.$this->b->id)->assertForbidden();
    }

    public function test_a_cashier_can_reach_only_their_own_branch(): void
    {
        $cashier = $this->makeUser($this->a, 'cashier');

        $this->assertSame([$this->a->id], array_values($cashier->accessibleBranchIds()));
    }

    public function test_an_owner_can_reach_every_active_branch(): void
    {
        $owner = $this->makeUser($this->a, 'owner');

        $this->assertEqualsCanonicalizing(
            [$this->a->id, $this->b->id],
            $owner->accessibleBranchIds(),
        );
    }

    /* ---------- แคตตาล็อก ---------- */

    public function test_a_product_of_another_branch_is_not_sellable_here(): void
    {
        $mine = Product::create(['branch_id' => $this->a->id, 'name' => 'ผัดไทยสาขาหนึ่ง', 'price' => 60]);
        $theirs = Product::create(['branch_id' => $this->b->id, 'name' => 'ผัดไทยสาขาสอง', 'price' => 60]);

        $ids = Product::sellableAt($this->a->id)->get()->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, 'เมนูของอีกสาขาต้องไม่โผล่มาขายที่นี่');
    }

    public function test_a_central_product_is_sellable_at_every_branch(): void
    {
        // กับดักที่เคยพลาดมาแล้ว: กรองด้วย where('branch_id', $id) ตรง ๆ
        // จะคัดเมนูกลางออกหมดโดยไม่มี error ให้เห็น
        $central = Product::create(['branch_id' => null, 'name' => 'น้ำเปล่า', 'price' => 10]);

        $this->assertContains($central->id, Product::sellableAt($this->a->id)->get()->pluck('id')->all());
        $this->assertContains($central->id, Product::sellableAt($this->b->id)->get()->pluck('id')->all());
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

    protected function makeOrder(Branch $branch): Order
    {
        return Order::create([
            'branch_id' => $branch->id,
            'order_no' => $branch->code.'0001',
            'business_date' => '2026-09-21',
        ]);
    }
}
