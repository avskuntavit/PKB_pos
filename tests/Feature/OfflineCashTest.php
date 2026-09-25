<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\StockUnit;
use App\Models\Branch;
use App\Models\Category;
use App\Models\OfflineSyncEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PeriodLockService;
use App\Services\StockService;
use App\Enums\StockMovementType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * รับเงินสดตอนเน็ตหลุด — offline เฟส 3
 *
 * ── สิ่งที่เทสต์ชุดนี้คุมเป็นหลัก ───────────────────────────────────────
 * 1. **ส่งคิวเดิมซ้ำต้องไม่ตัดเงินสองรอบ** — ความเสียหายที่แก้ย้อนหลังยากที่สุด
 * 2. ใบรับเงินที่ลงบิลไม่ได้ต้องกลายเป็น held **ไม่ใช่ failed** เพราะเงินอยู่ในลิ้นชักแล้ว
 *    พนักงานทำอะไรกับมันไม่ได้ ต้องเป็นงานของผู้จัดการ
 * 3. ยอดที่ลงบิลคือยอดของเซิร์ฟเวอร์ ยอดที่แท็บเล็ตจำมาใช้เทียบเท่านั้น
 * 4. ตัดสต๊อกแล้วติดลบ = ลงบิลได้ แต่ต้องเตือน (เหมือนตอนออนไลน์)
 */
class OfflineCashTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Product $noodle;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = $this->makeBranch('AA', 'สาขาหนึ่ง');

        $category = Category::create(['branch_id' => $this->branch->id, 'name' => 'ก๋วยเตี๋ยว']);

        $this->noodle = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'หมี่ขาว',
            'price' => 50,
            // ตั้งแต่ตอนสร้าง ไม่ใช่มา update ทีหลัง — เทสต์ที่ไม่มีสูตรก็ไม่กระทบ
            // เพราะ usageFor() คืนว่างเปล่าอยู่ดีเมื่อไม่มีสูตรของสาขานั้น
            'track_stock' => true,
        ]);

        $this->cashier = $this->makeUser('cashier', 'cashier@test.local');

        $this->actingAs($this->cashier);
    }

    /* ---------- ทางปกติ ---------- */

    public function test_cash_taken_while_offline_closes_the_bill_when_it_syncs(): void
    {
        $order = $this->billFor(2);

        $this->sync([$this->payEntry('p1', $order, received: 200)])
            ->assertOk()
            ->assertJsonPath('applied', 1)
            ->assertJsonPath('held', 0);

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->receipt_no, 'เลขใบเสร็จออกตอน sync ไม่ใช่ตอนเก็บเงิน');
        $this->assertSame('100.00', $order->paid_amount);
        $this->assertSame('100.00', $order->change_amount, 'รับ 200 ยอด 100 ทอน 100');
    }

    public function test_the_receipt_number_comes_from_the_server_not_the_tablet(): void
    {
        $order = $this->billFor(1);

        $this->sync([$this->payEntry('p1', $order, received: 50)])->assertJsonPath('applied', 1);

        $this->assertStringStartsWith(
            'R',
            (string) $order->fresh()->receipt_no,
            'ต้องเป็นเลขในชุดเดียวกับที่ออกตอนออนไลน์',
        );
    }

    /* ---------- ด่านกันตัดเงินสองรอบ ---------- */

    public function test_sending_the_same_payment_twice_does_not_charge_twice(): void
    {
        $order = $this->billFor(2);
        $queue = [$this->payEntry('p1', $order, received: 200)];

        $this->sync($queue)->assertJsonPath('applied', 1);

        $receiptNo = $order->fresh()->receipt_no;

        // แท็บเล็ตส่งซ้ำเพราะไม่ได้รับคำตอบรอบแรก
        $this->sync($queue)
            ->assertOk()
            ->assertJsonPath('applied', 0)
            ->assertJsonPath('results.0.status', 'duplicate');

        $order->refresh();

        $this->assertSame($receiptNo, $order->receipt_no, 'เลขใบเสร็จต้องไม่ถูกออกใหม่');
        $this->assertSame(1, $order->payments()->count(), 'ต้องมีรายการรับเงินรายการเดียว');
    }

    /* ---------- เงินที่ลงบิลไม่ได้ ---------- */

    public function test_a_payment_that_cannot_be_booked_is_held_for_the_manager(): void
    {
        $order = $this->billFor(2);

        // มีคนปิดบิลนี้ไปก่อนแล้วจากอีกเครื่อง
        app(PaymentService::class)->pay($order->refresh(), [
            ['method' => 'cash', 'amount' => 100, 'received' => 100],
        ]);

        $response = $this->sync([$this->payEntry('p1', $order, received: 200)]);

        $response->assertOk()
            ->assertJsonPath('held', 1)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('results.0.status', 'held');

        $entry = OfflineSyncEntry::where('uuid', 'p1')->firstOrFail();

        $this->assertSame(OfflineSyncEntry::STATUS_HELD, $entry->status);
        $this->assertSame('200.00', $entry->amount, 'ต้องรู้ว่าเงินในลิ้นชักเท่าไหร่ ไม่งั้นตามไม่ได้');
        $this->assertNotNull($entry->message);
        $this->assertNull($entry->resolved_at);
    }

    public function test_a_non_money_entry_that_fails_stays_failed_not_held(): void
    {
        $order = $this->billFor(1);

        $response = $this->sync([[
            'uuid' => 'a1',
            'kind' => 'add_item',
            'order_id' => $order->id,
            'payload' => ['product_id' => 999999, 'qty' => 1],
        ]]);

        // ใบที่ไม่ได้แทนเงิน ล้มแล้วให้พนักงานคีย์ใหม่ได้ ไม่ต้องรบกวนผู้จัดการ
        $response->assertJsonPath('results.0.status', 'failed')->assertJsonPath('held', 0);
    }

    public function test_a_bill_that_changed_while_offline_is_held_not_booked_at_the_wrong_total(): void
    {
        $order = $this->billFor(2);

        // มีคนเพิ่มของเข้าบิลจากอีกเครื่องระหว่างที่เครื่องนี้หลุด
        app(OrderService::class)->addItem($order, $this->noodle, 1);

        $response = $this->sync([$this->payEntry('p1', $order, received: 200, expected: 100)]);

        $response->assertJsonPath('results.0.status', 'held');
        $this->assertStringContainsString('ยอดบิลเปลี่ยน', (string) $response->json('results.0.message'));
        $this->assertSame(OrderStatus::Open, $order->fresh()->status, 'บิลต้องยังเปิดอยู่ ไม่ถูกปิดด้วยยอดผิด');
    }

    public function test_money_that_is_less_than_the_bill_is_held(): void
    {
        $order = $this->billFor(2);

        $response = $this->sync([$this->payEntry('p1', $order, received: 80)]);

        $response->assertJsonPath('results.0.status', 'held');
        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
    }

    public function test_a_locked_accounting_period_holds_the_money_instead_of_booking_it(): void
    {
        $pastDay = Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays(9);

        /*
        | ต้องปิดงวด **ก่อน** สร้างบิลย้อนวัน
        |
        | ปิดงวดทั้งที่ยังมีบิลเปิดค้างในเดือนนั้นไม่ได้ (ด่านของ 2.5 เอง) ถ้าสร้างบิลก่อน
        | จะไปติดด่านนั้นแทนที่จะได้ทดสอบสิ่งที่ตั้งใจ — ลำดับเดียวกับ PeriodLockTest
        */
        $this->actingAs($this->makeUser('manager', 'manager@test.local'));
        app(PeriodLockService::class)->close($this->branch, $pastDay->format('Y-m'));

        $this->actingAs($this->cashier);

        // บิลนี้เกิดหลังปิดงวด จำลองบิลที่แท็บเล็ตถือค้างไว้ข้ามเดือน
        $order = $this->billFor(2);
        $order->forceFill(['business_date' => $pastDay->toDateString()])->save();

        $this->sync([$this->payEntry('p1', $order->fresh(), received: 200)])
            ->assertJsonPath('results.0.status', 'held');

        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
    }

    /* ---------- สต๊อก ---------- */

    /**
     * ปิดบิลแล้วต้องตัดสต๊อกจริง — ไม่เกี่ยวกับ offline เลย
     *
     * ── ทำไมเทสต์นี้อยู่ในไฟล์ของเฟส 3 ──────────────────────────────────
     * เจอที่นี่ตอนเขียนเทสต์รับเงินตอนหลุด แล้วพบว่า **ตอนออนไลน์ก็ไม่ตัดสต๊อกเหมือนกัน**
     * ทั้งโปรเจกต์ไม่เคยมีเทสต์ไหนคุมเส้นทางนี้ (`deductForOrder` ไม่ถูกเรียกในเทสต์ไหนเลย)
     * บั๊กจึงอยู่มาเงียบ ๆ ตลอด — ดูคำอธิบายเต็มที่ StockService::deductForOrder()
     *
     * ถ้าวันหนึ่งตัวนี้แดงขึ้นมาอีก ให้สงสัยเรื่องเดิมก่อนเสมอ:
     * มีใครสักคนโหลด relation มาแบบจำกัดคอลัมน์ไว้ก่อนแล้ว
     */
    public function test_paying_a_bill_deducts_stock_at_all(): void
    {
        [$order, $flour] = $this->billWithRecipe(startingStock: 500, gramsPerDish: 120, dishes: 2);

        app(PaymentService::class)->pay($order->refresh(), [
            ['method' => 'cash', 'amount' => (float) $order->grand_total, 'received' => 200],
        ]);

        $this->assertSame(
            1,
            $this->usageMovements($flour),
            'ปิดบิลตอนออนไลน์ต้องมีรายการตัดสต๊อกหนึ่งแถว',
        );
        $this->assertSame('260.000', $flour->fresh()->stockAt($this->branch->id)->stock_qty);
    }

    public function test_paying_offline_deducts_stock_the_same_way_as_online(): void
    {
        [$order, $flour] = $this->billWithRecipe(startingStock: 500, gramsPerDish: 120, dishes: 2);

        $this->sync([$this->payEntry('p1', $order, received: 200)])->assertJsonPath('applied', 1);

        $this->assertSame(1, $this->usageMovements($flour), 'ต้องมีรายการตัดสต๊อกหนึ่งแถว');
        $this->assertSame(
            '260.000',
            $flour->fresh()->stockAt($this->branch->id)->stock_qty,
            '500 − (120 × 2)',
        );
    }

    public function test_stock_may_go_negative_but_the_manager_is_warned(): void
    {
        [$order, $flour] = $this->billWithRecipe(startingStock: 100, gramsPerDish: 120, dishes: 2);

        $response = $this->sync([$this->payEntry('p1', $order, received: 200)]);

        // เหมือนตอนออนไลน์ — ปล่อยให้ติดลบ ไม่จับลูกค้าเป็นตัวประกันเพราะตัวเลขไม่ตรง
        $response->assertJsonPath('applied', 1);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertTrue((float) $flour->fresh()->stockAt($this->branch->id)->stock_qty < 0);

        // แต่ต้องเตือน เพราะแปลว่าของหมดไปแล้วแต่ยังขายอยู่
        $entry = OfflineSyncEntry::where('uuid', 'p1')->firstOrFail();

        $this->assertNotNull($entry->warning);
        $this->assertStringContainsString('ติดลบ', $entry->warning);
        $this->assertNotNull($response->json('results.0.warning'), 'หน้าจอต้องได้คำเตือนกลับไปด้วย');
    }

    public function test_a_bill_with_enough_stock_raises_no_warning(): void
    {
        [$order] = $this->billWithRecipe(startingStock: 5000, gramsPerDish: 120, dishes: 2);

        $this->sync([$this->payEntry('p1', $order, received: 200)])->assertJsonPath('applied', 1);

        $this->assertNull(OfflineSyncEntry::where('uuid', 'p1')->firstOrFail()->warning);
    }

    /* ---------- หน้าผู้จัดการ ---------- */

    public function test_the_manager_sees_the_held_money(): void
    {
        $this->heldEntry();

        $this->actingAs($this->makeUser('manager', 'manager@test.local'))
            ->get('/backoffice/offline-holds')
            ->assertOk();
    }

    public function test_a_cashier_cannot_open_the_manager_page(): void
    {
        $this->get('/backoffice/offline-holds')->assertForbidden();
    }

    public function test_the_manager_must_write_what_they_did_with_the_money(): void
    {
        $entry = $this->heldEntry();

        $this->actingAs($this->makeUser('manager', 'manager@test.local'))
            ->post("/backoffice/offline-holds/{$entry->id}/resolve", [
                'resolution' => OfflineSyncEntry::RESOLUTION_REFUNDED,
                'note' => 'สั้น',
            ])
            ->assertSessionHasErrors('note');

        $this->assertNull($entry->fresh()->resolved_at);
    }

    public function test_resolving_records_who_decided_and_why(): void
    {
        $entry = $this->heldEntry();
        $manager = $this->makeUser('manager', 'manager@test.local');

        $this->actingAs($manager)
            ->post("/backoffice/offline-holds/{$entry->id}/resolve", [
                'resolution' => OfflineSyncEntry::RESOLUTION_BOOKED,
                'note' => 'เปิดบิลใหม่แล้วเก็บเงินสดตามยอดเดิม',
            ])
            ->assertRedirect();

        $entry->refresh();

        $this->assertSame(OfflineSyncEntry::RESOLUTION_BOOKED, $entry->resolution);
        $this->assertSame((int) $manager->id, (int) $entry->resolved_by);
        $this->assertNotNull($entry->resolved_at);
        $this->assertStringContainsString('เปิดบิลใหม่', (string) $entry->resolution_note);
    }

    public function test_the_same_money_cannot_be_decided_twice(): void
    {
        $entry = $this->heldEntry();
        $manager = $this->makeUser('manager', 'manager@test.local');

        $payload = [
            'resolution' => OfflineSyncEntry::RESOLUTION_OVERAGE,
            'note' => 'บันทึกเป็นเงินเกินในลิ้นชักไว้ก่อน',
        ];

        $this->actingAs($manager)->post("/backoffice/offline-holds/{$entry->id}/resolve", $payload);

        $first = $entry->fresh()->resolved_at;

        $this->actingAs($manager)
            ->post("/backoffice/offline-holds/{$entry->id}/resolve", [
                'resolution' => OfflineSyncEntry::RESOLUTION_REFUNDED,
                'note' => 'เปลี่ยนใจ อยากบันทึกใหม่เป็นคืนเงิน',
            ])
            ->assertSessionHas('error');

        $this->assertSame(
            OfflineSyncEntry::RESOLUTION_OVERAGE,
            $entry->fresh()->resolution,
            'คำตัดสินแรกต้องอยู่ ไม่ถูกทับ',
        );
        $this->assertEquals($first, $entry->fresh()->resolved_at);
    }

    public function test_money_from_another_branch_cannot_be_decided_here(): void
    {
        $entry = $this->heldEntry();
        $entry->update(['branch_id' => $this->makeBranch('BB', 'อีกสาขา')->id]);

        $this->actingAs($this->makeUser('manager', 'manager@test.local'))
            ->post("/backoffice/offline-holds/{$entry->id}/resolve", [
                'resolution' => OfflineSyncEntry::RESOLUTION_OVERAGE,
                'note' => 'ไม่ควรกดได้ตั้งแต่แรก',
            ])
            ->assertForbidden();

        $this->assertNull($entry->fresh()->resolved_at);
    }

    /* ---------- ตัวช่วย ---------- */

    /** @param  array<int, array<string, mixed>>  $entries */
    protected function sync(array $entries)
    {
        return $this->postJson('/pos/offline/sync', ['entries' => $entries]);
    }

    /** @return array<string, mixed> */
    protected function payEntry(string $uuid, Order $order, float $received, ?float $expected = null): array
    {
        return [
            'uuid' => $uuid,
            'kind' => 'pay_cash',
            'order_id' => $order->id,
            'at' => Carbon::now()->toIso8601String(),
            'payload' => [
                'expected_total' => $expected ?? (float) $order->fresh()->grand_total,
                'received' => $received,
                // ค่าที่แท็บเล็ตคำนวณไว้โชว์ลูกค้า เซิร์ฟเวอร์ต้องคิดใหม่เองไม่เชื่อค่านี้
                'change' => 0,
                'slip_no' => 'X120000',
                'order_no' => $order->order_no,
            ],
        ];
    }

    protected function billFor(int $dishes): Order
    {
        $order = app(OrderService::class)->open($this->branch);
        app(OrderService::class)->addItem($order, $this->noodle, $dishes);

        return $order->refresh();
    }

    /**
     * บิลที่ผูกสูตรไว้ เพื่อให้มีอะไรให้ตัดสต๊อกจริง ๆ
     *
     * @return array{0: Order, 1: StockItem}
     */
    protected function billWithRecipe(float $startingStock, float $gramsPerDish, int $dishes): array
    {
        $flour = StockItem::create([
            'branch_id' => null,
            'code' => 'FL1',
            'name' => 'เส้นหมี่',
            'unit' => StockUnit::Gram,
            'purchase_unit' => 'ถุง',
            'purchase_factor' => 1000,
        ]);

        RecipeItem::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->noodle->id,
            'stock_item_id' => $flour->id,
            'qty' => $gramsPerDish,
        ]);

        $this->noodle->update(['track_stock' => true]);
        $this->noodle->refresh();

        app(StockService::class)->move(
            $flour,
            $this->branch->id,
            StockMovementType::Purchase,
            $startingStock,
            0.05,
        );

        /*
        | พิสูจน์ว่าฟิกซ์เจอร์ถูกก่อน แล้วค่อยไปทดสอบสิ่งที่ตั้งใจจะทดสอบ
        |
        | ถ้าบรรทัดนี้ตก แปลว่าสูตร/ธง track_stock ตั้งไม่ถูก — เป็นปัญหาของเทสต์
        | ถ้าบรรทัดนี้ผ่านแต่สต๊อกไม่ขยับ แปลว่าเส้นทางรับเงินตอนหลุดไม่ได้ตัดสต๊อกจริง
        | ซึ่งเป็นบั๊กของโค้ด แยกสองอย่างนี้ออกจากกันตั้งแต่ต้น
        */
        $this->assertSame(
            [$flour->id => (float) $gramsPerDish * $dishes],
            app(StockService::class)->usageFor($this->noodle, [], (int) $this->branch->id, $dishes),
            'ฟิกซ์เจอร์ต้องคำนวณการใช้วัตถุดิบได้ก่อน',
        );

        return [$this->billFor($dishes), $flour];
    }

    /** เงินที่รับมาแล้วแต่ลงบิลไม่ได้ — สร้างจากเส้นทางจริง ไม่ได้ insert แถวเอง */
    protected function heldEntry(): OfflineSyncEntry
    {
        $order = $this->billFor(2);

        app(PaymentService::class)->pay($order->refresh(), [
            ['method' => 'cash', 'amount' => 100, 'received' => 100],
        ]);

        $this->sync([$this->payEntry('p1', $order, received: 200, expected: 100)]);

        return OfflineSyncEntry::where('uuid', 'p1')->firstOrFail();
    }

    /** มีรายการตัดสต๊อก (usage) ของวัตถุดิบชิ้นนี้กี่แถว */
    protected function usageMovements(StockItem $item): int
    {
        return StockMovement::where('stock_item_id', $item->id)
            ->where('type', StockMovementType::Usage)
            ->count();
    }

    protected function makeUser(string $role, string $email): User
    {
        return User::create([
            'branch_id' => $this->branch->id,
            'name' => "ผู้ใช้ {$role}",
            'email' => $email,
            'password' => 'password',
            'role' => $role,
        ]);
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
}
