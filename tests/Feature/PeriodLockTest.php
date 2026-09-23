<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PeriodLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ล็อกงวดบัญชีที่ปิดไปแล้ว (gap-analysis 2.5)
 *
 * ── สิ่งที่เทสต์ชุดนี้คุมเป็นหลัก ───────────────────────────────────────
 * 1. ปิดงวดแล้วต้องแก้บิลของเดือนนั้นไม่ได้จริง ทุกทาง ไม่ใช่แค่ทางที่หน้าเว็บใช้
 * 2. ปิดงวดที่ยังขายอยู่ หรือที่ยังมีบิลเปิดค้าง ต้องปิดไม่ได้ —
 *    ทั้งสองกรณีทำให้มีบิลที่ไม่มีใครแตะได้อีกเลย
 * 3. เปิดงวดกลับต้องเป็นเจ้าของ ต้องมีเหตุผล และต้องทิ้งประวัติไว้
 * 4. งวดของแต่ละสถานีแยกกันจริง
 */
class PeriodLockTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Product $product;

    /** วันขายที่อยู่ในเดือนก่อน — เดือนที่จบไปแล้วแน่นอนไม่ว่าจะรันวันไหน */
    protected Carbon $pastDay;

    protected string $pastPeriod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = $this->makeBranch('AA', 'สาขาหนึ่ง');

        $this->product = Product::create([
            'branch_id' => $this->branch->id,
            'name' => 'หมี่ขาว',
            'price' => 50,
        ]);

        $this->pastDay = Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays(9);
        $this->pastPeriod = $this->pastDay->format('Y-m');
    }

    /* ---------- ปิดงวดแล้วแก้บิลไม่ได้ ---------- */

    public function test_closing_a_period_blocks_editing_bills_from_that_month(): void
    {
        $this->actingAs($this->makeUser('manager'));

        $this->periods()->close($this->branch, $this->pastPeriod);

        $order = $this->backdatedOpenOrder();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ปิดบัญชีไปแล้ว');

        app(OrderService::class)->addItem($order, $this->product, 1);
    }

    public function test_bills_in_a_month_that_is_still_open_are_untouched(): void
    {
        $this->actingAs($this->makeUser('manager'));

        $this->periods()->close($this->branch, $this->pastPeriod);

        // บิลของเดือนนี้ไม่เกี่ยวกับงวดที่ปิด ต้องทำงานได้ตามปกติ
        $order = app(OrderService::class)->open($this->branch);
        app(OrderService::class)->addItem($order, $this->product, 2);

        $this->assertSame('100.00', $order->fresh()->subtotal);
    }

    public function test_refunding_a_bill_from_a_closed_period_is_blocked(): void
    {
        $this->actingAs($this->makeUser('manager'));

        $order = $this->paidOrderMovedToPast();
        $this->periods()->close($this->branch, $this->pastPeriod);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ปิดบัญชีไปแล้ว');

        app(PaymentService::class)->refund($order->fresh(), 50, 'cash', 'ลูกค้าขอคืน');
    }

    public function test_voiding_a_bill_from_a_closed_period_is_blocked(): void
    {
        /*
        | ช่องนี้อันตรายที่สุด — ทำลายบิลเก่าหนึ่งใบ ยอดขายทั้งเดือนที่ยื่นภาษีไปแล้ว
        | เปลี่ยนทันที และ void() ไม่ได้ผ่าน assertEditable() เหมือนเมธอดอื่น
        */
        $this->actingAs($this->makeUser('manager'));

        $order = $this->paidOrderMovedToPast();
        $this->periods()->close($this->branch, $this->pastPeriod);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ปิดบัญชีไปแล้ว');

        app(OrderService::class)->void($order->fresh(), 'กดผิด');
    }

    public function test_paying_a_bill_left_open_in_a_closed_period_is_blocked(): void
    {
        $this->actingAs($this->makeUser('manager'));

        // ปิดงวดตอนยังไม่มีบิล แล้วค่อยย้ายบิลที่ยังเปิดอยู่ย้อนเข้าไปในงวดนั้น
        $this->periods()->close($this->branch, $this->pastPeriod);

        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);
        $orders->addItem($order, $this->product, 1);
        $order->fresh()->update(['business_date' => $this->pastDay->toDateString()]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ปิดบัญชีไปแล้ว');

        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'cash', 'amount' => 50, 'received' => 50],
        ]);
    }

    /* ---------- เงื่อนไขตอนปิดงวด ---------- */

    public function test_a_month_that_has_not_ended_cannot_be_closed(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ยังไม่จบ');

        $this->periods()->close($this->branch, Carbon::now()->format('Y-m'));
    }

    public function test_a_period_with_open_bills_cannot_be_closed(): void
    {
        $this->actingAs($this->makeUser('manager'));

        $this->backdatedOpenOrder();

        try {
            $this->periods()->close($this->branch, $this->pastPeriod);
            $this->fail('ควรปิดงวดไม่ได้เมื่อยังมีบิลเปิดค้าง');
        } catch (\DomainException $e) {
            // ต้องบอกจำนวนด้วย ไม่ใช่แค่ "ปิดไม่ได้" แล้วปล่อยให้ไปหาเอง
            $this->assertStringContainsString('1 ใบ', $e->getMessage());
        }

        $this->assertDatabaseCount('accounting_periods', 0);
    }

    public function test_closing_the_same_period_twice_is_refused(): void
    {
        $this->periods()->close($this->branch, $this->pastPeriod);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ปิดไปแล้ว');

        $this->periods()->close($this->branch, $this->pastPeriod);
    }

    public function test_each_station_closes_its_own_periods(): void
    {
        $other = $this->makeBranch('BB', 'สาขาสอง');

        $this->periods()->close($this->branch, $this->pastPeriod);

        $this->assertTrue($this->periods()->isLocked($this->branch->id, $this->pastDay));
        $this->assertFalse(
            $this->periods()->isLocked($other->id, $this->pastDay),
            'ปิดงวดของสถานีหนึ่งต้องไม่ล็อกสถานีอื่นไปด้วย'
        );
    }

    /* ---------- เปิดงวดกลับ ---------- */

    public function test_the_owner_can_reopen_a_closed_period_with_a_reason(): void
    {
        $owner = $this->makeUser('owner');
        $this->actingAs($owner);

        $this->periods()->close($this->branch, $this->pastPeriod);
        $record = $this->periods()->reopen($this->branch, $this->pastPeriod, $owner, 'ลูกค้าขอคืนเงินบิลที่ออกผิด');

        $this->assertSame(AccountingPeriod::REOPENED, $record->status);
        $this->assertSame(1, $record->times_reopened);
        $this->assertSame($owner->id, $record->reopened_by);
        $this->assertFalse($this->periods()->isLocked($this->branch->id, $this->pastDay));

        // แก้บิลได้จริงหลังเปิดกลับ ไม่ใช่แค่สถานะเปลี่ยน
        $order = $this->backdatedOpenOrder();
        app(OrderService::class)->addItem($order, $this->product, 1);

        $this->assertSame('50.00', $order->fresh()->subtotal);
    }

    public function test_a_manager_cannot_reopen_a_closed_period(): void
    {
        $manager = $this->makeUser('manager');

        $this->periods()->close($this->branch, $this->pastPeriod);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('เจ้าของระบบ');

        $this->periods()->reopen($this->branch, $this->pastPeriod, $manager, 'อยากแก้บิลเฉย ๆ');
    }

    public function test_reopening_needs_a_real_reason(): void
    {
        $owner = $this->makeUser('owner');

        $this->periods()->close($this->branch, $this->pastPeriod);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('เหตุผล');

        $this->periods()->reopen($this->branch, $this->pastPeriod, $owner, 'ok');
    }

    public function test_a_period_that_is_not_closed_cannot_be_reopened(): void
    {
        $owner = $this->makeUser('owner');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ไม่ได้ปิดอยู่');

        $this->periods()->reopen($this->branch, $this->pastPeriod, $owner, 'ไม่ได้ปิดไว้ตั้งแต่แรก');
    }

    public function test_closing_again_after_a_reopen_keeps_the_history(): void
    {
        $owner = $this->makeUser('owner');
        $this->actingAs($owner);

        $this->periods()->close($this->branch, $this->pastPeriod);
        $this->periods()->reopen($this->branch, $this->pastPeriod, $owner, 'แก้บิลที่ออกผิดเลขที่');
        $record = $this->periods()->close($this->branch, $this->pastPeriod);

        $this->assertSame(AccountingPeriod::CLOSED, $record->status);
        $this->assertSame(1, $record->times_reopened, 'ประวัติการเปิดกลับต้องไม่หายเมื่อปิดใหม่');
        $this->assertNotNull($record->reopen_reason);
        $this->assertDatabaseCount('accounting_periods', 1);
    }

    public function test_closing_and_reopening_are_written_to_the_activity_log(): void
    {
        $owner = $this->makeUser('owner');
        $this->actingAs($owner);

        $this->periods()->close($this->branch, $this->pastPeriod);
        $this->periods()->reopen($this->branch, $this->pastPeriod, $owner, 'ลูกค้าขอคืนเงินบิลที่ออกผิด');

        $this->assertDatabaseHas('activity_logs', ['action' => 'period.close']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'period.reopen']);
    }

    /* ---------- สรุปบนหน้าจอ ---------- */

    public function test_the_overview_shows_the_status_and_totals_of_each_month(): void
    {
        $this->actingAs($this->makeUser('manager'));

        $this->paidOrderMovedToPast();
        $this->periods()->close($this->branch, $this->pastPeriod);

        $rows = collect($this->periods()->overview($this->branch, 3));
        $past = $rows->firstWhere('period', $this->pastPeriod);
        $current = $rows->firstWhere('period', Carbon::now()->format('Y-m'));

        $this->assertCount(3, $rows);

        $this->assertTrue($past['is_closed']);
        $this->assertTrue($past['has_ended']);
        $this->assertSame(1, $past['bills']);
        $this->assertSame(50.0, $past['sales']);
        $this->assertSame(0, $past['open_bills']);

        $this->assertFalse($current['is_closed']);
        $this->assertFalse($current['has_ended'], 'เดือนที่ยังขายอยู่ต้องปิดไม่ได้');
    }

    /* ---------- สิทธิ์และหน้าเว็บ ---------- */

    public function test_a_manager_can_open_the_periods_page(): void
    {
        $this->actingAs($this->makeUser('manager'));

        $this->get('/backoffice/periods')->assertOk();
    }

    public function test_a_cashier_cannot_open_the_periods_page(): void
    {
        $this->actingAs($this->makeUser('cashier'));

        $this->get('/backoffice/periods')->assertForbidden();
    }

    public function test_closing_a_period_through_the_page(): void
    {
        $this->actingAs($this->makeUser('manager'));

        $this->post('/backoffice/periods/close', [
            'period' => $this->pastPeriod,
            'note' => 'ยื่น ภ.พ.30 แล้ว',
        ])->assertRedirect();

        $this->assertDatabaseHas('accounting_periods', [
            'branch_id' => $this->branch->id,
            'period' => $this->pastPeriod,
            'status' => AccountingPeriod::CLOSED,
            'note' => 'ยื่น ภ.พ.30 แล้ว',
        ]);
    }

    public function test_a_manager_who_posts_the_reopen_form_is_refused(): void
    {
        /*
        | route ใช้สิทธิ์ period.close ซึ่งผู้จัดการมี — เข้าถึง endpoint ได้
        | ด่านที่กันจริงอยู่ในเซอร์วิส ไม่ใช่ที่ middleware
        */
        $this->actingAs($this->makeUser('manager'));

        $this->periods()->close($this->branch, $this->pastPeriod);

        $this->post('/backoffice/periods/reopen', [
            'period' => $this->pastPeriod,
            'reason' => 'อยากแก้บิลย้อนหลัง',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('accounting_periods', [
            'period' => $this->pastPeriod,
            'status' => AccountingPeriod::CLOSED,
        ]);
    }

    public function test_a_short_reason_is_rejected_by_the_form(): void
    {
        $this->actingAs($this->makeUser('owner'));

        $this->periods()->close($this->branch, $this->pastPeriod);

        $this->post('/backoffice/periods/reopen', [
            'period' => $this->pastPeriod,
            'reason' => 'ok',
        ])->assertSessionHasErrors('reason');
    }

    /* ---------- รูปแบบงวด ---------- */

    public function test_period_strings_are_normalised(): void
    {
        $periods = $this->periods();

        $this->assertSame('2026-08', $periods->normalise('2026-8'));
        $this->assertSame('2026-08', $periods->normalise('2026-08'));
        $this->assertSame('2026-08', $periods->normalise('2026-08-31'));
        $this->assertSame('2026-08', $periods->periodOf(Carbon::parse('2026-08-15')));
        $this->assertSame('2026-08-01', $periods->startOf('2026-08')->toDateString());
        $this->assertSame('2026-08-31', $periods->endOf('2026-08')->toDateString());
        $this->assertSame('2569', substr($periods->thaiLabel('2026-08'), -4));
    }

    /* ---------- ตัวช่วย ---------- */

    protected function periods(): PeriodLockService
    {
        return app(PeriodLockService::class);
    }

    /** บิลที่ยังเปิดอยู่ แต่วันขายย้อนไปอยู่ในเดือนก่อน */
    protected function backdatedOpenOrder(): Order
    {
        $order = app(OrderService::class)->open($this->branch);
        $order->update(['business_date' => $this->pastDay->toDateString()]);

        return $order->fresh();
    }

    /** บิลที่จ่ายเงินแล้ว แล้วย้ายวันขายไปอยู่ในเดือนก่อน */
    protected function paidOrderMovedToPast(): Order
    {
        $orders = app(OrderService::class);

        $order = $orders->open($this->branch);
        $orders->addItem($order, $this->product, 1);

        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'cash', 'amount' => 50, 'received' => 50],
        ]);

        $order->fresh()->update(['business_date' => $this->pastDay->toDateString()]);

        return $order->fresh();
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

    protected function makeUser(string $role): User
    {
        return User::create([
            'branch_id' => $this->branch->id,
            'name' => "ผู้ใช้ {$role}",
            'email' => $role.'@test.local',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
