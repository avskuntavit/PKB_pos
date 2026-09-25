<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentCharge;
use App\Models\PaymentProviderAccount;
use App\Models\Product;
use App\Models\User;
use App\Payments\ChargeResult;
use App\Payments\ChargeStatusResult;
use App\Payments\ExpiryWindow;
use App\Payments\PaymentGateway;
use App\Payments\PollSchedule;
use App\Services\OrderService;
use App\Services\PaymentChargeService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * รับเงินผ่าน QR ของผู้ให้บริการชำระเงิน
 *
 * ── ทำไมชุดนี้สำคัญกว่าเทสต์ทั่วไป ──────────────────────────────────────
 * นี่คือเส้นทางที่เงินเข้าระบบโดย **ไม่มีคนยืนดู** — ตัวตั้งเวลาเป็นคนตัดสิน
 * ความผิดพลาดที่นี่ไม่มีใครเห็นตอนเกิด และไปโผล่ตอนกระทบยอดปลายเดือน
 *
 * ── สามข้อที่ชุดนี้คุมเป็นหลัก ──────────────────────────────────────────
 * 1. ไล่ถามซ้ำกี่รอบก็ลงบิลครั้งเดียว
 * 2. ยอดไม่ตรงห้ามปิดบิล — ไม่ว่าจะขาดหรือเกิน
 * 3. บิลถูกปิดด้วยเงินสดไปก่อนแล้วเงินโอนเข้าทีหลัง เงินก้อนนั้นต้องไม่หาย
 *
 * เกตเวย์จริงถูกแทนด้วยตัวปลอมที่ผูกผ่าน container ทางเดียวกับที่เจ้าใหม่จะเข้ามา
 * จึงได้ทดสอบเส้นทางเงินทั้งเส้นโดยไม่ต้องยิง API ของใคร
 */
class PaymentChargeTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Product $noodle;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = $this->makeBranch('PC1');

        $category = Category::create(['branch_id' => $this->branch->id, 'name' => 'ก๋วยเตี๋ยว']);

        $this->noodle = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'หมี่ขาว',
            'price' => 100,
            'cost' => 30,
        ]);

        $this->cashier = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'แคชเชียร์',
            'email' => 'pc@foodpos.test',
            'password' => 'password',
            'role' => 'cashier',
        ]);

        $this->actingAs($this->cashier);
    }

    /* ---------- QR ของร้านเอง (ไม่มีเกตเวย์) ---------- */

    public function test_the_shop_can_issue_its_own_promptpay_qr_without_any_gateway(): void
    {
        $charge = app(PaymentChargeService::class)->open($this->bill(2), $this->cashier);

        $this->assertSame(PaymentProvider::Static_, $charge->provider);
        $this->assertSame(ChargeStatus::Pending, $charge->status);
        $this->assertSame('200.00', $charge->amount);
        $this->assertNotNull($charge->qr_payload, 'ต้องได้ข้อความ EMVCo ไปวาดเป็น QR');
        $this->assertNull($charge->provider_charge_id, 'ไม่มีเกตเวย์ จึงไม่มี id ของเกตเวย์');
    }

    public function test_the_shops_own_qr_never_claims_the_money_arrived(): void
    {
        /*
        | ด่านที่สำคัญที่สุดของ driver static
        |
        | มันตรวจยอดไม่ได้ และต้องไม่แกล้งตอบว่าจ่ายแล้ว
        | การเดาว่าเงินเข้าคือการปิดบิลที่ไม่มีเงินจริง
        */
        $charge = app(PaymentChargeService::class)->open($this->bill(2), $this->cashier);

        $after = app(PaymentChargeService::class)->poll($charge);

        $this->assertSame(ChargeStatus::Pending, $after->status);
        $this->assertStringContainsString('ดูสลิป', (string) $after->failure_message);
        $this->assertSame(OrderStatus::Open, $after->order->status);
    }

    public function test_the_polling_round_skips_charges_that_have_no_gateway_to_ask(): void
    {
        app(PaymentChargeService::class)->open($this->bill(2), $this->cashier);

        // ใบของ static ไม่มี provider_charge_id — ไม่มีใครให้ถาม ไม่ต้องเสียรอบ
        $this->assertSame(0, app(PaymentChargeService::class)->pollOpen()['polled']);
    }

    public function test_a_branch_without_promptpay_set_up_gets_a_clear_refusal(): void
    {
        $this->branch->update(['promptpay_id' => null]);

        try {
            app(PaymentChargeService::class)->open($this->bill(2), $this->cashier);
            $this->fail('ต้องโยน exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ตั้งพร้อมเพย์', $e->getMessage());
        }

        // แถวยังอยู่เพื่อตอบได้ว่าเคยพยายามออกแล้วพลาด แต่ถูกปิดเป็น failed
        $this->assertSame(ChargeStatus::Failed, PaymentCharge::firstOrFail()->status);
    }

    /* ---------- กดโชว์ QR ซ้ำ ---------- */

    public function test_showing_the_qr_again_reuses_the_same_charge(): void
    {
        // พนักงานกดโชว์สองครั้งเป็นเรื่องปกติ ถ้าออกใบใหม่ทุกครั้ง
        // เกตเวย์จะมีรายการค้างเป็นสิบใบต่อบิล แล้วกระทบยอดไม่ได้
        $order = $this->bill(2);
        $charges = app(PaymentChargeService::class);

        $this->assertSame(
            $charges->open($order, $this->cashier)->id,
            $charges->open($order->fresh(), $this->cashier)->id,
        );
        $this->assertSame(1, PaymentCharge::count());
    }

    public function test_adding_a_dish_after_the_qr_was_shown_replaces_it(): void
    {
        $order = $this->bill(2);
        $charges = app(PaymentChargeService::class);

        $first = $charges->open($order, $this->cashier);

        app(OrderService::class)->addItem($order, $this->noodle, 1);

        $second = $charges->open($order->fresh(), $this->cashier);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(ChargeStatus::Cancelled, $first->fresh()->status, 'ใบเก่าต้องถูกปิด ไม่ค้างสองใบ');
        $this->assertSame('300.00', $second->amount);
    }

    public function test_a_closed_bill_cannot_be_given_a_new_qr(): void
    {
        $order = $this->bill(1);

        app(PaymentService::class)->pay($order->refresh(), [
            ['method' => 'cash', 'amount' => 100, 'received' => 100],
        ]);

        $this->expectExceptionMessage('บิลนี้ปิดไปแล้ว');

        app(PaymentChargeService::class)->open($order->fresh(), $this->cashier);
    }

    public function test_an_empty_bill_cannot_be_given_a_qr(): void
    {
        $this->expectExceptionMessage('ยังไม่มียอดให้เก็บเงิน');

        app(PaymentChargeService::class)->open(
            app(OrderService::class)->open($this->branch),
            $this->cashier,
        );
    }

    /* ---------- เงินเข้าครบ ---------- */

    public function test_an_online_bill_closes_itself_when_the_money_lands(): void
    {
        // สั่งจ่ายก่อนมารับ ไม่มีพนักงานยืนอยู่กับลูกค้าตอนจ่าย ปิดเองคือเรื่องที่ต้องการ
        [$order, $charge] = $this->gatewayBill(source: OrderSource::Online, dishes: 2);

        $this->fakeGateway()->willPay(200.0);

        $after = app(PaymentChargeService::class)->poll($charge);

        $this->assertSame(ChargeStatus::Paid, $after->status);
        $this->assertSame('200.00', $after->paid_amount);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(1, $order->fresh()->payments()->count());
        $this->assertNotNull($after->settled_payment_id, 'ต้องบอกได้ว่าเงินก้อนนี้ไปเข้าแถวไหน');
        $this->assertNotNull($order->fresh()->receipt_no);
    }

    public function test_a_counter_bill_waits_for_the_cashier_even_after_the_money_lands(): void
    {
        /*
        | โต๊ะข้างกันอาจถูกปิดผิดใบ ถ้าระบบปิดเองทันทีที่เงินเข้า
        | จึงขึ้นให้พนักงานเห็นว่า "จ่ายแล้ว รอปิดบิล" แล้วให้คนกด
        */
        [$order, $charge] = $this->gatewayBill(source: OrderSource::Pos, dishes: 2);

        $this->fakeGateway()->willPay(200.0);

        $after = app(PaymentChargeService::class)->poll($charge);

        $this->assertSame(ChargeStatus::Paid, $after->status);
        $this->assertSame(OrderStatus::Open, $order->fresh()->status, 'บิลต้องยังเปิดอยู่');
        $this->assertSame(0, $order->fresh()->payments()->count(), 'ยังไม่มีแถวรับเงิน');

        $waiting = app(PaymentChargeService::class)->awaitingCashier($order->fresh());

        $this->assertSame($charge->id, $waiting?->id, 'หน้า POS ต้องหาใบนี้เจอเพื่อขึ้นเตือน');
    }

    public function test_asking_the_gateway_again_never_books_the_money_twice(): void
    {
        /*
        | ด่านกันลงซ้ำ — ตัวไล่ถามเดินทุกนาที และสองเครื่องอาจถามพร้อมกัน
        | บั๊กคลาสเดียวกับคิวออฟไลน์ที่เคยแก้ไปแล้ว: select มาดูก่อนแล้วค่อย update
        | ทำให้ทั้งสองฝ่ายเห็นว่า "ยังไม่ลง"
        */
        [$order, $charge] = $this->gatewayBill(source: OrderSource::Online, dishes: 2);

        $this->fakeGateway()->willPay(200.0);

        $charges = app(PaymentChargeService::class);
        $charges->poll($charge);
        $charges->poll($charge->fresh());
        $charges->poll($charge->fresh());

        $this->assertSame(1, $order->fresh()->payments()->count(), 'ถามสามรอบ ต้องมีแถวรับเงินใบเดียว');
        $this->assertSame('200.00', $order->fresh()->paid_amount);
    }

    /* ---------- ยอดไม่ตรง ---------- */

    public function test_money_short_of_the_bill_is_never_booked(): void
    {
        [$order, $charge] = $this->gatewayBill(source: OrderSource::Online, dishes: 2);

        $this->fakeGateway()->willPay(150.0);

        $after = app(PaymentChargeService::class)->poll($charge);

        $this->assertSame(ChargeStatus::Mismatch, $after->status);
        $this->assertTrue($after->status->needsDecision());
        $this->assertSame('150.00', $after->paid_amount, 'ยอดที่เข้ามาจริงต้องถูกบันทึกไว้');
        $this->assertSame(OrderStatus::Open, $order->fresh()->status, 'ห้ามปิดบิลแล้วทิ้งส่วนต่าง');
        $this->assertSame(0, $order->fresh()->payments()->count());
    }

    public function test_money_over_the_bill_also_waits_for_a_decision(): void
    {
        // เงินส่วนเกินต้องมีคนตัดสินว่าคืนลูกค้าหรือบันทึกเป็นรับเกิน
        [$order, $charge] = $this->gatewayBill(source: OrderSource::Online, dishes: 2);

        $this->fakeGateway()->willPay(250.0);

        $after = app(PaymentChargeService::class)->poll($charge);

        $this->assertSame(ChargeStatus::Mismatch, $after->status);
        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
    }

    public function test_a_bill_that_grew_after_the_qr_was_scanned_waits_for_a_decision(): void
    {
        /*
        | ลูกค้าสแกนยอด 200 แล้วสั่งเพิ่มอีกจานก่อนเงินจะเข้า
        | เงินที่เข้ามาถูกต้องตามที่ขอ แต่ไม่ใช่ยอดบิลแล้ว — ปิดบิลไม่ได้
        */
        [$order, $charge] = $this->gatewayBill(source: OrderSource::Online, dishes: 2);

        app(OrderService::class)->addItem($order, $this->noodle, 1);

        $this->fakeGateway()->willPay(200.0);

        $after = app(PaymentChargeService::class)->poll($charge);

        $this->assertSame(ChargeStatus::Mismatch, $after->status);
        $this->assertStringContainsString('ยอดบิลเปลี่ยน', (string) $after->failure_message);
    }

    public function test_money_that_arrives_after_the_bill_was_paid_in_cash_is_not_lost(): void
    {
        /*
        | เคสที่แพงที่สุด — ลูกค้าสแกนแล้วเน็ตช้า พนักงานเลยรับเงินสดแทน
        | แล้วเงินโอนเข้าทีหลัง ถ้าระบบเมินเงินก้อนนี้ ร้านได้เงินเกินแบบไม่มีใครรู้
        | และลูกค้าที่จ่ายสองรอบจะไม่มีใครคืนให้
        */
        [$order, $charge] = $this->gatewayBill(source: OrderSource::Pos, dishes: 2);

        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'cash', 'amount' => 200, 'received' => 200],
        ]);

        $this->fakeGateway()->willPay(200.0);

        $after = app(PaymentChargeService::class)->poll($charge);

        $this->assertSame(ChargeStatus::Unmatched, $after->status);
        $this->assertSame('200.00', $after->paid_amount);
        $this->assertTrue($after->status->hasMoney());
        $this->assertSame(1, $order->fresh()->payments()->count(), 'ต้องไม่บันทึกเงินซ้ำเข้าบิลเดิม');
        $this->assertSame(1, PaymentCharge::needsDecision()->count(), 'ต้องไปโผล่ในรายการรอตัดสิน');
    }

    /* ---------- เกตเวย์งอแง ---------- */

    public function test_a_gateway_we_cannot_reach_leaves_the_charge_open_for_the_next_round(): void
    {
        /*
        | ถามไม่ได้ ≠ ยังไม่จ่าย
        | ถ้าปิดเป็น failed ตรงนี้ เงินที่เข้าไปแล้วจะไม่มีใครมาเก็บอีกเลย
        */
        [, $charge] = $this->gatewayBill(source: OrderSource::Online, dishes: 2);

        $this->fakeGateway()->willThrow('เน็ตหลุด');

        $after = app(PaymentChargeService::class)->poll($charge);

        $this->assertSame(ChargeStatus::Pending, $after->status, 'ต้องค้างไว้ให้รอบหน้ามาถามอีก');
        $this->assertSame(1, $after->poll_attempts);
        $this->assertStringContainsString('ถามเกตเวย์ไม่ได้', (string) $after->failure_message);
        $this->assertNotNull($after->last_polled_at);
    }

    public function test_a_gateway_that_says_expired_closes_the_charge(): void
    {
        [$order, $charge] = $this->gatewayBill(source: OrderSource::Online, dishes: 2);

        $this->fakeGateway()->willReturn(new ChargeStatusResult(ChargeStatus::Expired, message: 'หมดเวลา'));

        $after = app(PaymentChargeService::class)->poll($charge);

        $this->assertSame(ChargeStatus::Expired, $after->status);
        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
    }

    public function test_a_driver_cannot_decide_that_money_needs_a_human(): void
    {
        /*
        | mismatch / unmatched เป็นข้อสรุปของฝั่งเราหลังเทียบกับบิล
        | เกตเวย์ไม่รู้ว่าบิลเราเท่าไหร่หรือถูกปิดไปแล้วหรือยัง
        | ถ้าปล่อยให้ driver ตอบได้ กฎการเทียบยอดจะกระจายไปอยู่ในทุก driver
        */
        $this->expectException(\InvalidArgumentException::class);

        new ChargeStatusResult(ChargeStatus::Unmatched);
    }

    /* ---------- อายุ QR ---------- */

    public function test_the_expiry_the_shop_asked_for_is_squeezed_into_what_the_gateway_accepts(): void
    {
        /*
        | ร้านตั้ง 30 นาที แต่เจ้านี้รับสูงสุด 10 นาที (แบบ Beam Bolt)
        | ถ้าส่งไปตรง ๆ เกตเวย์ปฏิเสธทั้งคำขอ แล้วพนักงานเห็นแค่ "ออก QR ไม่ได้"
        */
        config(['pos.payments.expire_minutes' => 30]);

        [, $charge] = $this->gatewayBill(source: OrderSource::Pos, dishes: 2);

        $this->assertSame(
            600,
            (int) $charge->created_at->diffInSeconds($charge->expires_at),
            'ต้องถูกบีบลงมาเป็น 600 วินาทีตามเพดานของเจ้านี้',
        );
    }

    public function test_an_expiry_shorter_than_the_gateway_allows_is_pushed_up(): void
    {
        // ตั้งหนึ่งนาที แต่เจ้านี้รับขั้นต่ำ 90 วินาที
        config(['pos.payments.expire_minutes' => 1]);

        [, $charge] = $this->gatewayBill(source: OrderSource::Pos, dishes: 2);

        $this->assertSame(90, (int) $charge->created_at->diffInSeconds($charge->expires_at));
    }

    public function test_the_shops_own_qr_never_expires(): void
    {
        $charge = app(PaymentChargeService::class)->open($this->bill(2), $this->cashier);

        $this->assertNull($charge->expires_at, 'QR คงที่ของร้านไม่มีวันหมดอายุ');
    }

    public function test_an_expired_charge_is_closed_without_asking_the_gateway(): void
    {
        /*
        | ถูกต้องกว่า (เกตเวย์ก็จะตอบว่าหมดอายุ) และประหยัดการเรียก API
        | ซึ่งสำคัญเพราะ Beam คืน HTTP 429 ถ้าถามถี่เกิน
        */
        [, $charge] = $this->gatewayBill(source: OrderSource::Pos, dishes: 2);

        // ถ้าไปถามจริงจะได้ว่าจ่ายแล้ว — เทสต์นี้พิสูจน์ว่าไม่มีการถามเกิดขึ้น
        $this->fakeGateway()->willPay(200.0);

        $charge->update(['expires_at' => now()->subMinute()]);

        $stat = app(PaymentChargeService::class)->pollOpen();

        $this->assertSame(0, $stat['polled'], 'ต้องไม่ถามเกตเวย์เลย');
        $this->assertSame(1, $stat['expired']);
        $this->assertSame(ChargeStatus::Expired, $charge->fresh()->status);
    }

    public function test_a_charge_asked_a_moment_ago_is_not_asked_again_in_the_same_minute(): void
    {
        // ถามถี่เกินคือเหตุผลที่ Beam คืน 429 — ตารางถามจึงเว้นระยะให้
        [, $charge] = $this->gatewayBill(source: OrderSource::Pos, dishes: 2);

        $charges = app(PaymentChargeService::class);

        $this->assertSame(1, $charges->pollOpen()['polled'], 'รอบแรกต้องถาม');
        $this->assertSame(0, $charges->pollOpen()['polled'], 'รอบถัดมาทันทีต้องข้าม');
        $this->assertSame(1, $charges->pollOpen()['skipped']);
    }

    public function test_it_comes_back_to_a_charge_once_enough_time_has_passed(): void
    {
        [, $charge] = $this->gatewayBill(source: OrderSource::Pos, dishes: 2);

        $charges = app(PaymentChargeService::class);
        $charges->pollOpen();

        /*
        | ระยะห่างอ่านจาก PollSchedule เอง ไม่ใช่เขียนตัวเลขซ้ำไว้ที่นี่
        |
        | เดิมด่านนี้เขียนไว้ว่า "นาทีแรกถามห่างกัน 15 วินาที" แล้วเลื่อนเวลาไป 20 วินาที
        | ซึ่งเป็นตารางเก่า ตอนแก้ PollSchedule ให้มีพื้นที่ 60 วินาที (เพราะตัวตั้งเวลา
        | รันนาทีละครั้ง เร็วกว่านั้นไม่ได้จริง) ด่านนี้ไม่ได้ถูกแก้ตาม
        | และมันถูกบังไว้ด้วยฟิกซ์เจอร์ที่ตกก่อนถึงบรรทัดนี้ จึงไม่มีใครเห็นว่ามันค้างอยู่
        |
        | อ่านจากตารางตรง ๆ แล้วด่านนี้จะตามการแก้ตารางไปเองตลอด
        */
        $interval = PollSchedule::intervalFor(0);

        $this->assertSame(60, $interval, 'เพดานคือตัวตั้งเวลาที่รันนาทีละครั้ง — เร็วกว่านี้ไม่ได้');

        $this->travel($interval + 1)->seconds();

        $this->assertSame(1, $charges->pollOpen()['polled']);
    }

    public function test_the_amount_handed_to_the_gateway_is_in_satang(): void
    {
        /*
        | Stripe และ Beam รับยอดเป็นสตางค์ (100 บาท = 10000)
        | ผิดหน่วยคือผิด 100 เท่า และผิดแบบที่เทสต์ในร้านมองไม่เห็น
        */
        [, $charge] = $this->gatewayBill(source: OrderSource::Pos, dishes: 2);

        $this->assertSame('200.00', $charge->amount);
        $this->assertSame(20000, $charge->raw['amount_satang']);
    }

    /* ---------- เลือกผู้ให้บริการ ---------- */

    public function test_a_branch_with_no_account_set_up_falls_back_to_its_own_qr(): void
    {
        // ร้านต้องรับเงินได้ต่อแม้ยังไม่ได้ตั้งเกตเวย์ — ไม่ใช่โยน error ใส่หน้าพนักงาน
        $this->assertSame(
            PaymentProvider::Static_,
            app(PaymentChargeService::class)->providerFor($this->branch),
        );
    }

    public function test_an_account_that_is_switched_on_and_filled_in_takes_over(): void
    {
        $this->usableAccount(PaymentProvider::Beam);

        $this->assertSame(
            PaymentProvider::Beam,
            app(PaymentChargeService::class)->providerFor($this->branch->fresh()),
        );
    }

    public function test_an_account_missing_one_required_key_does_not_take_over(): void
    {
        /*
        | กรอกมาช่องเดียวแล้วเปิดใช้งานไว้ = ยังใช้งานไม่ได้
        |
        | ถ้ายอมให้เจ้านี้ชนะ QR ทุกใบจะวิ่งไปหา driver ที่ไม่มีกุญแจครบ
        | แล้วพนักงานเห็นแค่ข้อความของเกตเวย์ที่คนอ่านไม่รู้เรื่อง
        | ถอยไป QR ของร้านดีกว่า — ตรวจยอดอัตโนมัติไม่ได้ แต่ยังรับเงินได้
        */
        $this->account(PaymentProvider::Beam, active: true, credentials: ['api_key' => 'k']);

        $this->assertSame(['merchant_id'], PaymentProviderAccount::firstOrFail()->missingKeys());
        $this->assertSame(
            PaymentProvider::Static_,
            app(PaymentChargeService::class)->providerFor($this->branch->fresh()),
        );
    }

    public function test_an_account_with_no_keys_yet_does_not_take_over(): void
    {
        // เปิดสวิตช์แต่ยังไม่กรอกกุญแจ = ยังใช้ไม่ได้ ต้องไม่พาร้านไปติดตรงนั้น
        $this->account(PaymentProvider::Beam, active: true, credentials: null);

        $this->assertSame(
            PaymentProvider::Static_,
            app(PaymentChargeService::class)->providerFor($this->branch->fresh()),
        );
    }

    public function test_a_switched_off_account_does_not_take_over(): void
    {
        /*
        | กุญแจครบ **โดยตั้งใจ** — ด่านนี้ต้องวัดผลของสวิตช์เท่านั้น
        | ถ้าปล่อยให้กุญแจไม่ครบด้วย ด่านจะผ่านได้สองเหตุผล
        | แล้ววันที่กฎเรื่องสวิตช์พัง ด่านนี้จะยังเขียวอยู่เพราะกุญแจไม่ครบช่วยบังไว้
        */
        $credentials = [];
        foreach (PaymentProvider::Beam->requiredCredentialKeys() as $key) {
            $credentials[$key] = 'test-'.$key;
        }

        $this->account(PaymentProvider::Beam, active: false, credentials: $credentials);

        $this->assertSame(
            PaymentProvider::Static_,
            app(PaymentChargeService::class)->providerFor($this->branch->fresh()),
        );
    }

    public function test_another_branchs_account_is_never_used_here(): void
    {
        $other = $this->makeBranch('PC2');

        PaymentProviderAccount::create([
            'branch_id' => $other->id,
            'provider' => PaymentProvider::Beam,
            'mode' => 'live',
            'is_active' => true,
            'credentials' => ['api_key' => 'k'],
            'activated_at' => now(),
        ]);

        $this->assertSame(
            PaymentProvider::Static_,
            app(PaymentChargeService::class)->providerFor($this->branch->fresh()),
        );
    }

    public function test_a_provider_with_no_connector_written_yet_says_so_out_loud(): void
    {
        // ถอยไป static เงียบ ๆ ไม่ได้ — ร้านที่ตั้งใจเก็บผ่านเกตเวย์
        // จะได้ QR ที่ตรวจยอดไม่ได้โดยไม่รู้ตัว
        $this->expectExceptionMessage('ยังไม่มีตัวเชื่อมของ');

        app(PaymentChargeService::class)->gateway(PaymentProvider::Stripe);
    }

    public function test_the_gateway_keys_are_not_readable_straight_from_the_table(): void
    {
        /*
        | ค่ากุญแจเป็น ASCII **โดยตั้งใจ**
        |
        | cast 'encrypted:array' เรียก json_encode ซึ่ง escape อักษรไทยเป็น \uXXXX
        | ถ้าใช้ค่าภาษาไทย assertStringNotContainsString จะผ่านทุกครั้ง
        | แม้คอลัมน์จะไม่ถูกเข้ารหัสเลย — ด่านจะกลายเป็นด่านที่ปิดอยู่
        */
        $this->account(PaymentProvider::Beam, active: true, credentials: ['api_key' => 'SECRET_VALUE_1234']);

        $raw = (string) DB::table('payment_provider_accounts')->value('credentials');

        $this->assertStringNotContainsString('SECRET_VALUE_1234', $raw, 'กุญแจต้องถูกเข้ารหัสในฐานข้อมูล');
        $this->assertSame('SECRET_VALUE_1234', PaymentProviderAccount::firstOrFail()->secret('api_key'));
    }

    /* ---------- ตัวช่วย ---------- */

    protected function bill(int $dishes): Order
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);
        $orders->addItem($order, $this->noodle, $dishes);

        return $order->refresh();
    }

    /**
     * บิลที่ออก QR ผ่านเกตเวย์ปลอมไว้แล้ว
     *
     * @return array{0: Order, 1: PaymentCharge}
     */
    protected function gatewayBill(OrderSource $source, int $dishes): array
    {
        $this->usableAccount(PaymentProvider::Beam);
        $this->fakeGateway();

        $order = $this->bill($dishes);
        $order->forceFill(['source' => $source])->save();

        $charge = app(PaymentChargeService::class)->open($order->fresh(), $this->cashier);

        // พิสูจน์ฟิกซ์เจอร์ก่อน — ต้องวิ่งผ่านเกตเวย์ปลอมจริง ไม่ใช่ถอยไป static
        $this->assertSame(PaymentProvider::Beam, $charge->provider);
        $this->assertNotNull($charge->provider_charge_id);

        return [$order->fresh(), $charge];
    }

    /** เกตเวย์ปลอม ผูกผ่าน container ทางเดียวกับที่เจ้าใหม่จะเข้ามา */
    protected function fakeGateway(): FakeGateway
    {
        if (! app()->bound('payment.gateway.beam')) {
            app()->instance('payment.gateway.beam', new FakeGateway);
        }

        return app('payment.gateway.beam');
    }

    /**
     * บัญชีที่กรอกกุญแจครบตามที่เจ้านั้นต้องการจริง
     *
     * ── ทำไมไม่เขียนชื่อกุญแจไว้ในฟิกซ์เจอร์เอง ────────────────────────────
     * `providerFor()` เรียก `isUsable()` ซึ่งเทียบกับ `requiredCredentialKeys()`
     * ของเจ้านั้น ฟิกซ์เจอร์ที่เขียนชื่อกุญแจไว้เองจะล้าสมัยเงียบ ๆ ทันทีที่เจ้านั้น
     * ต้องการกุญแจเพิ่ม แล้วเทสต์จะตกด้วยเหตุผลที่อ่านไม่ออก ("ทำไมได้ static")
     * ตัวนี้ถามรายชื่อจากแหล่งเดียวกับโค้ดจริง จึงตามกันไปเสมอ
     */
    protected function usableAccount(PaymentProvider $provider): PaymentProviderAccount
    {
        $credentials = [];

        foreach ($provider->requiredCredentialKeys() as $key) {
            $credentials[$key] = 'test-'.$key;
        }

        return $this->account($provider, active: true, credentials: $credentials);
    }

    protected function account(PaymentProvider $provider, bool $active, ?array $credentials): PaymentProviderAccount
    {
        return PaymentProviderAccount::create([
            'branch_id' => $this->branch->id,
            'provider' => $provider,
            'mode' => 'test',
            'is_active' => $active,
            'credentials' => $credentials,
            'activated_at' => now(),
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
            'promptpay_id' => '0812345678',
            'promptpay_name' => 'TEST SHOP',
        ]);
    }
}

/**
 * เกตเวย์ปลอมสำหรับเทสต์
 *
 * อยู่ไฟล์เดียวกับเทสต์โดยตั้งใจ — มันไม่ใช่ของที่โค้ดจริงต้องรู้จัก
 * และการวางไว้ที่นี่ทำให้อ่านเทสต์แล้วเห็นทันทีว่าเกตเวย์ถูกสั่งให้ตอบอะไร
 */
class FakeGateway implements PaymentGateway
{
    protected ?ChargeStatusResult $answer = null;

    protected ?string $error = null;

    public function willPay(float $amount): self
    {
        $this->error = null;
        $this->answer = new ChargeStatusResult(ChargeStatus::Paid, paidAmount: $amount, paidAt: now());

        return $this;
    }

    public function willReturn(ChargeStatusResult $result): self
    {
        $this->error = null;
        $this->answer = $result;

        return $this;
    }

    public function willThrow(string $message): self
    {
        $this->answer = null;
        $this->error = $message;

        return $this;
    }

    public function name(): string
    {
        return 'beam';
    }

    /** เลียนแบบ Beam Bolt ที่บังคับ 90–600 วินาที */
    public function expiryWindow(): ExpiryWindow
    {
        return new ExpiryWindow(90, 600);
    }

    public function createCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): ChargeResult
    {
        return new ChargeResult(
            providerChargeId: 'fake_'.$charge->uuid,
            qrPayload: '00020101021229370016A000000677010111',
            // เจ้าจริงคืนวันหมดอายุของตัวเองมา — ตัวปลอมคืนตามที่ถูกขอ
            expiresAt: $charge->expires_at,
            raw: ['fake' => true, 'amount_satang' => \App\Support\Money::toSatang((float) $charge->amount)],
        );
    }

    public function pollCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): ChargeStatusResult
    {
        if ($this->error !== null) {
            throw new \RuntimeException($this->error);
        }

        return $this->answer ?? ChargeStatusResult::pending('ยังไม่จ่าย');
    }

    public function cancelCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): void
    {
        // ไม่ต้องทำอะไร
    }
}
