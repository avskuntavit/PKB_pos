<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VoucherBase;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * รหัสส่วนลด (voucher) — และฐานที่ร้านเลือกได้ว่าจะคิดจากอะไร
 *
 * ── สองแบบที่ขัดกันเองถ้าใช้กติกาเดียว ──────────────────────────────
 *   แบบการตลาด  "ซื้อครบ 400 ลด 10%" — ขั้นต่ำหมายถึงยอดค่าอาหารที่สั่ง
 *               ลูกค้าที่ได้โปรลดไปแล้วก็ยังสั่งครบ 400 อยู่ดี จึงควรใช้ได้
 *   แบบชดเชย    "ขอโทษที่ทำอาหารช้า ลด 100" — ต้องลดจากยอดที่ต้องจ่ายจริง
 *               ไม่งั้นคูปองไปซ้อนกับโปรจนบิลเหลือใกล้ศูนย์
 *
 * ร้านเลือกได้ต่อคูปอง (`base_mode`) เพราะกติกานี้เป็นของแคมเปญ ไม่ใช่ของร้าน
 *
 * ── ค่าเริ่มต้นต้องไม่เปลี่ยนความหมายของคูปองที่แจกไปแล้ว ──────────────
 * `menu_total` เป็นพฤติกรรมเดิมของระบบและเป็นค่าเริ่มต้นของคอลัมน์
 * คูปองที่อยู่ในมือลูกค้าแล้วต้องใช้ได้เหมือนเดิมทุกใบ
 */
class VoucherTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Product $noodle;      // 100 บาท

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // VAT 0 และไม่มีค่าบริการ เพื่อให้ตัวเลขในเทสต์อ่านออกว่ามาจากส่วนลดตัวไหน
        $this->branch = $this->makeBranch('VC1');

        $category = Category::create(['branch_id' => $this->branch->id, 'name' => 'ก๋วยเตี๋ยว']);

        $this->noodle = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'หมี่ขาว',
            'price' => 100,
            'cost' => 30,
        ]);

        $this->cashier = $this->makeUser(UserRole::Cashier, 'vc-cashier@test.local');

        $this->actingAs($this->cashier);
    }

    /* ---------- หัวใจของงานนี้: สองฐานให้คำตอบต่างกัน ---------- */

    public function test_a_marketing_voucher_still_works_on_a_bill_that_already_got_a_promotion(): void
    {
        /*
        | บิล 500 ได้โปรลด 200 ไปแล้ว ลูกค้ายื่นคูปอง "ซื้อครบ 400 ลด 10%"
        | ยอดค่าอาหารที่สั่งคือ 500 ซึ่งครบ 400 จริง — คูปองต้องใช้ได้
        */
        $this->promo(200);
        $voucher = $this->voucher('percent', 10, minSpend: 400, base: VoucherBase::MenuTotal);

        $order = $this->payWithVoucher($voucher, dishes: 5);

        $this->assertSame('50.00', $order->voucher_discount, '10% ของยอดค่าอาหาร 500');
        $this->assertSame('250.00', $order->grand_total, '500 - โปร 200 - คูปอง 50');
    }

    public function test_the_same_voucher_set_to_the_amount_due_is_refused_when_the_promotion_pulls_it_under_the_minimum(): void
    {
        /*
        | คูปองใบเดียวกัน แต่ตั้งฐานเป็น "ยอดที่ต้องจ่ายจริง"
        | หลังหักโปร 200 เหลือ 300 ซึ่งไม่ถึงขั้นต่ำ 400 — ต้องถูกปฏิเสธ
        | นี่คือความต่างทั้งหมดของสองโหมด แสดงด้วยฟิกซ์เจอร์ชุดเดียวกัน
        */
        $this->promo(200);
        $voucher = $this->voucher('percent', 10, minSpend: 400, base: VoucherBase::AmountDue);

        $this->expectException(\DomainException::class);

        $this->payWithVoucher($voucher, dishes: 5);
    }

    public function test_a_voucher_on_the_amount_due_discounts_only_what_is_left_to_pay(): void
    {
        $this->promo(200);
        $voucher = $this->voucher('percent', 10, minSpend: 200, base: VoucherBase::AmountDue);

        $order = $this->payWithVoucher($voucher, dishes: 5);

        $this->assertSame('30.00', $order->voucher_discount, '10% ของยอดที่เหลือ 300 ไม่ใช่ของ 500');
        $this->assertSame('270.00', $order->grand_total);
    }

    public function test_a_compensation_voucher_cannot_be_worth_more_than_what_is_left(): void
    {
        /*
        | โปรกินยอดไปเกือบหมดแล้ว (เหลือ 50) คูปองชดเชย 100
        | ฐาน amount_due ทำให้บันทึกว่าลูกค้าได้ 50 ซึ่งตรงกับความจริง
        | ถ้าคิดจากยอดค่าอาหาร จะบันทึกว่าลด 100 ทั้งที่ลูกค้าได้ประโยชน์แค่ 50
        | ตัวเลขที่บัญชีเอาไปตั้งเป็นค่าใช้จ่ายส่งเสริมการขายจะเกินจริง
        */
        $this->promo(450);
        $voucher = $this->voucher('amount', 100, minSpend: 0, base: VoucherBase::AmountDue);

        $order = $this->payWithVoucher($voucher, dishes: 5);

        $this->assertSame('50.00', $order->voucher_discount);
        $this->assertSame('0.00', $order->grand_total);
        $this->assertSame('50.00', VoucherRedemption::firstOrFail()->amount, 'ใบบันทึกต้องตรงกับที่ลูกค้าได้จริง');
    }

    public function test_the_same_compensation_voucher_on_the_menu_total_records_more_than_the_customer_actually_got(): void
    {
        // เทสต์คู่กับตัวบน — ล็อกพฤติกรรมของโหมดเดิมไว้ให้เห็นชัดว่าต่างกันตรงไหน
        $this->promo(450);
        $voucher = $this->voucher('amount', 100, minSpend: 0, base: VoucherBase::MenuTotal);

        $order = $this->payWithVoucher($voucher, dishes: 5);

        $this->assertSame('100.00', $order->voucher_discount);
        $this->assertSame('0.00', $order->grand_total, 'ยอดสุทธิติดลบไม่ได้ ถูกกันไว้ที่ 0');
    }

    public function test_a_staff_discount_lowers_the_base_only_on_the_amount_due(): void
    {
        $order = $this->openBill(5);
        $order->forceFill(['staff_discount' => 200])->save();
        app(OrderService::class)->recalculate($order);

        $due = $this->voucher('percent', 10, minSpend: 0, base: VoucherBase::AmountDue, code: 'DUE');
        $menu = $this->voucher('percent', 10, minSpend: 0, base: VoucherBase::MenuTotal, code: 'MENU');

        $this->assertSame(300.0, $due->base_mode->baseFor($order->fresh()), '500 - สิทธิ์พนักงาน 200');
        $this->assertSame(500.0, $menu->base_mode->baseFor($order->fresh()), 'ยอดค่าอาหารเต็ม ๆ');

        // และมูลค่าที่ลดได้ก็ต่างกันตามฐาน
        $this->assertSame(30.0, $due->discountFor($due->base_mode->baseFor($order->fresh())));
        $this->assertSame(50.0, $menu->discountFor($menu->base_mode->baseFor($order->fresh())));
    }

    public function test_an_existing_voucher_keeps_working_exactly_as_before(): void
    {
        /*
        | คอลัมน์ใหม่ต้องไม่เปลี่ยนความหมายของคูปองที่แจกออกไปแล้ว
        | สร้างโดยไม่ระบุ base_mode = ต้องได้ menu_total จากค่าเริ่มต้นของคอลัมน์
        */
        $voucher = Voucher::create([
            'branch_id' => $this->branch->id,
            'code' => 'OLD',
            'name' => 'คูปองเก่า',
            'type' => 'percent',
            'value' => 10,
            'usage_limit' => 1,
        ]);

        $this->assertSame(VoucherBase::MenuTotal, $voucher->fresh()->base_mode);

        $this->promo(200);
        $order = $this->payWithVoucher($voucher, dishes: 5);

        $this->assertSame('50.00', $order->voucher_discount, 'คิดจากยอดค่าอาหารเหมือนเดิม');
    }

    public function test_a_fresh_voucher_behaves_the_same_in_memory_and_after_reloading(): void
    {
        /*
        | ด่านที่กันบั๊กเงียบที่สุดของโมเดลนี้
        |
        | `Voucher::create()` คืน object ที่มีแค่คอลัมน์ที่เราส่งไป คอลัมน์ที่ปล่อยให้
        | ฐานข้อมูลเติมค่าตั้งต้นจะเป็น null ใน object นั้น และ Eloquent ไม่ฟ้องอะไรเลย
        |
        | ผลจริง: `is_active` เป็น null → `isRedeemable()` คืน false → `discountFor()`
        | คืน **0** คูปองที่ควรลด 50 บาทกลายเป็นลด 0 บาทโดยไม่มีข้อผิดพลาดใด ๆ
        |
        | ด่านนี้เทียบสองฝั่ง ถ้าวันหน้ามีคนเปลี่ยนค่าตั้งต้นที่ migration
        | แต่ลืมเปลี่ยนที่ $attributes (หรือกลับกัน) ด่านนี้จะฟ้องทันที
        */
        $voucher = $this->voucher('amount', 50, minSpend: 0, base: VoucherBase::MenuTotal);

        // ฝั่งหน่วยความจำ — ก่อนแตะฐานข้อมูลเลย
        $this->assertTrue($voucher->is_active, 'คูปองใหม่ต้องใช้งานได้ทันที ไม่ใช่ null');
        $this->assertSame(0, $voucher->used_count, 'จำนวนครั้งที่ใช้ต้องเป็น 0 ไม่ใช่ null');
        $this->assertSame(50.0, $voucher->discountFor(500), 'ลดได้จริงโดยไม่ต้อง reload');

        // ฝั่งฐานข้อมูล — และต้องตอบเหมือนกันทุกช่อง
        $reloaded = $voucher->fresh();

        foreach (['type', 'value', 'min_spend', 'base_mode', 'usage_limit', 'used_count', 'is_active'] as $field) {
            $this->assertEquals(
                $reloaded->{$field},
                $voucher->{$field},
                "ค่าตั้งต้นของ {$field} ในหน่วยความจำกับในฐานข้อมูลต้องตรงกัน",
            );
        }

        $this->assertSame(50.0, $reloaded->discountFor(500));
    }

    /* ---------- กติกาทั่วไปของคูปอง (ไม่เคยมีเทสต์เลย) ---------- */

    public function test_an_unknown_code_is_refused(): void
    {
        $this->expectExceptionMessage('ไม่พบรหัสส่วนลดนี้');

        app(PaymentService::class)->pay($this->openBill(1)->refresh(), $this->cash(1000), 'ไม่มีจริง');
    }

    public function test_a_code_from_another_branch_is_refused(): void
    {
        $other = $this->makeBranch('VC2');

        Voucher::create([
            'branch_id' => $other->id,
            'code' => 'OTHER',
            'name' => 'ของสาขาอื่น',
            'type' => 'amount',
            'value' => 50,
            'usage_limit' => 1,
        ]);

        $this->expectExceptionMessage('ไม่พบรหัสส่วนลดนี้');

        app(PaymentService::class)->pay($this->openBill(1)->refresh(), $this->cash(1000), 'OTHER');
    }

    public function test_a_switched_off_voucher_is_refused(): void
    {
        $this->assertVoucherRefused(fn (Voucher $v) => $v->update(['is_active' => false]));
    }

    public function test_a_voucher_used_up_to_its_limit_is_refused(): void
    {
        $this->assertVoucherRefused(fn (Voucher $v) => $v->update(['usage_limit' => 2, 'used_count' => 2]));
    }

    public function test_a_voucher_that_has_not_started_is_refused(): void
    {
        $this->assertVoucherRefused(fn (Voucher $v) => $v->update(['starts_at' => Carbon::now()->addDay()]));
    }

    public function test_an_expired_voucher_is_refused(): void
    {
        $this->assertVoucherRefused(fn (Voucher $v) => $v->update(['ends_at' => Carbon::now()->subDay()]));
    }

    public function test_a_bill_below_the_minimum_spend_is_refused(): void
    {
        $this->assertVoucherRefused(fn (Voucher $v) => $v->update(['min_spend' => 9999]));
    }

    public function test_the_ceiling_caps_a_percentage_voucher(): void
    {
        $voucher = $this->voucher('percent', 50, minSpend: 0, base: VoucherBase::MenuTotal);
        $voucher->update(['max_discount' => 60]);

        $order = $this->payWithVoucher($voucher->fresh(), dishes: 5);

        $this->assertSame('60.00', $order->voucher_discount, '50% ของ 500 = 250 แต่เพดาน 60');
    }

    public function test_using_a_voucher_records_it_and_counts_it(): void
    {
        $voucher = $this->voucher('amount', 80, minSpend: 0, base: VoucherBase::MenuTotal);

        $order = $this->payWithVoucher($voucher, dishes: 5);

        $this->assertSame(1, $voucher->fresh()->used_count);

        $redemption = VoucherRedemption::firstOrFail();

        $this->assertSame($voucher->id, (int) $redemption->voucher_id);
        $this->assertSame($order->id, (int) $redemption->order_id);
        $this->assertSame('80.00', $redemption->amount);
        $this->assertNotNull($redemption->redeemed_at);
    }

    /* ---------- หน้าจัดการคูปองในหลังบ้าน ---------- */

    public function test_the_manager_can_create_a_voucher_on_either_base(): void
    {
        $this->actingAs($this->makeUser(UserRole::Manager, 'vc-manager@test.local'));

        $this->post('/backoffice/vouchers', [
            'code' => 'SORRY100',
            'name' => 'ชดเชยลูกค้า',
            'type' => 'amount',
            'value' => 100,
            'min_spend' => 0,
            'usage_limit' => 5,
            'base_mode' => 'amount_due',
        ])->assertRedirect();

        $this->assertSame(VoucherBase::AmountDue, Voucher::where('code', 'SORRY100')->firstOrFail()->base_mode);
    }

    public function test_a_voucher_created_without_a_base_takes_the_branch_default(): void
    {
        $this->branch->update(['default_voucher_base' => VoucherBase::AmountDue]);

        $this->actingAs($this->makeUser(UserRole::Manager, 'vc-manager@test.local'));

        $this->post('/backoffice/vouchers', [
            'code' => 'NOBASE',
            'name' => 'ไม่ได้เลือกฐาน',
            'type' => 'amount',
            'value' => 50,
            'usage_limit' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            VoucherBase::AmountDue,
            Voucher::where('code', 'NOBASE')->firstOrFail()->base_mode,
            'ตกไปใช้ค่าเริ่มต้นของสาขา ไม่ใช่ค่าคงที่ในโค้ด',
        );
    }

    public function test_a_voucher_can_still_override_the_branch_default(): void
    {
        // ค่าเริ่มต้นเป็นแค่ค่าตั้งต้น ไม่ใช่กฎบังคับ — คูปองชดเชยหนึ่งใบต้องตั้งสวนได้
        $this->branch->update(['default_voucher_base' => VoucherBase::MenuTotal]);

        $this->actingAs($this->makeUser(UserRole::Manager, 'vc-manager@test.local'));

        $this->post('/backoffice/vouchers', [
            'code' => 'SORRY',
            'name' => 'ชดเชยลูกค้า',
            'type' => 'amount',
            'value' => 100,
            'usage_limit' => 1,
            'base_mode' => 'amount_due',
        ])->assertSessionHasNoErrors();

        $this->assertSame(VoucherBase::AmountDue, Voucher::where('code', 'SORRY')->firstOrFail()->base_mode);
    }

    public function test_changing_the_branch_default_never_touches_vouchers_already_issued(): void
    {
        /*
        | ด่านที่สำคัญที่สุดของค่าเริ่มต้นระดับสาขา
        |
        | ถ้าคูปองอ่านฐานจากสาขาสด ๆ ตอนคิดเงิน การกดเปลี่ยนค่านี้หนึ่งครั้ง
        | จะไปแก้เงื่อนไขของคูปองทุกใบที่พิมพ์แจกออกไปแล้ว — ผิดสัญญากับลูกค้า
        | ฐานจึงถูกคัดลอกลงแถวของคูปองตอนสร้าง แล้วไม่ขยับอีก
        */
        $this->branch->update(['default_voucher_base' => VoucherBase::MenuTotal]);

        $voucher = $this->voucher('percent', 10, minSpend: 400, base: VoucherBase::MenuTotal);

        $this->branch->update(['default_voucher_base' => VoucherBase::AmountDue]);

        $this->assertSame(VoucherBase::MenuTotal, $voucher->fresh()->base_mode);

        // และยังใช้ได้เหมือนเดิมบนบิลที่ได้โปรไปแล้ว
        $this->promo(200);
        $order = $this->payWithVoucher($voucher->fresh(), dishes: 5);

        $this->assertSame('50.00', $order->voucher_discount);
    }

    public function test_the_branch_starts_on_the_behaviour_the_system_always_had(): void
    {
        // สาขาที่สร้างใหม่โดยไม่ระบุอะไร ต้องได้ menu_total = พฤติกรรมเดิม
        $this->assertSame(VoucherBase::MenuTotal, $this->makeBranch('VC9')->default_voucher_base);
    }

    public function test_a_duplicate_code_gets_a_message_not_a_crash(): void
    {
        /*
        | ตารางมี unique(branch_id, code) อยู่แล้ว ถ้าไม่ตรวจที่ controller
        | ผู้ใช้จะเจอหน้า error 500 แทนข้อความว่ารหัสนี้มีคนใช้แล้ว
        */
        $this->voucher('amount', 50, minSpend: 0, base: VoucherBase::MenuTotal, code: 'DUP');

        $this->actingAs($this->makeUser(UserRole::Manager, 'vc-manager@test.local'));

        $this->post('/backoffice/vouchers', [
            'code' => 'DUP',
            'name' => 'รหัสซ้ำ',
            'type' => 'amount',
            'value' => 10,
            'usage_limit' => 1,
            'base_mode' => 'menu_total',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Voucher::count());
    }

    public function test_the_same_code_may_exist_in_a_different_branch(): void
    {
        // รหัสถูกพิมพ์บนใบคูปองของแต่ละสาขา ห้ามซ้ำกันข้ามสาขาคือข้อจำกัดที่ไม่มีเหตุผล
        $other = $this->makeBranch('VC3');

        Voucher::create([
            'branch_id' => $other->id,
            'code' => 'SHARED',
            'name' => 'ของสาขาอื่น',
            'type' => 'amount',
            'value' => 10,
            'usage_limit' => 1,
        ]);

        $this->actingAs($this->makeUser(UserRole::Manager, 'vc-manager@test.local'));

        $this->post('/backoffice/vouchers', [
            'code' => 'SHARED',
            'name' => 'ของสาขานี้',
            'type' => 'amount',
            'value' => 10,
            'usage_limit' => 1,
            'base_mode' => 'menu_total',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Voucher::count());
    }

    public function test_the_screen_offers_both_bases_with_an_explanation(): void
    {
        $this->actingAs($this->makeUser(UserRole::Manager, 'vc-manager@test.local'));

        $this->get('/backoffice/vouchers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('bases', 2)
                ->where('bases.0.value', 'menu_total')
                ->where('bases.1.value', 'amount_due')
                ->where('defaultBase', 'menu_total'));
    }

    public function test_the_voucher_screen_preselects_the_branch_default(): void
    {
        $this->branch->update(['default_voucher_base' => VoucherBase::AmountDue]);

        $this->actingAs($this->makeUser(UserRole::Manager, 'vc-manager@test.local'));

        $this->get('/backoffice/vouchers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('defaultBase', 'amount_due'));
    }

    public function test_the_branch_settings_screen_can_change_the_default(): void
    {
        // ผู้จัดการไม่มีสิทธิ์ branch.settings — เจ้าของร้านเท่านั้น (Permission::defaultsFor)
        $this->actingAs($this->makeUser(UserRole::Owner, 'vc-owner@test.local'));

        $this->get('/backoffice/settings/branch')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('voucherBases', 2)
                ->where('branch.default_voucher_base', 'menu_total'));
    }

    /* ---------- ตัวช่วย ---------- */

    /** คูปองที่ควรใช้ได้ พอถูกแก้ตามที่ส่งมาแล้วต้องถูกปฏิเสธ */
    protected function assertVoucherRefused(callable $break): void
    {
        $voucher = $this->voucher('amount', 50, minSpend: 0, base: VoucherBase::MenuTotal);

        // พิสูจน์ก่อนว่าคูปองนี้ใช้ได้จริงตอนยังไม่ถูกแก้ ไม่งั้นเทสต์ผ่านด้วยเหตุผลที่ผิด
        $this->assertSame(50.0, $voucher->discountFor(500), 'ฟิกซ์เจอร์ต้องใช้ได้ก่อน');

        $break($voucher);

        $this->expectException(\DomainException::class);

        app(PaymentService::class)->pay($this->openBill(5)->refresh(), $this->cash(1000), $voucher->code);
    }

    protected function payWithVoucher(Voucher $voucher, int $dishes): Order
    {
        $order = $this->openBill($dishes);

        app(PaymentService::class)->pay($order->refresh(), $this->cash(1000), $voucher->code);

        return $order->fresh();
    }

    protected function openBill(int $dishes): Order
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);
        $orders->addItem($order, $this->noodle, $dishes);

        return $order->refresh();
    }

    /** @return array<int, array<string, mixed>> */
    protected function cash(float $amount): array
    {
        return [['method' => 'cash', 'amount' => $amount, 'received' => $amount]];
    }

    protected function voucher(
        string $type,
        float $value,
        float $minSpend,
        VoucherBase $base,
        string $code = 'SAVE',
    ): Voucher {
        return Voucher::create([
            'branch_id' => $this->branch->id,
            'code' => $code,
            'name' => 'คูปองทดสอบ',
            'type' => $type,
            'value' => $value,
            'min_spend' => $minSpend,
            'usage_limit' => 10,
            'base_mode' => $base,
        ]);
    }

    protected function promo(float $amount): Promotion
    {
        return Promotion::create([
            'branch_id' => $this->branch->id,
            'name' => 'ลดท้ายบิล '.$amount,
            'trigger_type' => 'none',
            'reward_type' => 'bill_amount',
            'reward_value' => $amount,
        ]);
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
        ]);
    }
}
