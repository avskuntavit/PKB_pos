<?php

namespace Tests\Feature;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentIntent;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OnlineOrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OnlineOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Product $noodle;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'T1',
            'name' => 'สาขาทดสอบ',
            'vat_rate' => 7,
            'vat_included' => true,
            'service_charge_rate' => 0,
            'rounding_mode' => 0,
            'business_day_start' => '05:00:00',
            'is_accepting_online_orders' => true,
            'open_time' => '00:00:00',
            'close_time' => '23:59:59',
            'prep_minutes' => 20,
            'award_points_online' => true,
            'promptpay_id' => '0899999999',
        ]);

        $category = Category::create(['branch_id' => $this->branch->id, 'name' => 'ก๋วยเตี๋ยว']);

        $this->noodle = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'หมี่ขาว หมูหมัก',
            'price' => 50,
            'cost' => 18,
        ]);

        $this->staff = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'พนักงานทดสอบ',
            'email' => 'staff@foodpos.test',
            'password' => 'password',
            'role' => 'manager',
        ]);
    }

    private function place(array $overrides = []): Order
    {
        return app(OnlineOrderService::class)->place(
            branch: $overrides['branch'] ?? $this->branch,
            lines: $overrides['lines'] ?? [['product_id' => $this->noodle->id, 'qty' => 2]],
            contact: $overrides['contact'] ?? ['name' => 'คุณพลอย', 'phone' => '0811111111'],
            type: $overrides['type'] ?? OrderType::Takeaway,
            pickupAt: $overrides['pickupAt'] ?? Carbon::now()->addMinutes(30),
            intent: $overrides['intent'] ?? PaymentIntent::PayAtStore,
            staff: $overrides['staff'] ?? null,
            table: $overrides['table'] ?? null,
        );
    }

    public function test_a_guest_can_order_from_the_storefront_without_logging_in(): void
    {
        $this->get('/order')->assertOk();

        $order = $this->place();

        $this->assertSame(OrderSource::Online, $order->source);
        $this->assertSame(FulfilmentStatus::Placed, $order->fulfilment_status);
        $this->assertSame('100.00', $order->subtotal);
        $this->assertNotEmpty($order->track_token);

        // รอร้านกดรับก่อน ยังไม่เข้าครัว
        $this->assertSame(0, KitchenTicket::count());
        $this->assertSame(1, $order->pendingApprovalItems()->count());
    }

    public function test_the_phone_number_links_the_order_to_a_customer_record(): void
    {
        $this->place();

        $customer = Customer::where('phone', '0811111111')->first();
        $this->assertNotNull($customer);
        $this->assertSame('คุณพลอย', $customer->name);

        // สั่งซ้ำด้วยเบอร์เดิมต้องไม่สร้างลูกค้าซ้ำ
        $this->place();
        $this->assertSame(1, Customer::where('phone', '0811111111')->count());
    }

    public function test_accepting_sends_the_order_to_the_kitchen(): void
    {
        $this->actingAs($this->staff);

        $order = $this->place();
        app(OnlineOrderService::class)->accept($order, $this->staff);

        $order->refresh();

        $this->assertSame(FulfilmentStatus::Accepted, $order->fulfilment_status);
        $this->assertNotNull($order->accepted_at);
        $this->assertSame(1, KitchenTicket::count());
        $this->assertSame(0, $order->pendingApprovalItems()->count());
    }

    public function test_rejecting_voids_the_bill_and_keeps_the_reason_for_the_customer(): void
    {
        $this->actingAs($this->staff);

        $order = $this->place();
        app(OnlineOrderService::class)->reject($order, 'ของหมด', $this->staff);

        $order->refresh();

        $this->assertSame(FulfilmentStatus::Rejected, $order->fulfilment_status);
        $this->assertSame(OrderStatus::Void, $order->status);
        $this->assertSame('ของหมด', $order->reject_reason);
    }

    public function test_a_customer_can_cancel_only_before_the_shop_accepts(): void
    {
        $order = $this->place();

        app(OnlineOrderService::class)->cancelByCustomer($order);
        $this->assertSame(FulfilmentStatus::Cancelled, $order->fresh()->fulfilment_status);

        // อีกใบ: ร้านรับแล้ว ยกเลิกเองไม่ได้
        $accepted = $this->place();
        app(OnlineOrderService::class)->accept($accepted, $this->staff);

        $this->expectException(\DomainException::class);
        app(OnlineOrderService::class)->cancelByCustomer($accepted->fresh());
    }

    public function test_staff_orders_skip_approval_and_go_straight_to_the_kitchen(): void
    {
        $this->actingAs($this->staff);

        $order = $this->place(['staff' => $this->staff]);

        $this->assertSame(OrderSource::Pos, $order->source);
        $this->assertSame(FulfilmentStatus::Accepted, $order->fulfilment_status);
        $this->assertSame($this->staff->id, $order->placed_by_user_id);
        $this->assertSame(0, $order->pendingApprovalItems()->count());
        $this->assertSame(1, KitchenTicket::count());
    }

    public function test_staff_can_order_even_when_the_shop_is_closed_to_online_orders(): void
    {
        $this->branch->update(['is_accepting_online_orders' => false]);
        $this->actingAs($this->staff);

        $order = $this->place(['staff' => $this->staff, 'branch' => $this->branch->fresh()]);
        $this->assertNotNull($order->id);

        // แต่ลูกค้าทั่วไปสั่งไม่ได้
        $this->expectException(\DomainException::class);
        $this->place(['branch' => $this->branch->fresh()]);
    }

    public function test_paying_completes_the_online_order_and_awards_points(): void
    {
        $this->actingAs($this->staff);

        $order = $this->place();
        app(OnlineOrderService::class)->accept($order, $this->staff);

        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'cash', 'amount' => 100, 'received' => 100],
        ]);

        $order->refresh();

        $this->assertSame(FulfilmentStatus::Completed, $order->fulfilment_status);
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertGreaterThan(0, Customer::where('phone', '0811111111')->first()->points);
    }

    public function test_points_can_be_switched_off_for_online_orders(): void
    {
        $this->branch->update(['award_points_online' => false]);
        $this->actingAs($this->staff);

        $order = $this->place(['branch' => $this->branch->fresh()]);
        app(OnlineOrderService::class)->accept($order, $this->staff);

        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'cash', 'amount' => 100, 'received' => 100],
        ]);

        $this->assertSame(0, Customer::where('phone', '0811111111')->first()->points);
    }

    public function test_a_government_scheme_is_recorded_as_an_intent_only(): void
    {
        $order = $this->place(['intent' => PaymentIntent::KhonLaKhrueng]);

        $this->assertSame(PaymentIntent::KhonLaKhrueng, $order->payment_intent);
        $this->assertTrue($order->payment_intent->isGovernmentScheme());

        // ยังไม่มีการชำระเงินใด ๆ เกิดขึ้น — เงินเข้าตอนพนักงานออก QR ที่เคาน์เตอร์
        $this->assertSame(0, $order->payments()->count());
        $this->assertSame(OrderStatus::Open, $order->status);
    }

    public function test_the_tracking_page_is_reachable_only_with_the_token(): void
    {
        $order = $this->place();

        $this->get('/order/track/'.$order->track_token)->assertOk();
        $this->get('/order/track/'.str_repeat('x', 40))->assertNotFound();
    }

    public function test_a_government_scheme_bill_is_settled_by_staff_as_a_split_payment(): void
    {
        $this->actingAs($this->staff);

        // ลูกค้าแจ้งว่าจะจ่ายผ่านคนละครึ่ง — ตอนสั่งยังไม่มีเงินเข้าใด ๆ
        $order = $this->place(['intent' => PaymentIntent::KhonLaKhrueng]);
        app(OnlineOrderService::class)->accept($order, $this->staff);

        // พนักงานออก QR ที่เคาน์เตอร์ เห็นรายการสำเร็จในแอปโครงการ แล้วมากดบันทึกเอง
        // ยอด 100 บาท: โครงการช่วย 50 ลูกค้าจ่ายสดอีก 50
        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'khon_la_khrueng', 'amount' => 50, 'reference' => 'KLK-TEST-001'],
            ['method' => 'cash', 'amount' => 50, 'received' => 50],
        ]);

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(2, $order->payments()->count());

        // รายงานต้องแยกยอดสองช่องทางได้ ไม่ใช่ยุบเป็นก้อนเดียว
        $byMethod = $order->payments()->pluck('amount', 'method');
        $this->assertSame('50.00', $byMethod['khon_la_khrueng']);
        $this->assertSame('50.00', $byMethod['cash']);

        // เลขอ้างอิงจากแอปโครงการถูกเก็บไว้ให้ตรวจย้อนหลังได้
        $this->assertSame(
            'KLK-TEST-001',
            $order->payments()->where('method', 'khon_la_khrueng')->first()->reference,
        );
    }

    public function test_the_payment_intent_never_settles_the_bill_by_itself(): void
    {
        $this->actingAs($this->staff);

        // ระบบไม่ได้ต่อกับระบบชำระเงินใด ๆ การเลือกวิธีจ่ายจึงไม่ทำให้บิลปิดเอง
        foreach ([PaymentIntent::PromptPay, PaymentIntent::KhonLaKhrueng, PaymentIntent::ThaiChuayThai] as $intent) {
            $order = $this->place(['intent' => $intent]);

            $this->assertSame(OrderStatus::Open, $order->status);
            $this->assertSame(0, $order->payments()->count());
            $this->assertSame('0.00', $order->paid_amount);
        }
    }

    public function test_it_refuses_menu_items_from_another_branch(): void
    {
        $other = Branch::create([
            'code' => 'T2',
            'name' => 'อีกสาขา',
            'vat_rate' => 7,
            'vat_included' => true,
            'business_day_start' => '05:00:00',
            'open_time' => '00:00:00',
            'close_time' => '23:59:59',
        ]);

        $foreign = Product::create(['branch_id' => $other->id, 'name' => 'เมนูสาขาอื่น', 'price' => 999]);

        $this->expectException(\DomainException::class);
        $this->place(['lines' => [['product_id' => $foreign->id, 'qty' => 1]]]);
    }
}
