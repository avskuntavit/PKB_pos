<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * กันคำสั่งเดิมถูกบันทึกซ้ำ — middleware `idempotent`
 *
 * ── ทำไมต้องมีชุดนี้ ────────────────────────────────────────────────
 * นี่คือด่านสุดท้ายก่อนเงินจะถูกบันทึกสองรอบ พนักงานกดชำระเงิน เน็ตหลุด
 * หน้าจอขึ้นว่าพลาด พนักงานกดใหม่ — ถ้าด่านนี้ไม่ทำงาน บิลถูกจ่ายสองครั้ง
 * และไม่มีใครรู้จนกว่าจะนับเงินปลายวันแล้วไม่ตรง
 *
 * ── สิ่งที่ชุดนี้คุมเป็นหลัก ──────────────────────────────────────────
 * 1. คีย์เดิมยิงซ้ำ = **โค้ดข้างในต้องไม่ถูกเรียกรอบสอง** ไม่ใช่แค่ผลลัพธ์ดูเหมือนเดิม
 * 2. คีย์ของคนหนึ่งต้องไม่ไปบล็อกงานของอีกคน
 * 3. **งานที่ล้มเหลวต้องคืนคีย์** ไม่งั้นพนักงานแก้แล้วส่งใหม่ไม่ได้
 *    และที่แย่กว่าคือถูกตอบว่า "บันทึกไปแล้ว" ทั้งที่ยังไม่มีอะไรถูกบันทึก
 * 4. คีย์ที่รูปแบบไม่ถูกต้อง = เหมือนไม่ได้ส่งมา ต้องไม่ทำให้ระบบล่ม
 *
 * ── วิธีทดสอบ ───────────────────────────────────────────────────────
 * ครึ่งแรกใช้เส้นทางทดสอบที่นับจำนวนครั้งที่ closure ถูกเรียกจริง
 * เพราะถ้าเทสต์ผ่านเส้นทางจริงอย่างเดียว จะแยกไม่ออกว่า "ไม่ซ้ำ" เกิดจาก
 * middleware หรือเกิดจากด่านของ service ที่กันไว้อยู่แล้วอีกชั้น
 *
 * ครึ่งหลังยิงเส้นทางเงินจริงเพื่อพิสูจน์ว่าสายถูกต่อไว้จริงบน endpoint ที่สำคัญ
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /** จำนวนครั้งที่ closure ปลายทางถูกเรียกจริง */
    public static int $hits = 0;

    protected Branch $branch;

    protected Product $noodle;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        self::$hits = 0;

        $this->branch = Branch::create([
            'code' => 'ID1',
            'name' => 'สาขาทดสอบ',
            'vat_rate' => 7,
            'vat_included' => true,
            'service_charge_rate' => 0,
            'rounding_mode' => 0,
            'business_day_start' => '05:00:00',
        ]);

        $category = Category::create(['branch_id' => $this->branch->id, 'name' => 'ก๋วยเตี๋ยว']);

        $this->noodle = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'หมี่ขาว',
            'price' => 100,
            'cost' => 30,
        ]);

        $this->cashier = $this->makeUser('cashier@idem.test');

        $this->registerProbeRoutes();

        $this->actingAs($this->cashier);
    }

    /* ---------- สัญญาหลัก: ยิงซ้ำแล้วโค้ดข้างในต้องไม่ทำงานรอบสอง ---------- */

    public function test_the_same_key_twice_only_does_the_work_once(): void
    {
        $this->probe('ok', 'key-aaaaaaaa')->assertRedirect();
        $this->probe('ok', 'key-aaaaaaaa')->assertRedirect();

        $this->assertSame(1, self::$hits, 'คีย์เดิม = ความตั้งใจเดิม ต้องทำงานครั้งเดียว');
    }

    public function test_the_duplicate_is_told_it_was_already_saved(): void
    {
        $this->probe('ok', 'key-aaaaaaaa');

        $this->probe('ok', 'key-aaaaaaaa')
            ->assertSessionHas('success', 'รายการนี้บันทึกไปแล้ว ระบบไม่ได้ทำซ้ำให้');
    }

    public function test_a_new_intention_carries_a_new_key_and_runs_again(): void
    {
        $this->probe('ok', 'key-aaaaaaaa');
        $this->probe('ok', 'key-bbbbbbbb');

        $this->assertSame(2, self::$hits);
    }

    public function test_one_persons_key_does_not_block_another_persons_work(): void
    {
        $this->probe('ok', 'key-aaaaaaaa');

        // เครื่องอีกตัวในร้านสุ่มคีย์ชนกันพอดี — ต้องไม่กลายเป็นการบล็อกงานของเพื่อน
        $this->actingAs($this->makeUser('second@idem.test'));
        $this->probe('ok', 'key-aaaaaaaa');

        $this->assertSame(2, self::$hits);
    }

    public function test_the_key_is_released_once_it_expires(): void
    {
        $this->probe('ok', 'key-aaaaaaaa');

        // คีย์อยู่ได้ 15 นาที — เลยจากนั้นโต๊ะเดิมต้องเปิดบิลใหม่ได้
        $this->travel(16)->minutes();

        $this->probe('ok', 'key-aaaaaaaa');

        $this->assertSame(2, self::$hits);
    }

    /* ---------- คีย์ที่รูปแบบไม่ถูกต้อง = เหมือนไม่ได้ส่งมา ---------- */

    public function test_a_request_without_a_key_is_not_blocked(): void
    {
        // หน้าจอรุ่นเก่าที่ยังไม่ได้อัปเดตต้องใช้งานได้อยู่ ไม่ใช่ถูกปฏิเสธ
        $this->probe('ok');
        $this->probe('ok');

        $this->assertSame(2, self::$hits);
    }

    public function test_a_key_that_is_too_short_is_ignored(): void
    {
        $this->probe('ok', 'abcdefg');   // 7 ตัว ต่ำกว่าขั้นต่ำ 8
        $this->probe('ok', 'abcdefg');

        $this->assertSame(2, self::$hits);
    }

    public function test_a_key_that_is_too_long_is_ignored(): void
    {
        $long = str_repeat('a', 65);

        $this->probe('ok', $long);
        $this->probe('ok', $long);

        $this->assertSame(2, self::$hits);
    }

    public function test_a_key_with_characters_outside_the_allowed_set_is_ignored(): void
    {
        $this->probe('ok', 'key with spaces!');
        $this->probe('ok', 'key with spaces!');

        $this->assertSame(2, self::$hits);
    }

    public function test_surrounding_whitespace_does_not_make_a_new_key(): void
    {
        $this->probe('ok', 'key-aaaaaaaa');
        $this->probe('ok', '  key-aaaaaaaa  ');

        $this->assertSame(1, self::$hits, 'ช่องว่างหัวท้ายต้องถูกตัด ไม่ใช่กลายเป็นคีย์คนละตัว');
    }

    /* ---------- งานที่ล้มเหลวต้องคืนคีย์ ---------- */

    public function test_a_crash_releases_the_key_so_the_staff_can_try_again(): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->probe('boom', 'key-aaaaaaaa');
            $this->fail('ต้องโยน exception ออกมา');
        } catch (\RuntimeException) {
            // ตามคาด
        }

        // คีย์ต้องถูกคืน ไม่งั้นพนักงานกดใหม่แล้วถูกบอกว่า "บันทึกไปแล้ว" ทั้งที่ยังไม่ได้บันทึก
        $this->probe('ok', 'key-aaaaaaaa');

        $this->assertSame(2, self::$hits);
    }

    public function test_a_form_that_did_not_pass_validation_releases_the_key(): void
    {
        $this->probe('invalid', 'key-aaaaaaaa')->assertSessionHasErrors();

        $this->probe('ok', 'key-aaaaaaaa');

        $this->assertSame(2, self::$hits, 'กรอกผิดแล้วแก้ส่งใหม่ด้วยคีย์เดิม ต้องผ่าน');
    }

    public function test_a_request_blocked_by_permissions_releases_the_key(): void
    {
        $this->probe('forbidden', 'key-aaaaaaaa')->assertForbidden();

        $this->probe('ok', 'key-aaaaaaaa');

        $this->assertSame(2, self::$hits);
    }

    public function test_errors_sent_back_without_an_exception_release_the_key(): void
    {
        // เส้นทางที่ไม่ได้โยน exception ออกมา — ต้องอาศัยการอ่าน flash ไม่ใช่ตัวจับ exception
        $this->probe('rejected', 'key-aaaaaaaa')->assertSessionHasErrors('ช่อง');

        $this->probe('ok', 'key-aaaaaaaa');

        $this->assertSame(2, self::$hits);
    }

    public function test_a_failing_status_code_releases_the_key(): void
    {
        $this->probe('unprocessable', 'key-aaaaaaaa')->assertStatus(422);

        $this->probe('ok', 'key-aaaaaaaa');

        $this->assertSame(2, self::$hits);
    }

    public function test_a_refusal_shown_as_an_error_message_releases_the_key(): void
    {
        /*
        | ทุก endpoint ที่ใส่ด่านนี้ไว้ แจ้งความล้มเหลวด้วย back()->with('error', ...)
        | ไม่ใช่ withErrors() — เช่น "บิลนี้ถูกปิดไปแล้ว" ของหน้าชำระเงิน
        |
        | ถ้าไม่นับว่านี่คือความล้มเหลว คีย์จะถูกยึดไว้ทั้งที่ยังไม่มีอะไรถูกบันทึก
        | แล้วการกดใหม่ด้วยคีย์เดิมจะได้คำตอบว่า "บันทึกไปแล้ว" — โกหกพนักงานเรื่องเงิน
        */
        $this->probe('refused', 'key-aaaaaaaa')->assertSessionHas('error');

        $this->probe('ok', 'key-aaaaaaaa');

        $this->assertSame(2, self::$hits);
    }

    public function test_a_successful_request_keeps_its_key_claimed(): void
    {
        $this->probe('ok', 'key-aaaaaaaa')->assertSessionHas('success', 'เรียบร้อย');

        $this->probe('ok', 'key-aaaaaaaa');

        $this->assertSame(1, self::$hits);
    }

    /* ---------- เส้นทางเงินจริง ---------- */

    public function test_pressing_pay_twice_with_the_same_key_charges_the_bill_once(): void
    {
        $order = $this->openBill();

        $this->payRequest($order, 'pay-aaaaaaaa')->assertRedirect();
        $this->payRequest($order, 'pay-aaaaaaaa');

        $order->refresh();

        $this->assertSame(1, $order->payments()->count(), 'ต้องมีรายการรับเงินใบเดียว');
        $this->assertSame('100.00', $order->paid_amount);
    }

    public function test_without_a_key_the_second_press_reaches_the_service_and_is_refused_there(): void
    {
        /*
        | เทสต์คู่กับตัวบน — พิสูจน์ว่าตัวที่กันซ้ำในเคสข้างบนคือ middleware จริง ๆ
        | ไม่ใช่ด่านของ PaymentService ที่กันอยู่แล้วอีกชั้น
        |
        | ไม่มีคีย์ = คำขอที่สองวิ่งเข้า controller เต็ม ๆ แล้วถูกด่านชั้นในตีกลับ
        | ผลลัพธ์ปลอดภัยเหมือนกันแต่คนละที่ และคนละข้อความที่พนักงานเห็น
        */
        $order = $this->openBill();

        $this->payRequest($order)->assertRedirect();
        $this->payRequest($order)->assertSessionHas('error', 'บิลนี้ถูกปิดไปแล้ว');

        $this->assertSame(1, $order->fresh()->payments()->count());
    }

    public function test_a_key_already_used_blocks_a_different_bill_too(): void
    {
        /*
        | คีย์ผูกกับ "ความตั้งใจของผู้ใช้" ไม่ใช่กับบิล — หน้าจอจึงต้องหมุนคีย์ใหม่
        | ทุกครั้งที่ทำสำเร็จ (useIdempotencyKey().rotate ใน onSuccess)
        | เทสต์นี้ล็อกพฤติกรรมนั้นไว้ ถ้าวันหนึ่งมีคนถอด rotate ออก บิลถัดไปจะถูกปฏิเสธ
        */
        $first = $this->openBill();
        $second = $this->openBill();

        $this->payRequest($first, 'pay-aaaaaaaa');
        $this->payRequest($second, 'pay-aaaaaaaa')
            ->assertSessionHas('success', 'รายการนี้บันทึกไปแล้ว ระบบไม่ได้ทำซ้ำให้');

        $this->assertSame(0, $second->fresh()->payments()->count());
    }

    public function test_sending_the_same_order_to_the_kitchen_twice_prints_one_ticket(): void
    {
        $order = $this->openBill();

        $this->post("/pos/orders/{$order->id}/send", [], ['X-Idempotency-Key' => 'snd-aaaaaaaa']);
        $this->post("/pos/orders/{$order->id}/send", [], ['X-Idempotency-Key' => 'snd-aaaaaaaa'])
            ->assertSessionHas('success', 'รายการนี้บันทึกไปแล้ว ระบบไม่ได้ทำซ้ำให้');

        $this->assertSame(1, $order->kitchenTickets()->count(), 'ครัวต้องไม่ได้ใบสั่งสองใบ');
    }

    /* ---------- ตัวช่วย ---------- */

    /**
     * เส้นทางทดสอบที่นับจำนวนครั้งที่โค้ดข้างในถูกเรียกจริง
     *
     * ใช้ middleware ตัวจริงจาก alias เดียวกับที่ route จริงใช้ ไม่ได้สร้างตัวปลอมขึ้นมา
     */
    protected function registerProbeRoutes(): void
    {
        $outcomes = [
            // สำเร็จ
            'ok' => fn () => back()->with('success', 'เรียบร้อย'),
            // ล้มด้วย exception
            'boom' => fn () => throw new \RuntimeException('พัง'),
            // กรอกฟอร์มไม่ผ่าน
            'invalid' => fn (Request $request) => $request->validate(['ต้องมี' => ['required']]),
            // ไม่มีสิทธิ์ — โยนออกมา
            'forbidden' => fn () => abort(403),
            // ตีกลับด้วย errors โดยไม่โยน exception (แบบเดียวกับ CheckoutController)
            'rejected' => fn () => back()->withErrors(['ช่อง' => 'ไม่ผ่าน']),
            // ตอบสถานะ 4xx กลับไปตรง ๆ โดยไม่โยน exception
            'unprocessable' => fn () => response('ไม่ไหว', 422),
            // ปฏิเสธด้วยข้อความ error แบบเดียวกับ controller จริง
            'refused' => fn () => back()->with('error', 'ทำรายการไม่ได้'),
        ];

        foreach ($outcomes as $name => $handler) {
            Route::post("/__idem-probe/{$name}", function (Request $request) use ($handler) {
                self::$hits++;

                return $handler($request);
            })->middleware(['web', 'idempotent']);
        }
    }

    protected function probe(string $outcome, ?string $key = null)
    {
        return $this->post(
            "/__idem-probe/{$outcome}",
            [],
            $key === null ? [] : ['X-Idempotency-Key' => $key],
        );
    }

    protected function payRequest(Order $order, ?string $key = null)
    {
        return $this->post(
            "/pos/orders/{$order->id}/pay",
            ['lines' => [['method' => 'cash', 'amount' => 100, 'received' => 100]]],
            $key === null ? [] : ['X-Idempotency-Key' => $key],
        );
    }

    protected function openBill(): Order
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);
        $orders->addItem($order, $this->noodle, 1);

        return $order->refresh();
    }

    protected function makeUser(string $email): User
    {
        return User::create([
            'branch_id' => $this->branch->id,
            'name' => 'แคชเชียร์',
            'email' => $email,
            'password' => 'password',
            'role' => 'cashier',
        ]);
    }
}
