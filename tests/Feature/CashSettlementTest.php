<?php

namespace Tests\Feature;

use App\Enums\CashSettlementStatus;
use App\Models\Branch;
use App\Models\CashSettlement;
use App\Models\Category;
use App\Models\OfflineSyncEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashSettlementService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * นำส่งเงินสดสิ้นวัน — และเงินที่รับตอนเน็ตหลุดแต่ลงบิลไม่ได้
 *
 * ── ทำไมต้องมีชุดนี้ ────────────────────────────────────────────────
 * ตัวเลขในใบนำส่งคือสิ่งที่ตัดสินว่าพนักงานคนหนึ่ง "ส่งเงินครบ" หรือ "เงินหาย"
 * ถ้ามันผิด ผลไม่ใช่บั๊กบนหน้าจอ แต่เป็นคนถูกกล่าวหา
 *
 * ── ช่องที่ชุดนี้ปิด ────────────────────────────────────────────────
 * เงินสดที่รับตอนระบบล่มแล้วลงบิลไม่ได้ (status = held) อยู่ในลิ้นชักจริง
 * แต่ไม่มีแถวใน payments — `expectedFor()` จึงมองไม่เห็น
 * ปลายวัน `counted` จะมากกว่า `expected` แล้วระบบขึ้นว่า "เงินเกิน"
 * คนที่ทำถูกทุกขั้นตอนกลายเป็นคนที่ต้องอธิบายตัวเอง
 *
 * ── เส้นแบ่งที่ต้องคุมให้แน่น ─────────────────────────────────────────
 * `expected_amount` ต้องยังหมายความว่า "มาจากบิล" เสมอ ตรวจย้อนหลังได้
 * เงินที่ไม่มีบิลรองรับอยู่คอลัมน์ของตัวเอง แล้วค่อยรวมกันเป็นยอดที่ต้องนำส่ง
 */
class CashSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Product $noodle;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = $this->makeBranch('CS1');

        $category = Category::create(['branch_id' => $this->branch->id, 'name' => 'ก๋วยเตี๋ยว']);

        $this->noodle = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'หมี่ขาว',
            'price' => 100,
            'cost' => 30,
        ]);

        $this->cashier = $this->makeUser('cashier', 'cs-cashier@test.local');

        $this->actingAs($this->cashier);
    }

    /* ---------- ฐาน: วันที่มีแต่บิลปกติ ---------- */

    public function test_the_amount_to_hand_over_comes_from_the_bills(): void
    {
        $this->paidCashBill(100);
        $this->paidCashBill(50);

        $settlement = $this->settlementForToday();

        $this->assertSame('150.00', $settlement->expected_amount);
        $this->assertSame('0.00', $settlement->held_cash_amount);
        $this->assertSame(150.0, $settlement->due());
    }

    public function test_money_taken_out_of_the_drawer_during_the_shift_is_deducted(): void
    {
        $this->paidCashBill(100);
        $this->shift(openingCash: 500, countedCash: 570, cashIn: 20, cashOut: 50);

        $this->assertSame('70.00', $this->settlementForToday()->expected_amount, '100 + 20 - 50');
    }

    /* ---------- ช่องที่ปิดรอบนี้: เงินค้างตอนเน็ตหลุด ---------- */

    public function test_cash_taken_offline_that_never_reached_a_bill_is_still_money_the_staff_must_hand_over(): void
    {
        $this->paidCashBill(100);
        $this->heldEntry(200);

        $settlement = $this->settlementForToday();

        // ยอดจากบิลต้องไม่ถูกแตะ — มันคือตัวเลขที่ตรวจย้อนหลังได้
        $this->assertSame('100.00', $settlement->expected_amount);
        $this->assertSame('200.00', $settlement->held_cash_amount);
        $this->assertSame(300.0, $settlement->due(), 'เงินในลิ้นชักมี 300 พนักงานต้องส่ง 300');
    }

    public function test_handing_over_everything_in_the_drawer_no_longer_shows_up_as_a_surplus(): void
    {
        /*
        | นี่คือเหตุผลทั้งหมดของงานรอบนี้
        |
        | ก่อนแก้: expected = 100 (จากบิล) · พนักงานโอน 300 ตามเงินที่นับได้
        |          → diff = +200 ระบบฟ้องว่า "โอนเกิน" ทั้งที่ทำถูกทุกขั้น
        */
        $this->paidCashBill(100);
        $this->heldEntry(200);

        $settlement = app(CashSettlementService::class)->submit(
            $this->settlementForToday(),
            300,
            'REF-1',
            null,
            $this->cashier,
        );

        $this->assertSame('0.00', $settlement->diff_amount, 'ส่งครบตามเงินในลิ้นชัก = ไม่มีส่วนต่าง');

        // และตัวเลขที่ตรวจสอบได้ยังแยกกันอยู่ ไม่ได้ถูกกลืนเข้าไปเป็นก้อนเดียว
        $this->assertSame('100.00', $settlement->expected_amount);
        $this->assertSame('200.00', $settlement->held_cash_amount);
        $this->assertSame('300.00', $settlement->transferred_amount);
    }

    public function test_handing_over_only_the_bill_total_is_short_by_the_held_cash(): void
    {
        $this->paidCashBill(100);
        $this->heldEntry(200);

        $settlement = app(CashSettlementService::class)->submit(
            $this->settlementForToday(),
            100,
            null,
            null,
            $this->cashier,
        );

        $this->assertSame('-200.00', $settlement->diff_amount, 'เงินค้างยังอยู่ในมือพนักงาน = ขาด');
    }

    /* ---------- คำตัดสินของผู้จัดการเปลี่ยนคำตอบ ---------- */

    public function test_cash_booked_onto_a_new_bill_is_no_longer_counted_here(): void
    {
        // ผู้จัดการเปิดบิลใหม่แล้วเก็บเงินตามปกติ — เงินก้อนนี้ไปโผล่ใน payments ทางนั้นแล้ว
        // ถ้ายังนับที่นี่ด้วยจะกลายเป็นเรียกเงินก้อนเดียวกันสองรอบ
        $this->resolve($this->heldEntry(200), OfflineSyncEntry::RESOLUTION_BOOKED);

        $this->assertSame('0.00', $this->settlementForToday()->held_cash_amount);
    }

    public function test_cash_given_back_to_the_customer_is_no_longer_counted_here(): void
    {
        $this->resolve($this->heldEntry(200), OfflineSyncEntry::RESOLUTION_REFUNDED);

        $this->assertSame('0.00', $this->settlementForToday()->held_cash_amount);
    }

    public function test_cash_written_off_as_an_overage_is_still_money_that_must_be_handed_over(): void
    {
        // "รับเกิน" = ยังหาเจ้าของไม่ได้ แต่เงินยังอยู่ในลิ้นชัก ต้องส่งมอบอยู่ดี
        $this->resolve($this->heldEntry(200), OfflineSyncEntry::RESOLUTION_OVERAGE);

        $this->assertSame('200.00', $this->settlementForToday()->held_cash_amount);
    }

    public function test_an_entry_that_simply_failed_is_not_money_in_the_drawer(): void
    {
        // failed = ไม่มีอะไรเกิดขึ้น พนักงานคีย์ใหม่ได้ ไม่ใช่เงินที่รับมาแล้ว
        $entry = $this->heldEntry(200);
        $entry->update(['status' => OfflineSyncEntry::STATUS_FAILED]);

        $this->assertSame('0.00', $this->settlementForToday()->held_cash_amount);
    }

    /* ---------- เตือนก้อนที่ยังไม่มีใครตัดสิน ---------- */

    public function test_it_reports_how_many_holds_nobody_has_decided_on_yet(): void
    {
        $this->heldEntry(200);
        $this->resolve($this->heldEntry(50), OfflineSyncEntry::RESOLUTION_OVERAGE);

        $unresolved = app(CashSettlementService::class)
            ->unresolvedHeldCashFor($this->branch, $this->today());

        $this->assertSame(1, $unresolved['count'], 'ที่ตัดสินแล้วไม่ต้องเตือนอีก');
        $this->assertSame(200.0, $unresolved['amount']);

        // แต่ยอดที่ต้องนำส่งรวมทั้งสองก้อน เพราะเงินอยู่ในลิ้นชักทั้งคู่
        $this->assertSame('250.00', $this->settlementForToday()->held_cash_amount);
    }

    /* ---------- วันขายไหน ---------- */

    public function test_the_money_belongs_to_the_day_of_the_bill_not_the_day_it_synced(): void
    {
        /*
        | พนักงานเก็บเงินตอนสี่ทุ่มวันที่ 20 เน็ตกลับมาตอนเช้าวันที่ 21
        | เงินเข้าลิ้นชักของวันที่ 20 ไม่ใช่วันที่ 21
        |
        | ใช้ business_date ของบิล ไม่ใช่ created_at ของแถว (เวลาที่ sync ขึ้นมา)
        | และไม่ใช่ client_at (นาฬิกาแท็บเล็ต ซึ่งตั้งผิดได้)
        */
        $this->travelTo(Carbon::parse('2026-09-20 22:00:00', $this->branch->timezone));
        $entry = $this->heldEntry(200);
        $billDate = $entry->order->business_date->toDateString();

        $this->travelTo(Carbon::parse('2026-09-21 09:00:00', $this->branch->timezone));
        $entry->update(['created_at' => now()]);

        $settlements = app(CashSettlementService::class);

        $this->assertSame('2026-09-20', $billDate, 'ฟิกซ์เจอร์: บิลต้องเป็นของวันที่ 20');
        $this->assertSame(200.0, $settlements->heldCashFor($this->branch, '2026-09-20'));
        $this->assertSame(0.0, $settlements->heldCashFor($this->branch, '2026-09-21'));
    }

    public function test_held_cash_from_another_branch_never_reaches_this_drawer(): void
    {
        $other = $this->makeBranch('CS2');

        $entry = $this->heldEntry(200);
        $entry->update(['branch_id' => $other->id]);

        $this->assertSame('0.00', $this->settlementForToday()->held_cash_amount);
    }

    /* ---------- ตัวเลขที่ถูกตรึงแล้วห้ามขยับเอง ---------- */

    public function test_a_decision_made_afterwards_does_not_rewrite_a_settlement_already_submitted(): void
    {
        /*
        | ใบนำส่งคือบันทึกว่า "วันนั้นตกลงกันว่าเท่าไหร่" ไม่ใช่ตัวเลขที่ขยับได้เรื่อย ๆ
        | ถ้าคำตัดสินย้อนไปแก้ใบที่ปิดแล้ว ยอดที่ผู้จัดการเคยเซ็นรับรองจะเปลี่ยนเงียบ ๆ
        */
        $this->paidCashBill(100);
        $entry = $this->heldEntry(200);

        app(CashSettlementService::class)->submit($this->settlementForToday(), 300, null, null, $this->cashier);

        $this->resolve($entry, OfflineSyncEntry::RESOLUTION_BOOKED);

        $settlement = $this->settlementForToday();

        $this->assertSame(CashSettlementStatus::Submitted, $settlement->status);
        $this->assertSame('200.00', $settlement->held_cash_amount, 'ตัวเลขถูกตรึงตอนแจ้งโอน');
        $this->assertSame('0.00', $settlement->diff_amount);
    }

    /* ---------- รายการวันที่ยังค้าง ---------- */

    public function test_a_day_with_nothing_but_held_cash_still_shows_up_as_outstanding(): void
    {
        // ไม่มีบิลเงินสดเลย มีแต่เงินที่ค้าง — ถ้ายอดที่ทวงคิดจากบิลอย่างเดียว
        // วันนี้จะเงียบหายไปจากรายการทั้งที่มีเงินอยู่ในลิ้นชัก
        $this->heldEntry(200);

        $rows = collect(app(CashSettlementService::class)->outstanding($this->branch))
            ->firstWhere('business_date', $this->today());

        $this->assertNotNull($rows, 'วันที่มีแต่เงินค้างต้องไม่ถูกข้าม');
        $this->assertSame(200.0, $rows['expected']);
    }

    /* ---------- หน้าจอ ---------- */

    public function test_the_staff_screen_shows_the_held_cash_as_its_own_line(): void
    {
        $this->paidCashBill(100);
        $this->heldEntry(200);

        /*
        | หน้านี้อยู่หลัง permission:cash.settle ซึ่ง Permission::defaultsFor
        | ให้ผู้จัดการขึ้นไป ไม่ให้แคชเชียร์ — คนที่นับเงินกับคนที่รับรองว่าเงินครบ
        | ต้องไม่ใช่คนเดียวกัน (ด่านข้างล่างคุมเส้นนั้นไว้)
        */
        $this->actingAs($this->makeUser('manager', 'cs-manager@test.local'))
            ->get('/pos/cash-settlement')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settlement.expected_amount', $this->money(100))
                ->where('settlement.held_cash_amount', $this->money(200))
                ->where('settlement.due_amount', $this->money(300))
                ->where('unresolvedHeld.count', 1)
                ->where('unresolvedHeld.amount', $this->money(200)));
    }

    public function test_a_cashier_cannot_open_the_hand_over_screen(): void
    {
        /*
        | คนที่รับเงินเข้าลิ้นชักต้องไม่ใช่คนที่แจ้งว่าส่งเงินครบแล้ว
        | ถ้าเป็นคนเดียวกัน ตัวเลขในใบนำส่งจะไม่มีใครถ่วงดุล
        |
        | ด่านนี้ยังกันการ "แก้ให้เทสต์ผ่าน" ด้วยการแจก cash.settle ให้แคชเชียร์
        | ซึ่งเป็นทางที่ง่ายที่สุดและผิดที่สุดเวลาด่านข้างบนตกเพราะสิทธิ์
        */
        $this->actingAs($this->cashier)
            ->get('/pos/cash-settlement')
            ->assertForbidden();
    }

    /* ---------- ตัวช่วย ---------- */

    /**
     * เทียบยอดเงินที่เดินทางผ่าน JSON มาแล้ว
     *
     * ── กับดักที่ด่านนี้แก้ ─────────────────────────────────────────────
     * JSON มีชนิดตัวเลขชนิดเดียว `json_encode(100.0)` ได้ `100`
     * แล้ว `json_decode('100')` คืน **int** ไม่ใช่ float
     *
     * `assertInertia()->where()` เทียบแบบเข้มงวด (`===`) การเขียน
     * `->where('settlement.expected_amount', 100.0)` จึงตกด้วยข้อความ
     * "Failed asserting that 100 is identical to 100.0" — ตกตลอดไป
     * ไม่ว่าฝั่งเซิร์ฟเวอร์จะถูกแค่ไหน
     *
     * และมันตกเฉพาะยอดที่เป็นจำนวนเต็ม ยอดอย่าง 100.50 จะผ่าน
     * เพราะยังเป็น float หลัง decode — กับดักที่โผล่ไม่สม่ำเสมอแบบนี้
     * เสียเวลาหาสาเหตุมากกว่าตกทุกครั้ง
     *
     * ── ทำไมไม่เขียน 100 เฉย ๆ ─────────────────────────────────────────
     * มันจะผ่านวันนี้ แล้วตกวันที่ใครเปลี่ยนฟิกซ์เจอร์เป็น 100.50
     * ตัวนี้เทียบค่าของเงินจริง ๆ ไม่ใช่ชนิดที่ JSON เผอิญเลือกให้
     */
    protected function money(float $baht): \Closure
    {
        // เผื่อครึ่งสตางค์ — เล็กกว่าหน่วยเงินที่เล็กสุด แต่กันความคลาดของ float
        return fn ($actual) => is_numeric($actual) && abs((float) $actual - $baht) < 0.005;
    }

    protected function today(): string
    {
        return $this->branch->businessDateFor()->toDateString();
    }

    protected function settlementForToday(): CashSettlement
    {
        return app(CashSettlementService::class)->forDate($this->branch, $this->today());
    }

    /** บิลที่จ่ายเงินสดเรียบร้อย — ผ่านเส้นทางจริง ไม่ได้ insert แถว payments เอง */
    protected function paidCashBill(float $amount): Order
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);
        $orders->addItem($order, $this->noodle, $amount / 100);

        app(PaymentService::class)->pay($order->refresh(), [
            ['method' => 'cash', 'amount' => $amount, 'received' => $amount],
        ]);

        return $order->refresh();
    }

    /**
     * เงินที่รับมาแล้วตอนเน็ตหลุด แต่ลงบิลไม่ได้
     *
     * ── สร้างจากเส้นทางจริง ไม่ได้ insert สถานะเอง ─────────────────────
     * `OfflineSyncService` เป็นคนตัดสินว่าแถวนี้เป็น held
     * ถ้าวันหน้าเงื่อนไขการเป็น held เปลี่ยน เทสต์ชุดนี้จะรู้ทันที
     *
     * ── ใช้เหตุ "ยอดบิลเปลี่ยน" ไม่ใช่ "บิลถูกปิดไปแล้ว" ───────────────
     * ทั้งสองทางให้ held เหมือนกัน แต่ทางที่ปิดบิลก่อนจะสร้างแถว payments
     * ทิ้งไว้ด้วย ซึ่งไปบวกใน expectedFor() แล้วทำให้เลขในเทสต์อ่านไม่ออก
     * ว่าตัวไหนมาจากบิลตัวไหนมาจากเงินค้าง — ทางนี้บิลยังเปิดอยู่ ไม่มี payments
     */
    protected function heldEntry(float $received): OfflineSyncEntry
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);
        $orders->addItem($order, $this->noodle, 1);
        $order->refresh();

        $uuid = 'hold-'.$order->id;

        $this->postJson('/pos/offline/sync', ['entries' => [[
            'uuid' => $uuid,
            'kind' => 'pay_cash',
            'order_id' => $order->id,
            'at' => Carbon::now()->toIso8601String(),
            'payload' => [
                // ยอดที่แท็บเล็ตจำไว้ไม่ตรงกับยอดจริง = มีคนแก้บิลตอนเครื่องหลุด
                'expected_total' => 999,
                'received' => $received,
                'change' => 0,
                'slip_no' => 'X1',
                'order_no' => $order->order_no,
            ],
        ]]])->assertOk();

        $entry = OfflineSyncEntry::where('uuid', $uuid)->firstOrFail();

        // พิสูจน์ฟิกซ์เจอร์ก่อน แล้วค่อยทดสอบสิ่งที่ตั้งใจจะทดสอบ
        $this->assertSame(OfflineSyncEntry::STATUS_HELD, $entry->status, 'ฟิกซ์เจอร์ต้องได้แถว held จริง');
        $this->assertSame(number_format($received, 2, '.', ''), $entry->amount);
        $this->assertSame(0, $order->fresh()->payments()->count(), 'ฟิกซ์เจอร์ต้องไม่ทิ้งแถว payments ไว้ปนยอด');

        return $entry;
    }

    protected function resolve(OfflineSyncEntry $entry, string $resolution): void
    {
        $entry->update([
            'resolution' => $resolution,
            'resolution_note' => 'ทดสอบคำตัดสินของผู้จัดการ',
            'resolved_by' => $this->cashier->id,
            'resolved_at' => now(),
        ]);
    }

    protected function shift(float $openingCash, float $countedCash, float $cashIn = 0, float $cashOut = 0): Shift
    {
        return Shift::create([
            'branch_id' => $this->branch->id,
            'shift_no' => 'S'.uniqid(),
            'business_date' => $this->today(),
            'opened_by' => $this->cashier->id,
            'closed_by' => $this->cashier->id,
            'opened_at' => now()->subHours(8),
            'closed_at' => now(),
            'opening_cash' => $openingCash,
            'counted_cash' => $countedCash,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'status' => 'closed',
        ]);
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

    protected function makeBranch(string $code): Branch
    {
        return Branch::create([
            'code' => $code,
            'name' => 'สาขา '.$code,
            'vat_rate' => 0,
            'vat_included' => true,
            'service_charge_rate' => 0,
            'rounding_mode' => 0,
            'business_day_start' => '05:00:00',
            'open_time' => '00:00:00',
            'close_time' => '23:59:59',
        ]);
    }
}
