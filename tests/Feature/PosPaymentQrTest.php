<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\PaymentProvider;
use App\Enums\UserRole;
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
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ปุ่มโชว์ QR บนหน้า POS
 *
 * ── สิ่งที่ชุดนี้คุมเป็นหลัก ─────────────────────────────────────────────
 * 1. กดปุ่มกี่ครั้งก็ได้ QR ใบเดียว — ไม่ทิ้งรายการค้างที่เกตเวย์เป็นสิบใบต่อบิล
 * 2. หน้าจอถามถี่แค่ไหน เราก็ถามเกตเวย์ไม่เกินเพดาน — ไม่งั้นโดน 429 ทั้งร้าน
 * 3. ปิดบิลแล้ว QR ที่ค้างต้องตาย — ไม่งั้นลูกค้าสแกนใบเดิมได้อีกแล้วเงินลอย
 * 4. เงินที่เข้ามาแล้วต้องผูกกับแถว payments ที่ถูกใบ — ไม่ใช่แค่ยอดรวมตรง
 *
 * ── สิ่งที่ตั้งใจไม่ทำ ──────────────────────────────────────────────────
 * ไม่ปิดบิลให้อัตโนมัติเมื่อเงินเข้า บิลหน้าเคาน์เตอร์รอพนักงานกดเสมอ
 * เพราะโต๊ะข้างกันอาจถูกปิดผิดใบ และแก้บิลที่ปิดไปแล้วยากกว่ากดปุ่มเพิ่มหนึ่งครั้ง
 */
class PosPaymentQrTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Product $noodle;

    protected User $cashier;

    protected PosQrFakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = $this->makeBranch('QR1');

        $category = Category::create(['branch_id' => $this->branch->id, 'name' => 'ก๋วยเตี๋ยว']);

        $this->noodle = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'หมี่ขาว',
            'price' => 100,
            'cost' => 30,
        ]);

        $this->cashier = $this->makeUser(UserRole::Cashier, 'qr-cashier@test.local');

        $this->actingAs($this->cashier);
    }

    /* ---------- ออก QR ---------- */

    public function test_the_button_opens_a_qr_the_customer_can_scan(): void
    {
        $this->useGateway();
        $order = $this->bill(2);

        $response = $this->postJson("/pos/orders/{$order->id}/charge")->assertOk();

        $charge = $response->json('charge');

        $this->assertSame('pending', $charge['status']);
        $this->assertMoney(200, $charge['amount'], 'ยอดที่ขอ');
        $this->assertNotEmpty($charge['qr_payload'], 'ต้องมีอะไรให้เอาไปวาด QR');
        $this->assertTrue($charge['verifies_automatically'], 'เจ้านี้ระบบตรวจยอดให้ได้');
    }

    public function test_pressing_the_button_twice_gives_back_the_same_qr(): void
    {
        /*
        | พนักงานกดโชว์ QR สองครั้งเป็นเรื่องปกติ — ลูกค้าขอดูใหม่ จอดับ กดพลาด
        | ถ้าออกใบใหม่ทุกครั้ง เกตเวย์จะมีรายการค้างเป็นสิบใบต่อบิล
        | แล้วตอนกระทบยอดปลายเดือนไม่มีใครรู้ว่าใบไหนคือใบจริง
        */
        $this->useGateway();
        $order = $this->bill(2);

        $first = $this->postJson("/pos/orders/{$order->id}/charge")->json('charge.id');
        $second = $this->postJson("/pos/orders/{$order->id}/charge")->json('charge.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, PaymentCharge::where('order_id', $order->id)->count());
    }

    public function test_adding_a_dish_after_the_qr_was_shown_retires_the_old_one(): void
    {
        // ปล่อยให้สแกนใบเดิมได้ = เก็บเงินขาด และเกตเวย์ส่วนใหญ่แก้ยอดใบเดิมไม่ได้
        $this->useGateway();
        $order = $this->bill(2);

        $old = $this->postJson("/pos/orders/{$order->id}/charge")->json('charge.id');

        app(OrderService::class)->addItem($order->fresh(), $this->noodle, 1);

        $new = $this->postJson("/pos/orders/{$order->id}/charge")->json('charge');

        $this->assertNotSame($old, $new['id']);
        $this->assertMoney(300, $new['amount'], 'ยอดใหม่หลังลูกค้าสั่งเพิ่ม');
        $this->assertSame(ChargeStatus::Cancelled, PaymentCharge::find($old)->status, 'ใบเก่าต้องถูกปิด');
    }

    public function test_the_lock_is_released_so_the_next_press_still_works(): void
    {
        /*
        | ล็อกที่ไม่ถูกคืนจะทำให้บิลนั้นออก QR ไม่ได้อีกเลยจนกว่าจะมีคนล้าง cache
        | อาการจะเป็น "กดปุ่มแล้วค้างสิบวินาทีแล้วขึ้น error" ซึ่งหาสาเหตุยากมาก
        */
        $this->useGateway();
        $order = $this->bill(2);

        $this->postJson("/pos/orders/{$order->id}/charge")->assertOk();

        // ใบที่สองของอีกบิลหนึ่ง — ถ้าล็อกรั่ว อันนี้ยังผ่าน แต่ของบิลเดิมจะค้าง
        $this->postJson("/pos/orders/{$order->id}/charge")->assertOk();

        $other = $this->bill(1);
        $this->postJson("/pos/orders/{$other->id}/charge")->assertOk();
    }

    public function test_a_closed_bill_cannot_be_given_a_qr(): void
    {
        $this->useGateway();
        $order = $this->bill(1);

        app(PaymentService::class)->pay($order->refresh(), [
            ['method' => 'cash', 'amount' => 100, 'received' => 100],
        ]);

        $this->postJson("/pos/orders/{$order->id}/charge")
            ->assertStatus(422)
            ->assertJsonPath('message', 'บิลนี้ปิดไปแล้ว ออก QR ใหม่ไม่ได้');
    }

    public function test_another_branchs_bill_is_never_reachable(): void
    {
        $other = $this->makeBranch('QR2');
        $order = Order::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'branch_id' => $other->id,
            'order_no' => 'X1',
            'business_date' => $other->businessDateFor()->toDateString(),
            'type' => \App\Enums\OrderType::Takeaway,
            'status' => 'open',
        ]);

        $this->postJson("/pos/orders/{$order->id}/charge")->assertForbidden();
    }

    public function test_a_gateway_that_refuses_tells_the_staff_what_to_do_instead(): void
    {
        /*
        | ข้อความของเกตเวย์คนอ่านไม่รู้เรื่องและอาจมีรายละเอียดภายในติดมา
        | หน้าจอจึงต้องบอกสิ่งที่ "ทำต่อได้" ไม่ใช่พ่นข้อความดิบออกมา
        */
        $this->useGateway();
        $this->gateway->failOpenWith('UPSTREAM_ERR_5512 merchant_id=beam_live_abc123');

        $order = $this->bill(2);

        $response = $this->postJson("/pos/orders/{$order->id}/charge")->assertStatus(502);

        $this->assertSame('ออก QR ไม่สำเร็จ กรุณาเก็บเงินด้วยวิธีอื่นไปก่อน', $response->json('message'));
        $this->assertStringNotContainsString('beam_live_abc123', $response->getContent(), 'รายละเอียดภายในต้องไม่หลุดออกไป');

        // แต่ต้องเก็บไว้ให้ตามเรื่องได้ ไม่ใช่กลืนหายไปเฉย ๆ
        $this->assertStringContainsString(
            'UPSTREAM_ERR_5512',
            (string) PaymentCharge::firstOrFail()->failure_message,
        );
    }

    /* ---------- ถามสถานะจากหน้าจอ ---------- */

    public function test_the_screen_never_asks_the_gateway_faster_than_the_floor(): void
    {
        /*
        | **ด่านสำคัญที่สุดของชุดนี้**
        |
        | จังหวะถามถูกกำหนดโดยเบราว์เซอร์ ไม่ใช่โดยเรา หน้าจอที่ถามทุกสองวินาที
        | (หรือเปิดค้างไว้หลายจอ) จะยิงเกตเวย์จน Beam คืน HTTP 429
        | แล้ว **ทุกบิลในร้านจะตรวจเงินไม่ได้พร้อมกัน** ไม่ใช่แค่จอที่ถามถี่
        */
        $this->useGateway();
        $charge = $this->openCharge();

        $this->getJson("/pos/charges/{$charge->id}")->assertOk();
        $this->assertSame(1, $this->gateway->polls, 'ครั้งแรกต้องถามจริง');

        // ยิงรัวสิบครั้งทันที — เกตเวย์ต้องไม่ถูกถามเพิ่มเลยสักครั้ง
        foreach (range(1, 10) as $_) {
            $this->getJson("/pos/charges/{$charge->id}")->assertOk();
        }

        $this->assertSame(1, $this->gateway->polls, 'คำขอที่มาเร็วกว่าเพดานต้องได้สถานะจากฐานข้อมูล');

        // พ้นเพดานแล้วจึงถามใหม่
        $this->travel(PollSchedule::SCREEN_FLOOR_SECONDS + 1)->seconds();
        $this->getJson("/pos/charges/{$charge->id}")->assertOk();

        $this->assertSame(2, $this->gateway->polls);
    }

    public function test_the_screen_sees_the_money_land(): void
    {
        $this->useGateway();
        $charge = $this->openCharge();

        $this->gateway->willPay(200.0);
        $this->travel(PollSchedule::SCREEN_FLOOR_SECONDS + 1)->seconds();

        $response = $this->getJson("/pos/charges/{$charge->id}")->assertOk();

        $this->assertSame('paid', $response->json('charge.status'));
        $this->assertMoney(200, $response->json('charge.paid_amount'), 'ยอดที่เข้ามาจริง');
    }

    public function test_a_gateway_we_cannot_reach_does_not_break_the_screen(): void
    {
        /*
        | ถามไม่ได้ ≠ เงินไม่เข้า — สองอย่างนี้ต่างกันสิ้นเชิง
        | ถ้าหน้าจอขึ้น error แดง พนักงานจะเข้าใจว่าลูกค้ายังไม่จ่าย
        | ทั้งที่เราแค่ยังไม่รู้ และตัวตั้งเวลาจะมาถามต่อให้เองอยู่แล้ว
        */
        $this->useGateway();
        $charge = $this->openCharge();

        $this->gateway->failPollWith('connection refused');
        $this->travel(PollSchedule::SCREEN_FLOOR_SECONDS + 1)->seconds();

        $response = $this->getJson("/pos/charges/{$charge->id}")->assertOk();

        $this->assertTrue($response->json('stale'), 'ต้องบอกว่าถามไม่ได้ชั่วคราว');
        $this->assertSame('pending', $response->json('charge.status'), 'และต้องไม่เดาว่าจ่ายหรือไม่จ่าย');
    }

    public function test_the_gateways_own_words_never_reach_the_browser(): void
    {
        /*
        | `store()` ระวังข้อนี้อยู่แล้ว แต่ `show()` เคยส่ง failure_message ดิบออกไป
        | ซึ่งเป็นช่องที่เปิดไว้ข้างหลังโดยไม่มีใครเห็น — ข้อความของเกตเวย์
        | อาจมีรหัสภายในหรือ merchant id ติดมา และคนอ่านไม่รู้เรื่องอยู่ดี
        */
        $this->useGateway();
        $charge = $this->openCharge();

        $charge->update([
            'status' => ChargeStatus::Failed,
            'failure_message' => 'UPSTREAM_ERR_9901 merchant_id=beam_live_zzz999',
        ]);

        $response = $this->getJson("/pos/charges/{$charge->id}")->assertOk();

        $this->assertStringNotContainsString('beam_live_zzz999', $response->getContent());
        $this->assertStringNotContainsString('UPSTREAM_ERR_9901', $response->getContent());
        $this->assertSame(
            'เกตเวย์ปฏิเสธคำขอ — ออก QR ใบใหม่ หรือเก็บเงินด้วยวิธีอื่น',
            $response->json('charge.note'),
            'ต้องบอกสิ่งที่พนักงานทำต่อได้ ไม่ใช่พ่นข้อความดิบ',
        );

        // แต่ต้องยังตามเรื่องได้จากฐานข้อมูล
        $this->assertStringContainsString('UPSTREAM_ERR_9901', (string) $charge->fresh()->failure_message);
    }

    public function test_the_stale_badge_goes_away_once_the_gateway_answers_again(): void
    {
        /*
        | ป้าย "ตรวจไม่ได้ชั่วคราว" ต้องหายไปเองเมื่อติดต่อได้อีกครั้ง
        | ถ้าค้างอยู่ พนักงานจะเลิกเชื่อป้ายนั้นไปเลย แล้ววันที่มันสำคัญจริงก็ไม่มีใครอ่าน
        */
        $this->useGateway();
        $charge = $this->openCharge();

        $this->gateway->failPollWith('connection refused');
        $this->travel(PollSchedule::SCREEN_FLOOR_SECONDS + 1)->seconds();

        $this->assertTrue($this->getJson("/pos/charges/{$charge->id}")->json('stale'));

        // เกตเวย์กลับมาตอบได้ตามปกติ
        $this->gateway->recover();
        $this->travel(PollSchedule::SCREEN_FLOOR_SECONDS + 1)->seconds();

        $response = $this->getJson("/pos/charges/{$charge->id}")->assertOk();

        $this->assertFalse($response->json('stale'), 'ติดต่อได้แล้วป้ายต้องหาย');
        $this->assertSame('pending', $response->json('charge.status'));
    }

    public function test_an_expired_qr_closes_itself_without_asking_the_gateway(): void
    {
        $this->useGateway();
        $charge = $this->openCharge();

        $charge->update(['expires_at' => now()->subMinute()]);

        $response = $this->getJson("/pos/charges/{$charge->id}")->assertOk();

        $this->assertSame('expired', $response->json('charge.status'));
        $this->assertSame(0, $this->gateway->polls, 'หมดอายุแล้วไม่ต้องเสียการเรียก API');
    }

    public function test_the_shops_own_qr_is_never_asked_and_never_pretends(): void
    {
        /*
        | สาขาที่ยังไม่ได้ตั้งเกตเวย์ยังโชว์ QR ได้ แต่ระบบตรวจยอดให้ไม่ได้
        | ต้องบอกหน้าจอตรง ๆ ไม่ใช่ปล่อยให้ขึ้นวงหมุนค้างไว้ให้เข้าใจว่ากำลังตรวจอยู่
        */
        $order = $this->bill(2);

        $charge = $this->postJson("/pos/orders/{$order->id}/charge")->assertOk()->json('charge');

        $this->assertSame('static', $charge['provider']);
        $this->assertFalse($charge['verifies_automatically']);
        $this->assertNotEmpty($charge['qr_payload']);

        $again = $this->getJson("/pos/charges/{$charge['id']}")->assertOk();

        $this->assertSame('pending', $again->json('charge.status'), 'ไม่แกล้งตอบว่าจ่ายแล้ว');
    }

    /* ---------- ยกเลิก ---------- */

    public function test_the_staff_can_retire_a_qr_when_the_customer_pays_cash_instead(): void
    {
        $this->useGateway();
        $charge = $this->openCharge();

        $this->deleteJson("/pos/charges/{$charge->id}")->assertOk();

        $this->assertSame(ChargeStatus::Cancelled, $charge->fresh()->status);
    }

    public function test_money_that_already_arrived_cannot_be_cancelled_away(): void
    {
        // ยกเลิกไม่ใช่การคืนเงิน — ถ้าปล่อยให้ทำ เงินก้อนนั้นจะหายไปจากสายตาทุกคน
        $this->useGateway();
        $charge = $this->openCharge();

        $charge->update(['status' => ChargeStatus::Paid, 'paid_amount' => 200]);

        $this->deleteJson("/pos/charges/{$charge->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'รายการนี้มีเงินเข้ามาแล้ว ยกเลิกไม่ได้');

        $this->assertSame(ChargeStatus::Paid, $charge->fresh()->status);
    }

    /* ---------- ตอนพนักงานกดปิดบิล ---------- */

    public function test_closing_the_bill_ties_the_money_to_the_payment_row(): void
    {
        $this->useGateway();
        $charge = $this->openCharge();

        $this->gateway->willPay(200.0);
        $this->travel(PollSchedule::SCREEN_FLOOR_SECONDS + 1)->seconds();
        $this->getJson("/pos/charges/{$charge->id}");

        $order = $charge->order;

        $this->assertNotNull(app(PaymentChargeService::class)->awaitingCashier($order->fresh()));

        $this->post("/pos/orders/{$order->id}/pay", [
            'lines' => [['method' => 'promptpay', 'amount' => 200, 'reference' => $charge->uuid]],
        ])->assertRedirect();

        $settled = $charge->fresh();
        $payment = $order->fresh()->payments()->firstOrFail();

        $this->assertSame($payment->id, $settled->settled_payment_id, 'เงินก้อนนี้ต้องตอบได้ว่าไปเข้าบิลแถวไหน');
    }

    public function test_closing_the_bill_with_cash_retires_the_qr_that_is_still_out_there(): void
    {
        /*
        | **ด่านที่กันเงินลอย**
        |
        | ลูกค้าเปลี่ยนใจจ่ายเงินสด แต่ QR ที่ออกไปแล้วยังใช้ได้อยู่
        | ลูกค้า (หรือคนที่ถ่ายรูป QR ไว้) สแกนทีหลังได้ เงินจะเข้ามาโดยไม่มีบิลรองรับ
        | กลายเป็น unmatched ที่ต้องมีคนตามคืนเงิน ทั้งที่กันได้ตั้งแต่ตอนปิดบิล
        */
        $this->useGateway();
        $charge = $this->openCharge();
        $order = $charge->order;

        $this->post("/pos/orders/{$order->id}/pay", [
            'lines' => [['method' => 'cash', 'amount' => 200, 'received' => 200]],
        ])->assertRedirect();

        $this->assertSame(ChargeStatus::Cancelled, $charge->fresh()->status);
        $this->assertSame('บิลถูกปิดด้วยวิธีอื่นแล้ว', $charge->fresh()->failure_message);
    }

    public function test_the_money_is_tied_to_the_line_with_the_matching_amount(): void
    {
        /*
        | บิลที่แบ่งจ่ายมีพร้อมเพย์ได้หลายบรรทัด (โอนสองรอบ)
        | ผูกผิดบรรทัดทำให้คำตอบว่า "เงินก้อนนี้อยู่ที่ไหน" ผิด
        | โดยที่ยอดรวมยังถูก — จึงไม่มีใครเห็นจนกว่าจะไล่ทีละรายการ
        */
        $this->useGateway();
        $order = $this->bill(3);

        // QR 300 บาท แต่ลูกค้าโอนมาสองรอบ รอบแรก 100 นอกระบบ
        $charge = app(PaymentChargeService::class)->open($order->fresh(), $this->cashier);
        $charge->update(['status' => ChargeStatus::Paid, 'paid_amount' => 300]);

        $this->post("/pos/orders/{$order->id}/pay", [
            'lines' => [
                ['method' => 'promptpay', 'amount' => 100, 'reference' => 'โอนรอบแรก'],
                ['method' => 'promptpay', 'amount' => 200, 'reference' => 'โอนรอบสอง'],
            ],
        ])->assertRedirect();

        // ไม่มีบรรทัดไหนยอด 300 — ผูกไม่ได้ ดีกว่าผูกมั่ว
        $this->assertNull($charge->fresh()->settled_payment_id, 'ยอดไม่ตรงบรรทัดไหนเลย ต้องไม่เดา');
    }

    public function test_a_closed_bill_never_keeps_saying_it_is_waiting_to_be_closed(): void
    {
        /*
        | ต่อจากด่านบน: ผูกแถว payments ไม่ได้ แต่บิลปิดไปแล้ว
        | ถ้ายังตอบว่า "รอพนักงานกดปิดบิล" หน้า POS จะขึ้นปุ่มปิดบิลค้างอยู่
        | บนบิลที่ปิดไปแล้ว แล้วพนักงานจะกดซ้ำหรือคิดว่าเงินหาย
        */
        $this->useGateway();
        $order = $this->bill(3);

        $charge = app(PaymentChargeService::class)->open($order->fresh(), $this->cashier);
        $charge->update(['status' => ChargeStatus::Paid, 'paid_amount' => 300]);

        $charges = app(PaymentChargeService::class);

        $this->assertNotNull($charges->awaitingCashier($order->fresh()), 'บิลยังเปิด = รอปิดบิลจริง');

        $this->post("/pos/orders/{$order->id}/pay", [
            'lines' => [
                ['method' => 'promptpay', 'amount' => 100, 'reference' => 'โอนรอบแรก'],
                ['method' => 'promptpay', 'amount' => 200, 'reference' => 'โอนรอบสอง'],
            ],
        ])->assertRedirect();

        $this->assertNull($charges->awaitingCashier($order->fresh()), 'บิลปิดแล้ว = ไม่มีอะไรให้รอ');
    }

    /* ---------- หน้าจอตอนโหลดหน้า ---------- */

    public function test_the_terminal_remembers_the_qr_after_a_refresh(): void
    {
        // พนักงานรีเฟรชหน้าหรือเปลี่ยนเครื่อง — ถ้าลืม จะกดออก QR ใบใหม่ทั้งที่ใบเดิมยังใช้ได้
        $this->useGateway();
        $charge = $this->openCharge();

        $this->get("/pos/terminal/{$charge->order_id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('charge.id', $charge->id)
                ->where('charge.status', 'pending'));
    }

    public function test_a_paid_qr_wins_over_one_that_is_still_waiting(): void
    {
        // ถ้ามีทั้งคู่ สิ่งที่พนักงานต้องทำต่อคือปิดบิล ไม่ใช่รอเงินของใบที่ยังค้าง
        $this->useGateway();
        $charge = $this->openCharge();
        $order = $charge->order;

        $charge->update(['status' => ChargeStatus::Paid, 'paid_amount' => 200]);

        PaymentCharge::create([
            'branch_id' => $this->branch->id,
            'order_id' => $order->id,
            'provider' => PaymentProvider::Beam,
            'amount' => 200,
            'status' => ChargeStatus::Pending,
            'provider_charge_id' => 'later',
        ]);

        $this->get("/pos/terminal/{$order->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('charge.status', 'paid'));
    }

    public function test_the_screen_never_receives_anything_about_the_gateway_account(): void
    {
        $this->useGateway();
        $charge = $this->openCharge();

        $response = $this->getJson("/pos/charges/{$charge->id}")->assertOk();
        $body = $response->getContent();

        $this->assertStringNotContainsString('MERCHANT-SECRET-0001', $body);
        $this->assertStringNotContainsString('API-KEY-SECRET-0002', $body);
        // raw คือคำตอบดิบของเกตเวย์ — ไม่รู้ว่าข้างในมีอะไร จึงไม่ส่งออกไปเลย
        $this->assertArrayNotHasKey('raw', (array) $response->json('charge'));
    }

    /* ---------- ตัวช่วย ---------- */

    /**
     * เทียบยอดเงินที่เดินทางผ่าน JSON มาแล้ว
     *
     * ── กับดักเดียวกับที่ CashSettlementTest เจอไปแล้ว ────────────────────
     * JSON มีชนิดตัวเลขชนิดเดียว `json_encode(200.0)` ได้ `200`
     * แล้ว `json_decode('200')` คืน **int** ไม่ใช่ float
     * `assertSame(200.0, ...)` จึงตกด้วย "200 is identical to 200.0" ตลอดไป
     *
     * และมันตกเฉพาะยอดที่เป็นจำนวนเต็ม — ยอดอย่าง 200.50 ยังเป็น float
     * หลัง decode จึงผ่าน กับดักที่โผล่ไม่สม่ำเสมอแบบนี้จึงกลับมาซ้ำได้ง่าย
     * ตัวช่วยนี้เทียบค่าของเงินจริง ๆ ไม่ใช่ชนิดที่ JSON เผอิญเลือกให้
     */
    protected function assertMoney(float $expected, mixed $actual, string $what): void
    {
        $this->assertIsNumeric($actual, "{$what} ต้องเป็นตัวเลข");
        // เผื่อครึ่งสตางค์ — เล็กกว่าหน่วยเงินที่เล็กสุด แต่กันความคลาดของ float
        $this->assertLessThan(0.005, abs((float) $actual - $expected), "{$what} ต้องเท่ากับ {$expected}");
    }

    /** ผูกเกตเวย์ปลอมและบัญชีที่กรอกกุญแจครบ เพื่อให้สาขานี้ใช้เจ้านั้นจริง */
    protected function useGateway(): void
    {
        $this->gateway = new PosQrFakeGateway;
        app()->instance('payment.gateway.'.PaymentProvider::Beam->value, $this->gateway);

        PaymentProviderAccount::create([
            'branch_id' => $this->branch->id,
            'provider' => PaymentProvider::Beam,
            'mode' => 'test',
            'is_active' => true,
            'activated_at' => now(),
            'credentials' => [
                'merchant_id' => 'MERCHANT-SECRET-0001',
                'api_key' => 'API-KEY-SECRET-0002',
            ],
        ]);
    }

    protected function bill(int $dishes): Order
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);
        $orders->addItem($order, $this->noodle, $dishes);

        return $order->refresh();
    }

    /** บิล 200 บาทที่ออก QR ไว้แล้ว */
    protected function openCharge(): PaymentCharge
    {
        $charge = app(PaymentChargeService::class)->open($this->bill(2), $this->cashier);

        // นับเฉพาะการถามหลังจากนี้ การออก QR ไม่ใช่การถาม
        $this->gateway->polls = 0;

        return $charge;
    }

    protected function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'branch_id' => $this->branch->id,
            'name' => 'ผู้ใช้ทดสอบ',
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
            'promptpay_id' => '0812345678',
            'promptpay_name' => 'TEST SHOP',
        ]);
    }
}

/**
 * เกตเวย์ปลอมที่ **นับจำนวนครั้งที่ถูกถาม**
 *
 * ตัวนับคือหัวใจของชุดนี้ — ด่านเรื่องเพดานการถามพิสูจน์ไม่ได้เลยถ้าไม่รู้ว่า
 * เกตเวย์ถูกเรียกไปกี่ครั้งจริง ๆ
 *
 * ชื่อต่างจาก FakeGateway (PaymentChargeTest) และ SettingsFakeGateway
 * โดยตั้งใจ — ทั้งสามอยู่ namespace เดียวกัน ชื่อซ้ำกันจะโหลดพร้อมกันไม่ได้
 */
class PosQrFakeGateway implements PaymentGateway
{
    public int $polls = 0;

    protected ?ChargeStatusResult $answer = null;

    protected ?string $pollError = null;

    protected ?string $openError = null;

    public function willPay(float $amount): void
    {
        $this->pollError = null;
        $this->answer = new ChargeStatusResult(ChargeStatus::Paid, paidAmount: $amount, paidAt: now());
    }

    public function failPollWith(string $message): void
    {
        $this->answer = null;
        $this->pollError = $message;
    }

    /** กลับมาตอบได้ตามปกติ — ใช้ทดสอบว่าป้าย "ตรวจไม่ได้" หายไปเอง */
    public function recover(): void
    {
        $this->pollError = null;
        $this->answer = null;
    }

    public function failOpenWith(string $message): void
    {
        $this->openError = $message;
    }

    public function name(): string
    {
        return 'beam';
    }

    public function expiryWindow(): ExpiryWindow
    {
        return new ExpiryWindow(90, 600);
    }

    public function createCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): ChargeResult
    {
        if ($this->openError !== null) {
            throw new \RuntimeException($this->openError);
        }

        return new ChargeResult(
            providerChargeId: 'fake_'.$charge->uuid,
            qrPayload: '00020101021229370016A000000677010111',
            expiresAt: $charge->expires_at,
        );
    }

    public function pollCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): ChargeStatusResult
    {
        $this->polls++;

        if ($this->pollError !== null) {
            throw new \RuntimeException($this->pollError);
        }

        return $this->answer ?? ChargeStatusResult::pending('ยังไม่จ่าย');
    }

    public function cancelCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): void
    {
        // ไม่ต้องทำอะไร
    }
}
