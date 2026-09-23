<?php

namespace Tests\Feature;

use App\Enums\StockTransferStatus;
use App\Models\Branch;
use App\Models\BranchStockItem;
use App\Models\StockItem;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * โอนของระหว่างสถานี
 *
 * ── สิ่งที่เทสต์ชุดนี้คุมเป็นหลัก ─────────────────────────────
 * 1. ของหายจากต้นทางทันทีที่กดส่ง แต่ยังไม่โผล่ที่ปลายทางจนกว่าจะกดรับ
 *    (ระหว่างนั้นของอยู่บนรถ ไม่ได้อยู่ในสต๊อกของใคร)
 * 2. ต้นทุนปลายทางคิดถ่วงน้ำหนักใหม่ ไม่ใช่ค้างค่าเดิมไว้เงียบ ๆ
 * 3. ด่านสิทธิ์แยกตามสิ่งที่จะทำ — ส่ง/ยกเลิกใช้สิทธิ์ต้นทาง รับใช้สิทธิ์ปลายทาง
 */
class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $a;

    protected Branch $b;

    protected StockItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->a = $this->makeBranch('AA', 'ครัวกลาง');
        $this->b = $this->makeBranch('BB', 'สาขาสอง');

        // ของกลาง — โอนได้ เพราะทั้งสองสถานีเห็นของชิ้นเดียวกัน
        $this->item = StockItem::create([
            'branch_id' => null,
            'code' => 'STK001',
            'name' => 'เส้นเล็ก',
            'unit' => 'g',
        ]);

        $this->setStock($this->a, 10000, 0.05);
        $this->setStock($this->b, 2000, 0.10);
    }

    /* ---------- สิทธิ์เข้าหน้า ---------- */

    public function test_a_manager_can_open_the_transfers_page(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));

        $this->get('/backoffice/stock-transfers')->assertOk();
    }

    public function test_a_waiter_cannot_open_the_transfers_page(): void
    {
        $this->actingAs($this->makeUser($this->a, 'staff'));

        $this->get('/backoffice/stock-transfers')->assertForbidden();
    }

    /* ---------- ส่ง ---------- */

    public function test_sending_takes_stock_out_of_the_source_and_leaves_the_destination_alone(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));

        $this->send(3000)->assertRedirect();

        // ของออกจากร้านไปแล้วจริง จึงต้องหายจากต้นทางทันที
        $this->assertEqualsWithDelta(7000, $this->qtyAt($this->a), 0.001);

        // แต่ยังไม่ถึงปลายทาง — ถ้าบวกให้ตอนนี้ ปลายทางจะขายของที่ยังมาไม่ถึง
        $this->assertEqualsWithDelta(2000, $this->qtyAt($this->b), 0.001);

        $transfer = StockTransfer::firstOrFail();
        $this->assertSame(StockTransferStatus::InTransit, $transfer->status);
        $this->assertSame($this->a->id, $transfer->from_branch_id);
        $this->assertSame($this->b->id, $transfer->to_branch_id);
    }

    public function test_sending_more_than_the_branch_has_is_refused(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));

        $this->send(99999)->assertSessionHasErrors('items');

        $this->assertEqualsWithDelta(10000, $this->qtyAt($this->a), 0.001);
        $this->assertSame(0, StockTransfer::count());
    }

    public function test_a_station_specific_stock_item_cannot_be_transferred(): void
    {
        // ปลายทางมองไม่เห็นของชิ้นนี้ตั้งแต่แรก โอนไปก็ใช้ไม่ได้
        $own = StockItem::create([
            'branch_id' => $this->a->id,
            'code' => 'STK900',
            'name' => 'พริกสูตรลับ',
            'unit' => 'g',
        ]);

        BranchStockItem::create([
            'branch_id' => $this->a->id,
            'stock_item_id' => $own->id,
            'stock_qty' => 500,
            'cost_per_unit' => 1,
        ]);

        $this->actingAs($this->makeUser($this->a, 'manager'));

        $this->post('/backoffice/stock-transfers', [
            'to_branch_id' => $this->b->id,
            'items' => [['stock_item_id' => $own->id, 'qty' => 100]],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, StockTransfer::count());
    }

    /* ---------- รับ ---------- */

    public function test_receiving_adds_stock_at_the_destination_with_a_weighted_average_cost(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));
        $this->send(3000);

        $transfer = StockTransfer::firstOrFail();

        $this->actingAs($this->makeUser($this->b, 'manager'));
        $this->post("/backoffice/stock-transfers/{$transfer->id}/receive")->assertRedirect();

        $this->assertEqualsWithDelta(5000, $this->qtyAt($this->b), 0.001);

        /*
        | (2,000 × 0.10 + 3,000 × 0.05) / 5,000 = 0.07
        |
        | ถ้าไม่คิดถ่วงน้ำหนัก ต้นทุนปลายทางจะค้างที่ 0.10 แบบไม่มีใครเห็น
        | แล้วรายงานกำไรของปลายทางจะผิดไปทุกจานที่ใช้ของชิ้นนี้
        */
        $this->assertEqualsWithDelta(0.07, $this->costAt($this->b), 0.0001);

        $this->assertSame(StockTransferStatus::Received, $transfer->fresh()->status);
    }

    public function test_receiving_less_than_was_sent_records_the_shortfall_without_inventing_stock(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));
        $this->send(3000);

        $transfer = StockTransfer::with('items')->firstOrFail();
        $line = $transfer->items->first();

        $this->actingAs($this->makeUser($this->b, 'manager'));
        $this->post("/backoffice/stock-transfers/{$transfer->id}/receive", [
            'received' => [$line->id => 2500],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(4500, $this->qtyAt($this->b), 0.001);
        $this->assertEqualsWithDelta(500, $line->fresh()->shortfall(), 0.001);

        // ของที่หายต้องหายจากระบบจริง ต้นทางไม่ได้คืนมาให้
        $this->assertEqualsWithDelta(7000, $this->qtyAt($this->a), 0.001);
        $this->assertTrue($transfer->fresh()->load('items')->hasShortfall());
    }

    public function test_a_transfer_cannot_be_received_twice(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));
        $this->send(3000);

        $transfer = StockTransfer::firstOrFail();
        $receiver = $this->makeUser($this->b, 'manager');

        $this->actingAs($receiver);
        $this->post("/backoffice/stock-transfers/{$transfer->id}/receive");
        $this->post("/backoffice/stock-transfers/{$transfer->id}/receive")->assertSessionHasErrors('status');

        // รับซ้ำแล้วของต้องไม่เข้าสองรอบ
        $this->assertEqualsWithDelta(5000, $this->qtyAt($this->b), 0.001);
    }

    /* ---------- ยกเลิก ---------- */

    public function test_cancelling_puts_the_stock_back_at_the_source(): void
    {
        $sender = $this->makeUser($this->a, 'manager');
        $this->actingAs($sender);
        $this->send(3000);

        $transfer = StockTransfer::firstOrFail();

        $this->post("/backoffice/stock-transfers/{$transfer->id}/cancel", ['reason' => 'รถเสีย'])
            ->assertRedirect();

        $this->assertEqualsWithDelta(10000, $this->qtyAt($this->a), 0.001);
        $this->assertEqualsWithDelta(0.05, $this->costAt($this->a), 0.0001);
        $this->assertEqualsWithDelta(2000, $this->qtyAt($this->b), 0.001);
        $this->assertSame(StockTransferStatus::Cancelled, $transfer->fresh()->status);
    }

    /* ---------- ด่านกันข้ามสถานี ---------- */

    public function test_only_the_destination_can_receive(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));
        $this->send(3000);

        $transfer = StockTransfer::firstOrFail();

        // ต้นทางกดรับแทนปลายทางไม่ได้ ไม่งั้นของเข้าสต๊อกทั้งที่ยังไม่มีใครเห็นของ
        $this->post("/backoffice/stock-transfers/{$transfer->id}/receive")->assertForbidden();

        $this->assertEqualsWithDelta(2000, $this->qtyAt($this->b), 0.001);
    }

    public function test_only_the_source_can_cancel(): void
    {
        $this->actingAs($this->makeUser($this->a, 'manager'));
        $this->send(3000);

        $transfer = StockTransfer::firstOrFail();

        $this->actingAs($this->makeUser($this->b, 'manager'));
        $this->post("/backoffice/stock-transfers/{$transfer->id}/cancel")->assertForbidden();

        $this->assertSame(StockTransferStatus::InTransit, $transfer->fresh()->status);
    }

    public function test_an_owner_can_act_for_both_stations(): void
    {
        // เจ้าของเห็นทุกสถานี จึงทั้งส่งและรับเองได้ ต่างจากผู้จัดการ
        $owner = $this->makeUser($this->a, 'owner');

        $this->actingAs($owner);
        $this->send(1000)->assertRedirect();

        $transfer = StockTransfer::firstOrFail();

        $this->post("/backoffice/stock-transfers/{$transfer->id}/receive")->assertRedirect();

        $this->assertEqualsWithDelta(3000, $this->qtyAt($this->b), 0.001);
    }

    /* ---------- ตัวช่วย ---------- */

    protected function send(float $qty)
    {
        return $this->post('/backoffice/stock-transfers', [
            'to_branch_id' => $this->b->id,
            'items' => [['stock_item_id' => $this->item->id, 'qty' => $qty]],
        ]);
    }

    protected function setStock(Branch $branch, float $qty, float $cost): void
    {
        BranchStockItem::create([
            'branch_id' => $branch->id,
            'stock_item_id' => $this->item->id,
            'stock_qty' => $qty,
            'cost_per_unit' => $cost,
        ]);
    }

    protected function qtyAt(Branch $branch): float
    {
        return (float) BranchStockItem::where('branch_id', $branch->id)
            ->where('stock_item_id', $this->item->id)
            ->value('stock_qty');
    }

    protected function costAt(Branch $branch): float
    {
        return (float) BranchStockItem::where('branch_id', $branch->id)
            ->where('stock_item_id', $this->item->id)
            ->value('cost_per_unit');
    }

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
