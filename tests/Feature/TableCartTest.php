<?php

namespace Tests\Feature;

use App\Enums\OrderType;
use App\Enums\PaymentIntent;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TableCartItem;
use App\Models\TableSession;
use App\Services\OnlineOrderService;
use App\Services\TableCartService;
use App\Services\TableSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ตะกร้าร่วมของโต๊ะ
 *
 * ── สิ่งที่เทสต์ชุดนี้คุมเป็นหลัก ───────────────────────────────────────
 * 1. ยอดในตะกร้าต้องเท่ากับยอดที่ขึ้นบิลจริง — ลูกค้าเห็นราคาหนึ่งแล้วจ่ายอีกราคา
 *    คือเรื่องที่ร้านอาหารเถียงกับลูกค้าหน้าเคาน์เตอร์
 * 2. กดส่งซ้ำ หรือกดส่งพร้อมกันสองเครื่อง ต้องไม่ได้อาหารสองเท่า
 * 3. ยกเลิกแล้วต้องยกเลิกจริง ไม่ใช่แค่ปุ่มหาย
 * 4. แก้ตะกร้าโต๊ะอื่นไม่ได้
 */
class TableCartTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected DiningTable $table;

    protected Product $noodle;

    protected TableSession $session;

    /** กุญแจประจำเครื่องของ "สองมือถือ" ที่นั่งโต๊ะเดียวกัน */
    protected string $phoneA = 'aaaaaaaaaaaaaaaa';

    protected string $phoneB = 'bbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = Branch::create([
            'code' => 'T1',
            'name' => 'สาขาทดสอบ',
            'vat_rate' => 7,
            'vat_included' => true,
            'service_charge_rate' => 0,
            'rounding_mode' => 0,
            'business_day_start' => '05:00:00',
            // เทสต์ชุดนี้คุมเรื่องตะกร้า ไม่ใช่ด่านเปิดโต๊ะ
            'qr_requires_open_table' => false,
            /*
            | เปิด 24 ชั่วโมง — ไม่ใช่ความสวยงาม แต่เป็นความถูกต้องของเทสต์
            |
            | ค่าเริ่มต้นของ Branch คือ 09:00-21:00 และ OnlineOrderService::place()
            | เรียก isTakingOnlineOrders() ซึ่งอ่านนาฬิกาเครื่องจริง
            | ถ้าไม่ตั้งตรงนี้ เทสต์ที่ส่งตะกร้าเข้าบิลจะผ่านตอนกลางวัน
            | แล้วตกตอนกลางคืน — ตกโดยไม่มีอะไรในโค้ดเปลี่ยนเลย
            |
            | ที่อันตรายกว่าคือเทสต์ที่ผลลัพธ์ขึ้นกับเวลาที่รัน จะสอนให้คนเลิกเชื่อชุดเทสต์
            | (OnlineOrderFlowTest กับ MemberAndBenefitTest ตั้งไว้แบบนี้อยู่แล้ว)
            */
            'open_time' => '00:00:00',
            'close_time' => '23:59:59',
        ]);

        $this->table = DiningTable::create([
            'branch_id' => $this->branch->id,
            'name' => 'A1',
            'seats' => 4,
        ]);

        $category = Category::create(['branch_id' => $this->branch->id, 'name' => 'ก๋วยเตี๋ยว']);

        $this->noodle = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'หมี่ขาว',
            'price' => 50,
            'cost' => 18,
        ]);

        $this->session = app(TableSessionService::class)->resolve($this->table);
    }

    /* ---------- ตะกร้าที่ใช้ร่วมกันจริง ---------- */

    public function test_what_one_phone_adds_the_other_phone_sees(): void
    {
        $this->cart()->add($this->session, $this->noodle->id, 2, [], null, $this->phoneA, 'ก้อย');

        $seenByB = $this->cart()->summary($this->session, $this->phoneB);

        $this->assertCount(1, $seenByB['lines']);
        $this->assertSame('ก้อย', $seenByB['lines'][0]['guest_name']);
        $this->assertFalse($seenByB['lines'][0]['mine'], 'เครื่องอื่นต้องเห็นว่าไม่ใช่ของตัวเอง');
        $this->assertSame(100.0, $seenByB['total']);

        $this->assertTrue($this->cart()->summary($this->session, $this->phoneA)['lines'][0]['mine']);
    }

    public function test_adding_the_same_thing_twice_merges_into_one_line(): void
    {
        $cart = $this->cart();

        $cart->add($this->session, $this->noodle->id, 1, [], null, $this->phoneA, 'ก้อย');
        $cart->add($this->session, $this->noodle->id, 2, [], null, $this->phoneB, 'เจน');

        $summary = $cart->summary($this->session);

        $this->assertCount(1, $summary['lines'], 'ของเหมือนกันต้องยุบเป็นบรรทัดเดียว');
        $this->assertSame(3.0, $summary['lines'][0]['qty']);
    }

    public function test_a_different_note_makes_a_different_line(): void
    {
        $cart = $this->cart();

        $cart->add($this->session, $this->noodle->id, 1, [], 'ไม่ใส่ผักชี', $this->phoneA, 'ก้อย');
        $cart->add($this->session, $this->noodle->id, 1, [], null, $this->phoneA, 'ก้อย');

        $this->assertCount(2, $cart->summary($this->session)['lines']);
    }

    public function test_anyone_at_the_table_can_change_or_remove_anyone_elses_line(): void
    {
        $cart = $this->cart();
        $line = $cart->add($this->session, $this->noodle->id, 3, [], null, $this->phoneA, 'ก้อย');

        // เครื่อง B แก้ของเครื่อง A ได้ ตามที่ตกลงกันไว้
        $cart->setQty($this->session, $line->id, 1);
        $this->assertSame(1.0, (float) $line->fresh()->qty);

        $cart->remove($this->session, $line->id);
        $this->assertSame(0, TableCartItem::count());
    }

    public function test_setting_the_quantity_to_zero_removes_the_line(): void
    {
        $cart = $this->cart();
        $line = $cart->add($this->session, $this->noodle->id, 2, [], null, $this->phoneA, null);

        $this->assertNull($cart->setQty($this->session, $line->id, 0));
        $this->assertSame(0, TableCartItem::count());
    }

    public function test_a_line_from_another_table_cannot_be_touched(): void
    {
        $other = DiningTable::create(['branch_id' => $this->branch->id, 'name' => 'A2', 'seats' => 2]);
        $otherSession = app(TableSessionService::class)->resolve($other);

        $line = $this->cart()->add($otherSession, $this->noodle->id, 1, [], null, $this->phoneB, null);

        $this->expectException(\DomainException::class);

        $this->cart()->remove($this->session, $line->id);
    }

    /* ---------- ราคา ---------- */

    public function test_the_cart_uses_the_price_of_this_branch_not_the_central_price(): void
    {
        /*
        | เมนูกลางราคา 100 แต่สาขานี้ตั้งทับไว้ 80
        | หน้าเมนูของลูกค้าโชว์ 80 ตะกร้าจึงต้องเป็น 80 ด้วย
        | และตอนขึ้นบิลก็ต้อง 80 — เดิมตรงนั้นใช้ราคากลางอยู่ (บั๊กที่แก้ไปพร้อมกัน)
        */
        $central = Product::create(['branch_id' => null, 'name' => 'ข้าวผัดกลาง', 'price' => 100]);
        BranchProduct::create(['branch_id' => $this->branch->id, 'product_id' => $central->id, 'price' => 80]);

        $line = $this->cart()->add($this->session, $central->id, 1, [], null, $this->phoneA, null);

        $this->assertSame('80.00', $line->unit_price);
    }

    public function test_modifier_surcharges_are_included_in_the_cart_total(): void
    {
        [$group, $extra] = $this->makeModifier(15);

        $line = $this->cart()->add($this->session, $this->noodle->id, 2, [$extra->id], null, $this->phoneA, null);

        // (50 + 15) x 2
        $this->assertSame('65.00', $line->unit_price);
        $this->assertSame(130.0, $this->cart()->summary($this->session)['total']);
    }

    public function test_modifier_ids_that_do_not_belong_to_the_product_are_dropped(): void
    {
        $line = $this->cart()->add($this->session, $this->noodle->id, 1, [999999], null, $this->phoneA, null);

        $this->assertNull($line->modifier_ids);
        $this->assertSame('50.00', $line->unit_price, 'ตัวเลือกมั่วต้องไม่ทำให้ราคาขยับ');
    }

    public function test_the_cart_shows_modifier_names_not_id_numbers(): void
    {
        [$group, $extra] = $this->makeModifier(15, 'ไข่ดาว');
        $second = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'เผ็ดน้อย', 'price_delta' => 0]);

        $cart = $this->cart();
        $cart->add($this->session, $this->noodle->id, 1, [$second->id, $extra->id], null, $this->phoneA, null);

        $lines = $cart->summary($this->session)['lines'];

        // เรียงตามลำดับที่เก็บไว้ในบรรทัด (เรียง id แล้วตอน add) ไม่ใช่ตามลำดับที่กด
        $this->assertSame(
            ['ไข่ดาว', 'เผ็ดน้อย'],
            $lines[0]['modifier_names'],
            'ลูกค้าต้องเห็นชื่อตัวเลือก ไม่ใช่เลข id',
        );
    }

    public function test_a_line_without_modifiers_has_an_empty_name_list(): void
    {
        $this->cart()->add($this->session, $this->noodle->id, 1, [], null, $this->phoneA, null);

        $this->assertSame([], $this->cart()->summary($this->session)['lines'][0]['modifier_names']);
    }

    public function test_a_modifier_deleted_from_the_back_office_just_disappears_from_the_cart(): void
    {
        [, $extra] = $this->makeModifier(15, 'ไข่ดาว');

        $cart = $this->cart();
        $cart->add($this->session, $this->noodle->id, 1, [$extra->id], null, $this->phoneA, null);

        // เก็บไว้เป็น id ไม่ใช่ชื่อ ตัวเลือกจึงหายได้ระหว่างที่ของยังค้างอยู่ในตะกร้า
        $extra->delete();

        $lines = $cart->summary($this->session)['lines'];

        $this->assertSame([], $lines[0]['modifier_names'], 'ต้องหายเงียบ ไม่ใช่โผล่เป็นเลข id ให้ลูกค้างง');
        $this->assertSame(65.0, $lines[0]['unit_price'], 'ราคาที่ตกลงกันไว้ตอนหยิบใส่ตะกร้าต้องไม่ขยับตาม');
    }

    public function test_the_same_modifiers_in_a_different_order_are_one_line(): void
    {
        [$group, $a] = $this->makeModifier(10, 'พิเศษ');
        $b = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'ไข่ดาว', 'price_delta' => 5]);

        $cart = $this->cart();
        $cart->add($this->session, $this->noodle->id, 1, [$a->id, $b->id], null, $this->phoneA, null);
        $cart->add($this->session, $this->noodle->id, 1, [$b->id, $a->id], null, $this->phoneB, null);

        $this->assertCount(1, $cart->summary($this->session)['lines']);
    }

    /* ---------- เมนูที่สั่งไม่ได้ ---------- */

    public function test_a_product_from_another_branch_cannot_be_added(): void
    {
        $other = Branch::create([
            'code' => 'T2', 'name' => 'อีกสาขา', 'vat_rate' => 7,
            'vat_included' => true, 'business_day_start' => '05:00:00',
        ]);

        $foreign = Product::create(['branch_id' => $other->id, 'name' => 'เมนูสาขาอื่น', 'price' => 999]);

        $this->expectException(\DomainException::class);

        $this->cart()->add($this->session, $foreign->id, 1, [], null, $this->phoneA, null);
    }

    public function test_a_product_that_is_switched_off_cannot_be_added(): void
    {
        $this->noodle->update(['is_active' => false]);

        $this->expectException(\DomainException::class);

        $this->cart()->add($this->session, $this->noodle->id, 1, [], null, $this->phoneA, null);
    }

    public function test_nothing_can_be_added_after_the_session_closes(): void
    {
        app(TableSessionService::class)->close($this->session, 'paid');

        $this->expectException(\DomainException::class);

        $this->cart()->add($this->session->fresh(), $this->noodle->id, 1, [], null, $this->phoneA, null);
    }

    /* ---------- นับถอยหลังก่อนส่งครัว ---------- */

    public function test_pressing_send_starts_a_countdown_and_sends_nothing_yet(): void
    {
        $cart = $this->cart();
        $cart->add($this->session, $this->noodle->id, 1, [], null, $this->phoneA, 'ก้อย');

        $at = $cart->requestSubmit($this->session, $this->phoneA, 'ก้อย');

        $this->assertTrue($at->isFuture());
        $this->assertSame(0, Order::count(), 'ยังไม่ถึงเวลา ต้องยังไม่มีบิล');

        // ทุกเครื่องที่โต๊ะต้องเห็นนาฬิกาเดียวกัน ไม่ใช่เห็นเฉพาะคนที่กด
        $seenByB = $cart->summary($this->session->fresh(), $this->phoneB);
        $this->assertNotNull($seenByB['submit_at']);
        $this->assertSame('ก้อย', $seenByB['submit_by']);
        $this->assertFalse($seenByB['submit_is_mine']);
    }

    public function test_pressing_send_twice_does_not_push_the_countdown_further_out(): void
    {
        $cart = $this->cart();
        $cart->add($this->session, $this->noodle->id, 1, [], null, $this->phoneA, null);

        $first = $cart->requestSubmit($this->session, $this->phoneA, 'ก้อย');
        $second = $cart->requestSubmit($this->session->fresh(), $this->phoneB, 'เจน');

        $this->assertSame($first->toIso8601String(), $second->toIso8601String());
    }

    public function test_sending_before_the_countdown_finishes_is_refused(): void
    {
        $cart = $this->cart();
        $cart->add($this->session, $this->noodle->id, 1, [], null, $this->phoneA, null);
        $cart->requestSubmit($this->session, $this->phoneA, null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ยังนับถอยหลังไม่ครบ');

        $cart->submitNow($this->session->fresh(), $this->placeOrder());
    }

    public function test_anyone_can_cancel_and_the_cart_survives(): void
    {
        $cart = $this->cart();
        $cart->add($this->session, $this->noodle->id, 2, [], null, $this->phoneA, null);
        $cart->requestSubmit($this->session, $this->phoneA, 'ก้อย');

        // เครื่อง B (ไม่ใช่คนกด) ยกเลิกได้
        $this->assertTrue($cart->cancelSubmit($this->session->fresh()));

        $this->travel(10)->seconds();

        try {
            $cart->submitNow($this->session->fresh(), $this->placeOrder());
            $this->fail('ยกเลิกไปแล้วต้องส่งไม่ได้');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ยกเลิก', $e->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(1, TableCartItem::count(), 'ยกเลิกแล้วของในตะกร้าต้องยังอยู่ครบ');
    }

    public function test_a_countdown_left_hanging_expires_by_itself(): void
    {
        /*
        | คนกดส่งเป็นคนยิงคำสั่งส่งจริงเมื่อครบเวลา ถ้ามือถือเขาดับไปก่อน
        | จะไม่มีใครมาปิดงานนี้ ทั้งโต๊ะจะเห็นนาฬิกาค้างตลอดไปโดยไม่มีอะไรเกิดขึ้น
        */
        $cart = $this->cart();
        $cart->add($this->session, $this->noodle->id, 1, [], null, $this->phoneA, null);
        $cart->requestSubmit($this->session, $this->phoneA, null);

        $this->travel(TableCartService::SUBMIT_STALE_SECONDS + 10)->seconds();

        $this->assertNull($cart->pendingSubmit($this->session->fresh()));
        $this->assertNull($this->session->fresh()->cart_submit_at);
    }

    /* ---------- ส่งจริง ---------- */

    public function test_the_whole_cart_becomes_pending_items_and_the_cart_is_emptied(): void
    {
        $cart = $this->cart();
        $cart->add($this->session, $this->noodle->id, 2, [], 'ไม่ใส่ผักชี', $this->phoneA, 'ก้อย');
        $cart->add($this->session, $this->noodle->id, 1, [], null, $this->phoneB, 'เจน');
        $cart->requestSubmit($this->session, $this->phoneA, 'ก้อย');

        $this->travel(6)->seconds();

        $order = $cart->submitNow($this->session->fresh(), $this->placeOrder());

        $this->assertSame(2, $order->items()->count());
        $this->assertSame(0, TableCartItem::count(), 'ส่งแล้วตะกร้าต้องว่าง');
        $this->assertNull($this->session->fresh()->cart_submit_at);

        foreach ($order->items as $item) {
            $this->assertSame('pending', $item->approval_status, 'ยังต้องให้พนักงานกดยืนยันก่อนเข้าครัว');
        }

        // ชื่อคนสั่งต้องติดไปกับจาน ไม่งั้นบิลโต๊ะแยกรายคนไม่ได้
        $names = $order->items->pluck('guest_name')->sort()->values()->all();
        $this->assertSame(['ก้อย', 'เจน'], $names);
    }

    public function test_the_bill_charges_the_same_price_the_cart_showed(): void
    {
        $central = Product::create(['branch_id' => null, 'name' => 'ข้าวผัดกลาง', 'price' => 100]);
        BranchProduct::create(['branch_id' => $this->branch->id, 'product_id' => $central->id, 'price' => 80]);

        $cart = $this->cart();
        $cart->add($this->session, $central->id, 1, [], null, $this->phoneA, null);

        $shown = $cart->summary($this->session)['total'];

        $cart->requestSubmit($this->session, $this->phoneA, null);
        $this->travel(6)->seconds();
        $order = $cart->submitNow($this->session->fresh(), $this->placeOrder());

        $item = $order->items()->first();

        $this->assertNotNull($item, 'เมนูกลางต้องสั่งได้ ไม่ใช่หล่นหายเงียบ ๆ');
        $this->assertSame(80.0, $shown);
        $this->assertSame('80.00', $item->unit_price);
    }

    public function test_sending_twice_does_not_order_the_food_twice(): void
    {
        $cart = $this->cart();
        $cart->add($this->session, $this->noodle->id, 1, [], null, $this->phoneA, null);
        $cart->requestSubmit($this->session, $this->phoneA, null);

        $this->travel(6)->seconds();

        $cart->submitNow($this->session->fresh(), $this->placeOrder());

        try {
            $cart->submitNow($this->session->fresh(), $this->placeOrder());
            $this->fail('ยิงส่งซ้ำต้องไม่ผ่าน');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ยกเลิก', $e->getMessage());
        }

        $this->assertSame(1, OrderItem::count(), 'อาหารต้องไม่ถูกสั่งซ้ำ');
    }

    public function test_an_empty_cart_cannot_start_a_countdown(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ว่าง');

        $this->cart()->requestSubmit($this->session, $this->phoneA, null);
    }

    /* ---------- ผ่านหน้าเว็บจริง ---------- */

    public function test_the_http_endpoints_return_the_fresh_cart(): void
    {
        $this->scanQr();

        $this->post('/order/cart', [
            'product_id' => $this->noodle->id,
            'qty' => 2,
        ])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('cart.count', $this->isQty(2));

        $line = TableCartItem::firstOrFail();

        $this->patch("/order/cart/{$line->id}", ['qty' => 5])
            ->assertOk()
            ->assertJsonPath('cart.count', $this->isQty(5));

        $this->delete("/order/cart/{$line->id}")
            ->assertOk()
            ->assertJsonPath('cart.count', $this->isQty(0));
    }

    public function test_the_bill_feed_carries_the_cart(): void
    {
        $this->scanQr();

        $this->post('/order/cart', ['product_id' => $this->noodle->id, 'qty' => 1])->assertOk();

        $this->get('/order/bill')
            ->assertOk()
            ->assertJsonStructure(['bill', 'cart' => ['lines', 'count', 'total', 'submit_at']])
            ->assertJsonPath('cart.count', $this->isQty(1));
    }

    public function test_someone_who_is_not_seated_has_no_shared_cart(): void
    {
        // เข้าหน้าร้านตรง ๆ โดยไม่ได้สแกน QR = สั่งกลับบ้าน ใช้ตะกร้าในเบราว์เซอร์ตัวเอง
        $this->post('/order/cart', ['product_id' => $this->noodle->id, 'qty' => 1])
            ->assertStatus(409)
            ->assertJsonPath('seated', false);

        $this->assertSame(0, TableCartItem::count());
    }

    public function test_touching_a_line_that_someone_else_already_removed_says_so(): void
    {
        $this->scanQr();

        $line = $this->cart()->add($this->session, $this->noodle->id, 1, [], null, $this->phoneA, null);
        $line->delete();

        $this->patch('/order/cart/'.$line->id, ['qty' => 3])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_the_whole_flow_through_the_web_pages(): void
    {
        $this->scanQr();

        $this->post('/order/cart', ['product_id' => $this->noodle->id, 'qty' => 2])->assertOk();
        $this->post('/order/cart/submit')->assertOk()->assertJsonPath('cart.submit_at', fn ($v) => $v !== null);

        // ยังไม่ครบเวลา ส่งไม่ได้
        $this->post('/order/cart/submit/confirm', $this->contact())->assertStatus(422);

        $this->travel(6)->seconds();

        $this->post('/order/cart/submit/confirm', $this->contact())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('cart.count', $this->isQty(0));

        $item = OrderItem::firstOrFail();

        $this->assertSame('pending', $item->approval_status, 'ยังต้องให้พนักงานกดยืนยันก่อนเข้าครัว');
        $this->assertSame(0, TableCartItem::count());
    }

    /* ---------- ตัวช่วย ---------- */

    protected function cart(): TableCartService
    {
        return app(TableCartService::class);
    }

    /** สแกน QR จริง ๆ เพื่อให้ session ผูกโต๊ะเหมือนลูกค้าที่นั่งอยู่ */
    protected function scanQr(): void
    {
        $this->get('/t/'.$this->table->qr_token)->assertRedirect(route('storefront.menu'));
    }

    /** @return array<string, string> ข้อมูลติดต่อขั้นต่ำของหน้าเช็คเอาต์ */
    protected function contact(): array
    {
        return [
            'name' => 'ลูกค้าทดสอบ',
            'phone' => '0800000000',
            'payment_intent' => PaymentIntent::PayAtStore->value,
        ];
    }

    /**
     * ตัวเปิดบิลที่ตะกร้าเรียกตอนนับครบ
     *
     * ตะกร้าไม่รู้จักการเปิดบิล มันแค่ส่ง lines ให้ชั้นบนไปทำต่อ
     * เทสต์จึงต้องประกอบร่างเองเหมือนที่ controller ทำ
     */
    protected function placeOrder(): callable
    {
        return fn (array $lines) => app(OnlineOrderService::class)->place(
            branch: $this->branch,
            lines: $lines,
            contact: ['name' => 'ลูกค้าทดสอบ', 'phone' => '0800000000'],
            type: OrderType::DineIn,
            pickupAt: $this->branch->earliestPickupAt(),
            intent: PaymentIntent::PayAtStore,
            table: $this->table,
        );
    }

    /** @return array{0: ModifierGroup, 1: Modifier} */
    /**
     * เทียบจำนวนที่กลับมาใน JSON โดยไม่แคร์ว่าเป็น int หรือ float
     *
     * `count` ในเซอร์วิสเป็น float เสมอ แต่ `json_encode()` ของ PHP ตัด `.0` ทิ้ง
     * ถ้าไม่ได้เปิด JSON_PRESERVE_ZERO_FRACTION (ซึ่ง Laravel ไม่ได้เปิด)
     * 2.0 จึงออกไปเป็น `2` แล้ว decode กลับมาเป็น int — `assertJsonPath` เทียบแบบ
     * identical จึงตกทั้งที่ค่าถูกต้อง
     *
     * ปิดช่องนี้ด้วยการเทียบค่าเป็นตัวเลข ไม่ใช่เทียบชนิด เพราะสิ่งที่เทสต์นี้สนใจคือ
     * "ในตะกร้ามีกี่ที่" ไม่ใช่ "PHP ห่อเลขนั้นมาเป็นชนิดอะไร"
     */
    protected function isQty(float $expected): \Closure
    {
        return fn ($value) => is_numeric($value) && abs((float) $value - $expected) < 0.0001;
    }

    protected function makeModifier(float $delta, string $name = 'พิเศษ'): array
    {
        $group = ModifierGroup::create([
            'branch_id' => $this->branch->id,
            'name' => 'ขนาด',
            'max_select' => 3,
        ]);

        $modifier = Modifier::create([
            'modifier_group_id' => $group->id,
            'name' => $name,
            'price_delta' => $delta,
        ]);

        $this->noodle->modifierGroups()->attach($group->id, ['is_active' => true, 'sort_order' => 0]);

        return [$group, $modifier];
    }
}
