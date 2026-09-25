<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionItem;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * โปรโมชั่น — เอนจินคิดโปรและการต่อเข้าบิลจริง
 *
 * ── ทำไมต้องมีชุดนี้ ────────────────────────────────────────────────
 * โปรถูกคิดใหม่ทุกครั้งที่บิลขยับ (OrderService::recalculate) โดยไม่มีใครกด
 * แปลว่ามันแตะ "ทุกบิลในร้าน" โดยอัตโนมัติ — ถ้าเอนจินคิดผิด
 * ไม่มีหน้าจอไหนมาเตือน ยอดจะเพี้ยนเงียบ ๆ ไปทั้งวัน
 *
 * ── สิ่งที่ชุดนี้คุมเป็นหลัก ──────────────────────────────────────────
 * 1. **บิลที่ปิดไปแล้วต้องไม่ถูกคิดโปรใหม่** — ร้านสร้างโปรตอนบ่าย
 *    ยอดของบิลเช้าที่รับเงินไปแล้วต้องไม่ขยับ ไม่งั้นเงินในลิ้นชักไม่ตรงระบบ
 * 2. บิลหนึ่งใบใช้โปรได้ใบเดียว — ใบที่ลูกค้าได้ลดมากที่สุด
 * 3. โปรแถมต้องไม่ถูกใส่เอง เพราะลูกค้าต้องเลือกเมนูของแถมเอง
 * 4. โปรของสาขาอื่น/หมดอายุ/ปิดอยู่ ต้องไม่หลุดเข้าบิล
 * 5. ส่วนลดต้องไม่ทะลุฐานที่ควรคิด (เมนูที่ร่วมรายการ vs ทั้งบิล)
 *
 * คณิตศาสตร์ล้วน (roundsFor / rewardAmount) ถูกคุมแยกไว้ในฮาร์เนสอีกชุด
 * ที่นี่คุม "การต่อสาย" — ตัวไหนเข้าเงื่อนไข ใบไหนชนะ เขียนลงบิลถูกไหม
 */
class PromotionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Category $noodleCategory;

    protected Product $noodle;      // 100 บาท

    protected Product $drink;       // 25 บาท

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = $this->makeBranch('PR1');

        $this->noodleCategory = Category::create([
            'branch_id' => $this->branch->id,
            'name' => 'ก๋วยเตี๋ยว',
        ]);

        $drinkCategory = Category::create([
            'branch_id' => $this->branch->id,
            'name' => 'เครื่องดื่ม',
        ]);

        $this->noodle = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->noodleCategory->id,
            'name' => 'หมี่ขาว',
            'price' => 100,
            'cost' => 30,
        ]);

        $this->drink = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $drinkCategory->id,
            'name' => 'ชาเย็น',
            'price' => 25,
            'cost' => 8,
        ]);

        $this->actingAs(User::create([
            'branch_id' => $this->branch->id,
            'name' => 'แคชเชียร์',
            'email' => 'promo@foodpos.test',
            'password' => 'password',
            'role' => 'cashier',
        ]));
    }

    /* ---------- ทางปกติ: โปรเข้าบิลเองโดยไม่มีใครกด ---------- */

    public function test_a_bill_wide_promotion_lands_on_the_bill_without_anyone_pressing_anything(): void
    {
        $this->promo('ลดท้ายบิล 10%', reward: 'bill_percent', rewardValue: 10);

        $order = $this->billWith([[$this->noodle, 2]]);   // 200

        $this->assertSame('20.00', $order->promotion_discount);
        $this->assertSame('180.00', $order->grand_total, 'ยอดสุทธิต้องหักโปรแล้ว');
    }

    public function test_it_snapshots_the_promotion_name_on_the_bill(): void
    {
        $promotion = $this->promo('ลดท้ายบิล 10%', reward: 'bill_percent', rewardValue: 10);

        $order = $this->billWith([[$this->noodle, 2]]);

        $row = $order->orderPromotions()->firstOrFail();

        $this->assertSame($promotion->id, (int) $row->promotion_id);
        $this->assertSame('ลดท้ายบิล 10%', $row->name);
        $this->assertSame('20.00', $row->discount_amount);

        // ร้านเปลี่ยนชื่อโปรทีหลัง ใบเสร็จเก่าต้องยังอ่านได้ว่าวันนั้นใช้โปรชื่ออะไร
        $promotion->update(['name' => 'ชื่อใหม่']);

        $this->assertSame('ลดท้ายบิล 10%', $row->fresh()->name);
    }

    public function test_dropping_the_qualifying_dish_takes_the_promotion_away_again(): void
    {
        $this->promo('ครบ 2 ชาม ลด 50', trigger: 'qty', triggerValue: 2, reward: 'bill_amount', rewardValue: 50);

        $order = $this->billWith([[$this->noodle, 1], [$this->noodle, 1]]);
        $this->assertSame('50.00', $order->promotion_discount);

        app(OrderService::class)->voidItem($order->activeItems()->firstOrFail(), 'ลูกค้าเปลี่ยนใจ');

        $order->refresh();

        $this->assertSame('0.00', $order->promotion_discount, 'เหลือชามเดียว โปรต้องหลุด');
        $this->assertSame(0, $order->orderPromotions()->count(), 'แถวโปรเก่าต้องถูกลบ ไม่ใช่ค้างไว้');
    }

    public function test_a_voided_dish_does_not_count_toward_the_condition(): void
    {
        $this->promo('ครบ 3 ชาม ลด 50', trigger: 'qty', triggerValue: 3, reward: 'bill_amount', rewardValue: 50);

        $order = $this->billWith([[$this->noodle, 2], [$this->noodle, 1]]);
        $this->assertSame('50.00', $order->promotion_discount, 'ฟิกซ์เจอร์ต้องเข้าโปรก่อน');

        app(OrderService::class)->voidItem($order->activeItems()->firstOrFail(), 'ยกเลิก');

        $this->assertSame('0.00', $order->fresh()->promotion_discount);
    }

    /* ---------- ด่านเงิน: บิลที่ปิดไปแล้วห้ามขยับ ---------- */

    public function test_a_promotion_created_after_payment_never_touches_the_paid_bill(): void
    {
        $order = $this->billWith([[$this->noodle, 2]]);   // 200 ไม่มีโปร

        app(PaymentService::class)->pay($order->refresh(), [
            ['method' => 'cash', 'amount' => 200, 'received' => 200],
        ]);

        $order->refresh();
        $paidTotal = $order->grand_total;

        // ร้านสร้างโปรตอนบ่าย แล้วมีอะไรก็ตามไปเรียก recalculate ซ้ำ
        $this->promo('ลดท้ายบิล 50%', reward: 'bill_percent', rewardValue: 50);

        app(OrderService::class)->recalculate($order);

        $order->refresh();

        $this->assertSame($paidTotal, $order->grand_total, 'ยอดของบิลที่รับเงินไปแล้วต้องไม่ขยับ');
        $this->assertSame('0.00', $order->promotion_discount);
        $this->assertSame(0, $order->orderPromotions()->count());
    }

    public function test_a_paid_bill_keeps_the_promotion_it_had_when_it_closed(): void
    {
        $this->promo('ลดท้ายบิล 10%', reward: 'bill_percent', rewardValue: 10);

        $order = $this->billWith([[$this->noodle, 2]]);
        $this->assertSame('20.00', $order->promotion_discount);

        app(PaymentService::class)->pay($order->refresh(), [
            ['method' => 'cash', 'amount' => 180, 'received' => 200],
        ]);

        // โปรถูกปิดหลังจากนั้น — ของเดิมในบิลต้องยังอยู่
        Promotion::query()->update(['is_active' => false]);

        app(OrderService::class)->recalculate($order->refresh());

        $order->refresh();

        $this->assertSame('20.00', $order->promotion_discount);
        $this->assertSame(1, $order->orderPromotions()->count());
    }

    /* ---------- บิลเดียวใช้โปรได้ใบเดียว ---------- */

    public function test_only_the_best_promotion_applies_when_several_qualify(): void
    {
        $this->promo('ลด 10%', reward: 'bill_percent', rewardValue: 10, sortOrder: 1);
        $big = $this->promo('ลด 60 บาท', reward: 'bill_amount', rewardValue: 60, sortOrder: 2);

        $order = $this->billWith([[$this->noodle, 2]]);   // 200 -> 10% = 20, 60 บาท = 60

        $this->assertSame('60.00', $order->promotion_discount, 'ต้องเลือกใบที่ลูกค้าได้ลดมากที่สุด');
        $this->assertSame(1, $order->orderPromotions()->count(), 'โปรซ้อนกันไม่ได้');
        $this->assertSame($big->id, (int) $order->orderPromotions()->firstOrFail()->promotion_id);
    }

    public function test_a_tie_is_broken_by_the_order_the_shop_arranged(): void
    {
        $first = $this->promo('ลด 50 บาท (ก)', reward: 'bill_amount', rewardValue: 50, sortOrder: 1);
        $this->promo('ลด 50 บาท (ข)', reward: 'bill_amount', rewardValue: 50, sortOrder: 2);

        $order = $this->billWith([[$this->noodle, 2]]);

        $this->assertSame($first->id, (int) $order->orderPromotions()->firstOrFail()->promotion_id);
    }

    /* ---------- โปรแถม: ระบบเลือกแทนลูกค้าไม่ได้ ---------- */

    public function test_a_free_item_promotion_is_offered_but_never_applied_by_itself(): void
    {
        $promotion = $this->promo('ครบ 2 ชาม แถมน้ำ', trigger: 'qty', triggerValue: 2, reward: 'free_item');
        $this->rewardItem($promotion, $this->drink);

        $order = $this->billWith([[$this->noodle, 2]]);

        $this->assertSame('0.00', $order->promotion_discount, 'ระบบต้องไม่ยัดของแถมที่ลูกค้าไม่ได้เลือกลงบิล');
        $this->assertSame(0, $order->orderPromotions()->count());

        $offers = app(PromotionService::class)->freeItemOffers($order);

        $this->assertCount(1, $offers, 'แต่หน้าจอต้องเห็นว่ามีของแถมให้เลือก');
        $this->assertSame($promotion->id, $offers[0]['promotion']->id);
        $this->assertSame(1, $offers[0]['free_qty']);
        $this->assertTrue($offers[0]['free_choices']->contains('id', $this->drink->id));
    }

    public function test_a_free_item_promotion_with_nothing_to_give_away_is_not_a_candidate(): void
    {
        // ตั้งโปรแถมไว้แต่ลืมเลือกเมนูของแถม — ต้องไม่โผล่ไปหลอกพนักงานว่าใช้ได้
        $this->promo('ครบ 2 ชาม แถม (ยังไม่ได้เลือกเมนู)', trigger: 'qty', triggerValue: 2, reward: 'free_item');

        $order = $this->billWith([[$this->noodle, 2]]);

        $this->assertSame([], app(PromotionService::class)->candidates($order));
    }

    public function test_a_sold_out_dish_is_not_offered_as_a_free_choice(): void
    {
        $promotion = $this->promo('ครบ 2 ชาม แถมน้ำ', trigger: 'qty', triggerValue: 2, reward: 'free_item');
        $this->rewardItem($promotion, $this->drink);

        $this->drink->update(['unavailable_until' => Carbon::now()->addHour()]);

        $order = $this->billWith([[$this->noodle, 2]]);

        $this->assertSame(
            [],
            app(PromotionService::class)->candidates($order),
            'เลือกได้แต่ครัวทำไม่ได้ = อย่าเสนอตั้งแต่แรก',
        );
    }

    public function test_the_biggest_auto_applicable_promotion_wins_even_if_a_free_item_deal_is_worth_more(): void
    {
        $free = $this->promo('ครบ 1 ชาม แถมหมี่', trigger: 'qty', triggerValue: 1, reward: 'free_item', sortOrder: 1);
        $this->rewardItem($free, $this->noodle);   // ของแถมมูลค่า 100

        $this->promo('ลด 10 บาท', reward: 'bill_amount', rewardValue: 10, sortOrder: 2);

        $order = $this->billWith([[$this->noodle, 2]]);

        // โปรแถมคุ้มกว่า แต่ใส่เองไม่ได้ — ต้องไล่ลงมาเจอใบที่ใส่ได้
        $this->assertSame('10.00', $order->promotion_discount);

        $candidates = app(PromotionService::class)->candidates($order);
        $this->assertSame($free->id, $candidates[0]['promotion']->id, 'ใบที่คุ้มสุดยังคงเป็นโปรแถม');
    }

    /* ---------- ขอบเขต: เมนู หมวด สาขา ช่วงเวลา ---------- */

    public function test_a_promotion_tied_to_a_category_ignores_dishes_outside_it(): void
    {
        $promotion = $this->promo('ก๋วยเตี๋ยวลด 10%', reward: 'item_percent', rewardValue: 10);
        $this->triggerCategory($promotion, $this->noodleCategory);

        $order = $this->billWith([[$this->noodle, 1], [$this->drink, 4]]);   // 100 + 100

        $this->assertSame('10.00', $order->promotion_discount, 'ลด 10% ของเฉพาะก๋วยเตี๋ยว 100 บาท');
    }

    public function test_a_promotion_tied_to_one_dish_ignores_the_rest_of_the_bill(): void
    {
        $promotion = $this->promo('หมี่ขาวลด 10%', reward: 'item_percent', rewardValue: 10);
        $this->triggerProduct($promotion, $this->noodle);

        $order = $this->billWith([[$this->noodle, 1], [$this->drink, 4]]);

        $this->assertSame('10.00', $order->promotion_discount);
    }

    public function test_a_promotion_with_no_dishes_tied_to_it_covers_the_whole_bill(): void
    {
        $this->promo('ทั้งร้านลด 10%', reward: 'item_percent', rewardValue: 10);

        $order = $this->billWith([[$this->noodle, 1], [$this->drink, 4]]);   // 200

        $this->assertSame('20.00', $order->promotion_discount);
    }

    public function test_an_item_discount_cannot_eat_into_dishes_outside_the_deal(): void
    {
        $promotion = $this->promo('ชาเย็นลด 500 บาท', reward: 'item_amount', rewardValue: 500);
        $this->triggerProduct($promotion, $this->drink);

        $order = $this->billWith([[$this->noodle, 1], [$this->drink, 1]]);   // 100 + 25

        $this->assertSame('25.00', $order->promotion_discount, 'ลดได้มากสุดเท่ายอดชาเย็น');
        $this->assertSame('100.00', $order->grand_total);
    }

    public function test_a_promotion_from_another_branch_never_reaches_this_bill(): void
    {
        $other = $this->makeBranch('PR2');

        Promotion::create([
            'branch_id' => $other->id,
            'name' => 'โปรสาขาอื่น',
            'trigger_type' => 'none',
            'reward_type' => 'bill_percent',
            'reward_value' => 50,
        ]);

        $order = $this->billWith([[$this->noodle, 2]]);

        $this->assertSame('0.00', $order->promotion_discount);
    }

    public function test_a_switched_off_promotion_does_not_apply(): void
    {
        $this->assertNotApplied(['is_active' => false]);
    }

    public function test_a_promotion_that_has_not_started_yet_does_not_apply(): void
    {
        $this->assertNotApplied(['starts_at' => Carbon::now()->addDay()]);
    }

    public function test_an_expired_promotion_does_not_apply(): void
    {
        $this->assertNotApplied(['ends_at' => Carbon::now()->subDay()]);
    }

    public function test_a_deleted_promotion_does_not_apply(): void
    {
        $promotion = $this->promo('ลดท้ายบิล 50%', reward: 'bill_percent', rewardValue: 50);

        $order = $this->billWith([[$this->noodle, 2]]);
        $this->assertSame('100.00', $order->promotion_discount, 'ฟิกซ์เจอร์ต้องเข้าโปรก่อน');

        $promotion->delete();

        app(OrderService::class)->recalculate($order);

        $this->assertSame('0.00', $order->fresh()->promotion_discount);
    }

    /* ---------- เพดานและจำนวนรอบ ---------- */

    public function test_the_shop_ceiling_caps_the_discount(): void
    {
        $this->promo('ลด 50% ไม่เกิน 30', reward: 'bill_percent', rewardValue: 50)
            ->update(['max_discount' => 30]);

        $order = $this->billWith([[$this->noodle, 2]]);   // 50% ของ 200 = 100

        $this->assertSame('30.00', $order->promotion_discount);
    }

    public function test_a_deal_can_repeat_within_one_bill_up_to_the_limit(): void
    {
        $this->promo('ครบ 2 ชาม ลด 20', trigger: 'qty', triggerValue: 2, reward: 'bill_amount', rewardValue: 20)
            ->update(['max_rounds' => 3]);

        $this->assertSame('40.00', $this->billWith([[$this->noodle, 4]])->promotion_discount, '4 ชาม = 2 รอบ');
        $this->assertSame('60.00', $this->billWith([[$this->noodle, 8]])->promotion_discount, '8 ชาม = 4 รอบ แต่ชนเพดาน 3');
    }

    public function test_a_deal_that_does_not_repeat_only_counts_once(): void
    {
        $this->promo('ครบ 2 ชาม ลด 20', trigger: 'qty', triggerValue: 2, reward: 'bill_amount', rewardValue: 20);

        $this->assertSame('20.00', $this->billWith([[$this->noodle, 6]])->promotion_discount);
    }

    public function test_a_condition_counted_in_baht_uses_the_price_of_the_qualifying_dishes(): void
    {
        $promotion = $this->promo(
            'ก๋วยเตี๋ยวครบ 200 ลด 30',
            trigger: 'amount',
            triggerValue: 200,
            reward: 'bill_amount',
            rewardValue: 30,
        );
        $this->triggerCategory($promotion, $this->noodleCategory);

        // ยอดบิลถึง 200 แต่ก๋วยเตี๋ยวแค่ 100 — ยังไม่เข้าเงื่อนไข
        $this->assertSame('0.00', $this->billWith([[$this->noodle, 1], [$this->drink, 4]])->promotion_discount);

        $this->assertSame('30.00', $this->billWith([[$this->noodle, 2]])->promotion_discount);
    }

    /* ---------- ฐานที่ใช้คิด ---------- */

    public function test_a_bill_wide_discount_is_taken_off_the_price_after_line_discounts(): void
    {
        $this->promo('ลดท้ายบิล 10%', reward: 'bill_percent', rewardValue: 10);

        $order = $this->billWith([[$this->noodle, 2]]);   // 200

        // พนักงานลดรายบรรทัดไป 50 ก่อน ฐานโปรต้องเหลือ 150
        $item = $order->activeItems()->firstOrFail();
        $item->update(['discount' => 50]);

        app(OrderService::class)->recalculate($order);

        $this->assertSame('15.00', $order->fresh()->promotion_discount);
    }

    public function test_the_promotion_discount_also_shrinks_the_tax_the_bill_carries(): void
    {
        $before = $this->billWith([[$this->noodle, 2]]);
        $taxBefore = (float) $before->tax_amount;

        $this->promo('ลดท้ายบิล 10%', reward: 'bill_percent', rewardValue: 10);

        $after = $this->billWith([[$this->noodle, 2]]);

        $this->assertGreaterThan(
            (float) $after->tax_amount,
            $taxBefore,
            'ลดราคาแล้วภาษีต้องลดตาม ไม่ใช่คิดจากยอดเต็ม',
        );
        $this->assertSame('11.78', $after->tax_amount, '180 * 7 / 107');
    }

    /* ---------- ตัวช่วย ---------- */

    /** โปรที่ตั้งค่าแบบนี้ต้องไม่หลุดเข้าบิล ทั้งที่เงื่อนไขอื่นเข้าหมดแล้ว */
    protected function assertNotApplied(array $overrides): void
    {
        $promotion = $this->promo('ลดท้ายบิล 50%', reward: 'bill_percent', rewardValue: 50);

        // พิสูจน์ก่อนว่าฟิกซ์เจอร์เข้าโปรได้จริง ไม่งั้นเทสต์จะผ่านด้วยเหตุผลที่ผิด
        $this->assertSame('100.00', $this->billWith([[$this->noodle, 2]])->promotion_discount);

        $promotion->update($overrides);

        $this->assertSame('0.00', $this->billWith([[$this->noodle, 2]])->promotion_discount);
    }

    protected function promo(
        string $name,
        string $trigger = 'none',
        float $triggerValue = 0,
        string $reward = 'bill_percent',
        float $rewardValue = 0,
        int $sortOrder = 0,
    ): Promotion {
        return Promotion::create([
            'branch_id' => $this->branch->id,
            'name' => $name,
            'trigger_type' => $trigger,
            'trigger_value' => $triggerValue,
            'reward_type' => $reward,
            'reward_value' => $rewardValue,
            'sort_order' => $sortOrder,
        ]);
    }

    protected function triggerProduct(Promotion $promotion, Product $product): void
    {
        PromotionItem::create([
            'promotion_id' => $promotion->id,
            'role' => PromotionItem::ROLE_TRIGGER,
            'product_id' => $product->id,
        ]);
    }

    protected function triggerCategory(Promotion $promotion, Category $category): void
    {
        PromotionItem::create([
            'promotion_id' => $promotion->id,
            'role' => PromotionItem::ROLE_TRIGGER,
            'category_id' => $category->id,
        ]);
    }

    protected function rewardItem(Promotion $promotion, Product $product): void
    {
        PromotionItem::create([
            'promotion_id' => $promotion->id,
            'role' => PromotionItem::ROLE_REWARD,
            'product_id' => $product->id,
        ]);
    }

    /** @param  array<int, array{0: Product, 1: float}>  $lines */
    protected function billWith(array $lines): Order
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);

        foreach ($lines as [$product, $qty]) {
            $orders->addItem($order, $product, $qty);
        }

        return $order->refresh();
    }

    protected function makeBranch(string $code): Branch
    {
        return Branch::create([
            'code' => $code,
            'name' => 'สาขา '.$code,
            'vat_rate' => 7,
            'vat_included' => true,
            'service_charge_rate' => 0,
            'rounding_mode' => 0,
            'business_day_start' => '05:00:00',
        ]);
    }
}
