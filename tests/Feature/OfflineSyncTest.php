<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\KitchenTicket;
use App\Models\OfflineSyncEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PeriodLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * คิวฝั่งเบราว์เซอร์ตอนเน็ตหลุด — offline เฟส 2 (gap-analysis)
 *
 * ── สิ่งที่เทสต์ชุดนี้คุมเป็นหลัก ───────────────────────────────────────
 * 1. **ส่งคิวเดิมซ้ำต้องไม่ทำให้อาหารเข้าครัวสองรอบ** — นี่คือความเสียหาย
 *    ที่แก้ย้อนหลังไม่ได้ และเป็นเหตุผลทั้งหมดที่ฟีเจอร์นี้ต้องมี uuid
 * 2. รายการหนึ่งล้ม ต้องไม่ลากที่เหลือล้มตาม ของที่คีย์ตอนหลุดคือของที่ลูกค้ากินไปแล้ว
 * 3. ราคาคิดใหม่จากเซิร์ฟเวอร์เสมอ ไม่ใช่ราคาที่แท็บเล็ตจำไว้ตอนหลุด
 * 4. ด่านสาขา ด่านบิลปิด ด่านงวดบัญชี ยังทำงานเหมือนตอนออนไลน์ทุกประการ
 */
class OfflineSyncTest extends TestCase
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
        ]);

        $this->cashier = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'แคชเชียร์',
            'email' => 'cashier@test.local',
            'password' => 'password',
            'role' => 'cashier',
        ]);

        $this->actingAs($this->cashier);
    }

    /* ---------- ทางปกติ ---------- */

    public function test_items_keyed_while_offline_land_on_the_bill(): void
    {
        $order = $this->openOrder();

        $this->sync([
            $this->addEntry('a1', $order, 2),
            $this->addEntry('a2', $order, 1),
        ])->assertOk()->assertJsonPath('applied', 2)->assertJsonPath('failed', 0);

        $this->assertSame(2, $order->items()->count());
        $this->assertSame('150.00', $order->fresh()->subtotal, '50 × 2 + 50 × 1');
    }

    public function test_the_order_of_the_queue_is_kept_so_nothing_misses_the_kitchen(): void
    {
        $order = $this->openOrder();

        /*
        | ลำดับคือหัวใจ — ถ้าคำสั่งส่งครัวถูกเล่นก่อนรายการสุดท้าย
        | จานนั้นจะค้างอยู่ในบิลโดยครัวไม่เคยเห็น แล้วลูกค้ารออาหารที่ไม่มีใครทำ
        */
        $this->sync([
            $this->addEntry('a1', $order, 1),
            $this->addEntry('a2', $order, 1),
            $this->sendEntry('s1', $order),
        ])->assertOk()->assertJsonPath('failed', 0);

        $this->assertSame(
            0,
            $order->items()->where('status', 'pending')->count(),
            'ทุกจานต้องถูกส่งครัวไปแล้ว ไม่มีอะไรค้าง',
        );
        $this->assertSame(1, KitchenTicket::count(), 'ใบสั่งครัวใบเดียว ไม่ใช่ใบต่อจาน');
    }

    /* ---------- ด่านกันอาหารเข้าครัวสองรอบ ---------- */

    public function test_sending_the_same_queue_twice_does_not_order_the_food_twice(): void
    {
        $order = $this->openOrder();
        $queue = [$this->addEntry('a1', $order, 2), $this->sendEntry('s1', $order)];

        $this->sync($queue)->assertOk()->assertJsonPath('applied', 2);

        // แท็บเล็ตส่งซ้ำเพราะไม่ได้รับคำตอบรอบแรก (เน็ตหลุดตอนขากลับ)
        $this->sync($queue)
            ->assertOk()
            ->assertJsonPath('applied', 0)
            ->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.1.status', 'duplicate');

        $this->assertSame(1, $order->items()->count(), 'ต้องมีจานเดียว ไม่ใช่สองจาน');
        $this->assertSame(1, KitchenTicket::count(), 'ครัวต้องได้ใบเดียว');
    }

    public function test_a_duplicate_does_not_leave_a_second_audit_row(): void
    {
        $order = $this->openOrder();

        $this->sync([$this->addEntry('a1', $order, 1)]);
        $this->sync([$this->addEntry('a1', $order, 1)]);

        $this->assertSame(1, OfflineSyncEntry::where('uuid', 'a1')->count());
    }

    /* ---------- รายการหนึ่งล้ม ไม่ลากที่เหลือ ---------- */

    public function test_one_bad_entry_does_not_stop_the_others(): void
    {
        $order = $this->openOrder();

        $response = $this->sync([
            $this->addEntry('a1', $order, 1),
            // เมนูถูกลบไประหว่างที่แท็บเล็ตหลุด
            $this->addEntry('a2', $order, 1, ['product_id' => 999999]),
            $this->addEntry('a3', $order, 1),
        ]);

        $response->assertOk()
            ->assertJsonPath('applied', 2)
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('results.1.status', 'failed');

        $this->assertSame(2, $order->items()->count(), 'อีกสองจานต้องขึ้นบิลให้ได้');
    }

    public function test_a_failed_entry_says_why_in_words_the_staff_can_read(): void
    {
        $order = $this->openOrder();

        $response = $this->sync([$this->addEntry('a1', $order, 1, ['product_id' => 999999])]);

        $message = $response->json('results.0.message');

        $this->assertNotNull($message);
        $this->assertStringContainsString('เมนู', $message);
    }

    /**
     * ใบที่ล้มไปแล้ว ส่ง uuid เดิมซ้ำต้องไม่ทำงาน
     *
     * ฟังดูเหมือนข้อเสีย แต่เป็นราคาที่ต้องจ่ายของการ "จองคีย์ก่อนทำงาน"
     * ซึ่งจำเป็น เพราะถ้าจองหลังทำงาน งานที่สำเร็จแล้วแต่บันทึกผลไม่ทัน
     * (ไฟดับพอดี) จะถูกทำซ้ำรอบหน้า = อาหารเข้าครัวสองจาน
     *
     * ฝั่งหน้าจอจึงต้องไม่ส่งใบที่ล้มซ้ำ และบอกพนักงานให้ "คีย์ใหม่"
     * ไม่ใช่ให้กดส่งซ้ำแล้วมันหายไปเงียบ ๆ โดยของไม่เคยขึ้นบิล
     */
    public function test_an_entry_that_already_failed_cannot_be_pushed_again(): void
    {
        $order = $this->openOrder();

        // ต้องมีรายการก่อน — บิลว่างรับเงินไม่ได้ (ด่านของ PaymentService เอง)
        app(OrderService::class)->addItem($order, $this->noodle, 1);

        $this->payAndClose($order);

        // นับไว้ก่อน แล้วเทียบว่า "ไม่มีอะไรเพิ่ม" ไม่ใช่เทียบกับศูนย์
        // เพราะบิลนี้มีรายการของมันเองอยู่แล้วจากบรรทัดข้างบน
        $before = $order->items()->count();

        $this->sync([$this->addEntry('a1', $order, 1)])->assertJsonPath('failed', 1);

        // ปัญหาถูกแก้แล้ว (บิลกลับมาเปิด) แต่ใบเดิมยังส่งซ้ำไม่ได้
        $order->forceFill(['status' => 'open'])->save();

        $this->sync([$this->addEntry('a1', $order, 1)])
            ->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame(
            $before,
            $order->items()->count(),
            'uuid เดิมถือว่าใช้ไปแล้ว — พนักงานต้องคีย์ใหม่ ไม่ใช่ส่งใบเดิมซ้ำ',
        );
    }

    /* ---------- ด่านที่ต้องทำงานเหมือนตอนออนไลน์ ---------- */

    public function test_an_entry_for_another_branch_is_refused(): void
    {
        $other = $this->makeBranch('BB', 'อีกสาขา');
        $foreign = app(OrderService::class)->open($other);

        $this->sync([$this->addEntry('a1', $foreign, 1)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'failed');

        $this->assertSame(0, $foreign->items()->count());
    }

    public function test_a_closed_bill_refuses_new_items(): void
    {
        $order = $this->openOrder();
        app(OrderService::class)->addItem($order, $this->noodle, 1);
        $this->payAndClose($order);

        $response = $this->sync([$this->addEntry('a1', $order, 1)]);

        $response->assertJsonPath('results.0.status', 'failed');
        $this->assertStringContainsString('ปิด', (string) $response->json('results.0.message'));
    }

    public function test_a_closed_accounting_period_still_blocks_the_replay(): void
    {
        $manager = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'ผู้จัดการ',
            'email' => 'manager@test.local',
            'password' => 'password',
            'role' => 'manager',
        ]);

        $pastDay = Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays(9);

        /*
        | ต้องปิดงวด **ก่อน** สร้างบิลย้อนวัน
        |
        | ปิดงวดทั้งที่ยังมีบิลเปิดค้างในเดือนนั้นไม่ได้ (ด่านของ 2.5 เอง) ถ้าสร้างบิลก่อน
        | จะไปติดด่านนั้นแทนที่จะได้ทดสอบสิ่งที่ตั้งใจ — ลำดับเดียวกับ PeriodLockTest
        */
        $this->actingAs($manager);
        app(PeriodLockService::class)->close($this->branch, $pastDay->format('Y-m'));

        $this->actingAs($this->cashier);

        $order = $this->openOrder();
        $order->forceFill(['business_date' => $pastDay->toDateString()])->save();

        $this->sync([$this->addEntry('a1', $order->fresh(), 1)])
            ->assertJsonPath('results.0.status', 'failed');

        $this->assertSame(0, $order->items()->count(), 'งวดที่ปิดแล้วต้องไม่ถูกแก้ย้อนหลังผ่านทางลัดนี้');
    }

    public function test_sending_to_the_kitchen_with_nothing_pending_fails_loudly(): void
    {
        $order = $this->openOrder();

        $this->sync([$this->sendEntry('s1', $order)])
            ->assertJsonPath('results.0.status', 'failed');
    }

    /* ---------- ราคา ---------- */

    public function test_the_price_is_recalculated_here_not_taken_from_the_tablet(): void
    {
        $order = $this->openOrder();

        // ร้านขึ้นราคาระหว่างที่แท็บเล็ตหลุด
        $this->noodle->update(['price' => 60]);

        $this->sync([$this->addEntry('a1', $order, 1)])->assertJsonPath('applied', 1);

        $this->assertSame(
            '60.00',
            $order->items()->firstOrFail()->unit_price,
            'ต้องเป็นราคาปัจจุบัน ไม่ใช่ราคาที่เครื่องจำไว้ตอนกด',
        );
    }

    /* ---------- รูปแบบคำขอ ---------- */

    public function test_an_unknown_kind_is_refused_without_touching_the_bill(): void
    {
        $order = $this->openOrder();

        $this->sync([[
            'uuid' => 'x1',
            'kind' => 'delete_everything',
            'order_id' => $order->id,
            'payload' => null,
        ]])->assertJsonPath('results.0.status', 'failed');

        $this->assertSame(0, OfflineSyncEntry::count(), 'ชนิดที่ไม่รู้จักต้องไม่ถูกจองคีย์ไว้ด้วยซ้ำ');
    }

    public function test_too_many_entries_in_one_request_are_refused(): void
    {
        $order = $this->openOrder();

        $entries = [];

        for ($i = 0; $i <= 100; $i++) {
            $entries[] = $this->addEntry('bulk'.$i, $order, 1);
        }

        $this->sync($entries)->assertStatus(422);
        $this->assertSame(0, OrderItem::count(), 'ถูกปฏิเสธทั้งก้อน ต้องไม่มีอะไรลงไปครึ่ง ๆ กลาง ๆ');
    }

    public function test_a_guest_cannot_push_anything(): void
    {
        $order = $this->openOrder();

        auth()->logout();

        $response = $this->sync([$this->addEntry('a1', $order, 1)]);

        // 401 หรือเด้งไปหน้าล็อกอิน แล้วแต่ว่า Laravel ตีความ Accept ยังไง
        // สิ่งที่เทสต์นี้สนใจคือ "ไม่สำเร็จ และไม่มีอะไรลงบิล"
        $this->assertTrue(
            $response->status() === 401 || $response->isRedirect(),
            'คนที่ไม่ได้ล็อกอินต้องยิงเข้ามาไม่ได้ (ได้ '.$response->status().')',
        );
        $this->assertSame(0, OrderItem::count());
    }

    /* ---------- บันทึกไว้ให้ตรวจย้อนหลัง ---------- */

    public function test_every_entry_leaves_a_row_that_says_what_happened(): void
    {
        $order = $this->openOrder();

        $this->sync([
            $this->addEntry('a1', $order, 1),
            $this->addEntry('a2', $order, 1, ['product_id' => 999999]),
        ]);

        $ok = OfflineSyncEntry::where('uuid', 'a1')->firstOrFail();
        $bad = OfflineSyncEntry::where('uuid', 'a2')->firstOrFail();

        $this->assertSame(OfflineSyncEntry::STATUS_APPLIED, $ok->status);
        $this->assertSame((int) $this->branch->id, (int) $ok->branch_id);
        $this->assertSame((int) $this->cashier->id, (int) $ok->user_id);
        $this->assertNotNull($ok->client_at, 'เวลาที่พนักงานกดต้องถูกเก็บไว้ดูย้อนหลัง');

        $this->assertSame(OfflineSyncEntry::STATUS_FAILED, $bad->status);
        $this->assertNotNull($bad->message);
    }

    public function test_a_tablet_clock_set_to_nonsense_does_not_break_the_sync(): void
    {
        $order = $this->openOrder();

        $entry = $this->addEntry('a1', $order, 1);
        $entry['at'] = 'เมื่อวานตอนบ่าย';

        $this->sync([$entry])->assertJsonPath('applied', 1);

        $this->assertNull(OfflineSyncEntry::where('uuid', 'a1')->firstOrFail()->client_at);
    }

    /* ---------- ตัวช่วย ---------- */

    /** @param  array<int, array<string, mixed>>  $entries */
    protected function sync(array $entries)
    {
        return $this->postJson('/pos/offline/sync', ['entries' => $entries]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function addEntry(string $uuid, Order $order, float $qty, array $overrides = []): array
    {
        return [
            'uuid' => $uuid,
            'kind' => 'add_item',
            'order_id' => $order->id,
            'at' => Carbon::now()->toIso8601String(),
            'payload' => array_merge([
                'product_id' => $this->noodle->id,
                'qty' => $qty,
                'modifier_ids' => [],
                'note' => null,
                'open_price' => null,
                // ค่าที่แท็บเล็ตใช้วาดบนจอ ส่งมาด้วยแต่เซิร์ฟเวอร์ต้องไม่เชื่อ
                'name' => 'หมี่ขาว',
                'unit_price' => 50,
                'modifier_names' => [],
            ], $overrides),
        ];
    }

    /** @return array<string, mixed> */
    protected function sendEntry(string $uuid, Order $order): array
    {
        return [
            'uuid' => $uuid,
            'kind' => 'send_kitchen',
            'order_id' => $order->id,
            'at' => Carbon::now()->toIso8601String(),
            'payload' => null,
        ];
    }

    protected function openOrder(): Order
    {
        return app(OrderService::class)->open($this->branch);
    }

    protected function payAndClose(Order $order): void
    {
        $order->refresh();

        app(PaymentService::class)->pay($order, [
            ['method' => 'cash', 'amount' => (float) $order->grand_total, 'received' => (float) $order->grand_total],
        ]);

        $order->refresh();
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
