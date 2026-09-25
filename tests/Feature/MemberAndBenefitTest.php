<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Enums\OrderType;
use App\Enums\PaymentIntent;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchPaymentMethod;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerOtpCode;
use App\Models\Order;
use App\Models\Product;
use App\Models\StaffBenefitUsage;
use App\Models\User;
use App\Services\OnlineOrderService;
use App\Services\OtpService;
use App\Services\PaymentService;
use App\Services\StaffBenefitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberAndBenefitTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Product $noodle;

    protected Product $beer;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        config(['foodpos.otp.driver' => 'null']);

        $this->branch = Branch::create([
            'code' => 'T1',
            'name' => 'สาขาทดสอบ',
            'vat_rate' => 7,
            'vat_included' => true,
            'service_charge_rate' => 0,
            'rounding_mode' => 0,
            'business_day_start' => '05:00:00',
            'open_time' => '00:00:00',
            'close_time' => '23:59:59',
            'prep_minutes' => 20,
            'is_accepting_online_orders' => true,
            'staff_benefit_enabled' => true,
            'staff_benefit_monthly_cap' => 100,
            'staff_benefit_exclude_alcohol' => true,
        ]);

        $category = Category::create(['branch_id' => $this->branch->id, 'name' => 'ก๋วยเตี๋ยว']);

        // ราคาปกติ 50 ราคาพนักงาน 30 -> ประหยัด 20 ต่อจาน
        $this->noodle = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'หมี่ขาว หมูหมัก',
            'price' => 50,
            'cost' => 18,
            'staff_price' => 30,
        ]);

        $this->beer = Product::create([
            'branch_id' => $this->branch->id,
            'name' => 'เบียร์ขวดใหญ่',
            'price' => 120,
            'cost' => 78,
            'staff_price' => 80,      // ตั้งไว้ แต่ต้องไม่ถูกใช้เพราะเป็นแอลกอฮอล์
            'is_alcohol' => true,
        ]);

        $this->staff = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'ผู้จัดการทดสอบ',
            'email' => 'manager@foodpos.test',
            'password' => 'password',
            'role' => UserRole::Manager,
        ]);
    }

    private function employee(): Customer
    {
        return Customer::create([
            'branch_id' => $this->branch->id,
            'name' => 'คุณพนักงาน',
            'phone' => '0811111111',
            'employee_code' => 'EMP001',
            'employee_status' => EmployeeStatus::Approved,
            'employee_reviewed_at' => now(),
        ]);
    }

    private function placeOrder(array $lines, ?Customer $member = null): Order
    {
        return app(OnlineOrderService::class)->place(
            branch: $this->branch,
            lines: $lines,
            contact: ['name' => 'คุณพนักงาน', 'phone' => '0811111111'],
            type: OrderType::Takeaway,
            pickupAt: null,
            intent: PaymentIntent::PromptPay,
            member: $member,
        );
    }

    /* ---------- OTP ---------- */

    public function test_otp_login_creates_the_member_on_first_verification(): void
    {
        $otp = app(OtpService::class);
        $otp->request('081-111-1111', $this->branch);

        $record = CustomerOtpCode::first();
        $this->assertNotNull($record);
        $this->assertSame('0811111111', $record->phone);        // ขีดกลางถูกตัดออก

        // รหัสถูกเก็บเป็น hash ไม่ใช่ตัวเลขตรง ๆ
        $this->assertNotSame('123456', $record->code_hash);

        // ปลอมรหัสที่รู้ค่าเพื่อทดสอบขั้นยืนยัน
        $record->update(['code_hash' => Hash::make('123456')]);

        $customer = $otp->verify('0811111111', '123456', $this->branch, 'คุณใหม่');

        $this->assertSame('0811111111', $customer->phone);
        $this->assertNotNull($customer->phone_verified_at);
        $this->assertNotNull($record->fresh()->consumed_at);
    }

    public function test_a_wrong_code_counts_attempts_and_locks_after_the_limit(): void
    {
        $otp = app(OtpService::class);
        $otp->request('0811111111', $this->branch);

        CustomerOtpCode::first()->update(['code_hash' => Hash::make('123456')]);

        for ($i = 0; $i < 5; $i++) {
            try {
                $otp->verify('0811111111', '999999', $this->branch);
            } catch (\DomainException) {
                // นับครั้งที่ผิดไปเรื่อย ๆ
            }
        }

        $this->assertSame(5, CustomerOtpCode::first()->attempts);

        // ครบโควตาแล้ว รหัสที่ถูกก็ใช้ไม่ได้ ต้องขอใหม่
        $this->expectException(\DomainException::class);
        $otp->verify('0811111111', '123456', $this->branch);
    }

    public function test_requesting_a_new_code_too_soon_is_blocked(): void
    {
        $otp = app(OtpService::class);
        $otp->request('0811111111', $this->branch);

        $this->expectException(\DomainException::class);
        $otp->request('0811111111', $this->branch);
    }

    /* ---------- สิทธิ์พนักงานองค์กร ---------- */

    public function test_hr_must_approve_before_the_staff_price_applies(): void
    {
        $customer = Customer::create([
            'branch_id' => $this->branch->id,
            'name' => 'คุณรออนุมัติ',
            'phone' => '0822222222',
            'employee_code' => 'EMP999',
            'employee_status' => EmployeeStatus::Pending,
        ]);

        $order = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 2]], $customer);

        $this->assertSame('0.00', $order->fresh()->staff_discount);
    }

    public function test_approved_employee_gets_the_staff_price(): void
    {
        $order = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 2]], $this->employee());

        // (50 - 30) x 2 = 40
        $this->assertSame('40.00', $order->fresh()->staff_discount);
        $this->assertSame('100.00', $order->fresh()->subtotal);
        $this->assertSame('60.00', $order->fresh()->grand_total);
    }

    public function test_alcohol_is_excluded_from_the_benefit(): void
    {
        // สร้างพนักงานครั้งเดียวแล้วใช้ซ้ำ — employee() สร้างแถวใหม่ทุกครั้งที่เรียก
        // เรียกสองรอบจะชน unique(branch_id, employee_code) แล้วเทสต์ล้มด้วยเหตุผลคนละเรื่อง
        $customer = $this->employee();

        $order = $this->placeOrder([['product_id' => $this->beer->id, 'qty' => 1]], $customer);

        $this->assertSame('0.00', $order->fresh()->staff_discount);

        $preview = app(StaffBenefitService::class)->preview($order->fresh(), $customer);
        $this->assertContains('เบียร์ขวดใหญ่', $preview['excluded']);
    }

    public function test_the_monthly_cap_limits_the_discount(): void
    {
        $customer = $this->employee();

        // เพดาน 100 — สั่ง 10 จานจะประหยัดได้ 200 แต่ต้องถูกตัดเหลือ 100
        $order = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 10]], $customer);

        $this->assertSame('100.00', $order->fresh()->staff_discount);

        $preview = app(StaffBenefitService::class)->preview($order->fresh(), $customer);
        $this->assertTrue($preview['capped']);
    }

    public function test_the_cap_counts_usage_already_recorded_this_month(): void
    {
        $customer = $this->employee();

        // งวดของ "วันขาย" ไม่ใช่เดือนตามนาฬิกา — ดูเหตุผลที่หมวดงวดเดือนด้านล่าง
        $this->seedUsage(
            $customer,
            80,
            $this->branch->currentPeriod(),
            $this->branch->businessDateFor()->toDateString(),
        );

        // ใช้ไปแล้ว 80 จากเพดาน 100 -> รอบนี้ลดได้อีกแค่ 20
        $order = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 5]], $customer->fresh());

        $this->assertSame('20.00', $order->fresh()->staff_discount);
    }

    public function test_paying_records_the_benefit_for_the_company_to_reimburse(): void
    {
        $this->actingAs($this->staff);

        $customer = $this->employee();
        $order = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 2]], $customer);

        app(OnlineOrderService::class)->accept($order, $this->staff);

        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'promptpay', 'amount' => 60],
        ]);

        $usage = StaffBenefitUsage::where('order_id', $order->id)->first();

        $this->assertNotNull($usage);
        $this->assertSame('40.00', $usage->discount_amount);
        $this->assertSame('EMP001', $usage->employee_code);
        $this->assertSame($this->branch->currentPeriod(), $usage->period);
    }

    /* ---------- ช่องทางชำระเงินต่อสาขา ---------- */

    public function test_a_disabled_payment_method_is_refused_by_the_server(): void
    {
        $this->actingAs($this->staff);

        // ร้านนี้ไม่รับเงินสด
        BranchPaymentMethod::create([
            'branch_id' => $this->branch->id,
            'method' => 'cash',
            'is_enabled' => false,
        ]);
        BranchPaymentMethod::create([
            'branch_id' => $this->branch->id,
            'method' => 'promptpay',
            'is_enabled' => true,
        ]);

        $order = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 1]]);
        app(OnlineOrderService::class)->accept($order, $this->staff);

        $this->expectException(\DomainException::class);
        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'cash', 'amount' => 50, 'received' => 50],
        ]);
    }

    /* ---------- คิว ---------- */

    public function test_queue_numbers_run_per_day_and_only_after_the_shop_accepts(): void
    {
        $this->actingAs($this->staff);

        $first = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 1]]);
        $this->assertNull($first->queue_number);   // ยังไม่รับ ยังไม่กินเลขคิว

        app(OnlineOrderService::class)->accept($first, $this->staff);
        $this->assertSame(1, $first->fresh()->queue_number);

        $second = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 1]]);
        app(OnlineOrderService::class)->accept($second, $this->staff);
        $this->assertSame(2, $second->fresh()->queue_number);
    }

    /* ---------- สิทธิ์พนักงานร้าน ---------- */

    public function test_role_defaults_and_per_user_overrides(): void
    {
        $cashier = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'แคชเชียร์',
            'email' => 'cashier@foodpos.test',
            'password' => 'password',
            'role' => UserRole::Cashier,
        ]);

        // ค่าตั้งต้นของแคชเชียร์: รับเงินได้ แต่ทำลายบิลไม่ได้
        $this->assertTrue($cashier->can(Permission::PaymentTake));
        $this->assertFalse($cashier->can(Permission::OrderVoid));
        $this->assertFalse($cashier->can(Permission::BackOfficeAccess));

        // เพิ่มสิทธิ์พิเศษเฉพาะคนนี้
        $cashier->update(['permissions' => ['grant' => [Permission::ShiftClose->value], 'revoke' => [Permission::OrderDiscount->value]]]);
        $cashier->refresh();

        $this->assertTrue($cashier->can(Permission::ShiftClose));
        $this->assertFalse($cashier->can(Permission::OrderDiscount));
        $this->assertTrue($cashier->can(Permission::PaymentTake));   // ที่เหลือยังตามตำแหน่ง
    }

    public function test_an_inactive_account_loses_every_permission(): void
    {
        $this->staff->update(['is_active' => false]);

        $this->assertFalse($this->staff->fresh()->can(Permission::PaymentTake));
        $this->assertFalse($this->staff->fresh()->can(Permission::BackOfficeAccess));
    }

    /* ---------- งวดเดือนของวงเงินสวัสดิการ ---------- */

    /*
    | ร้านตัดรอบวันขาย 05:00 — ตีสองของวันที่ 1 ยังเป็น "เมื่อคืน" ในทางบัญชี
    |
    | ตัวบันทึก (record) ใช้ $order->business_date เป็นงวดมาตลอด
    | แต่ตัวหายอดสะสมเคยใช้ now() ทั้งสองจึงชี้ไปคนละเดือนในช่วงเวลานี้
    | ผลคือเพดานวงเงินเปิดให้ใช้ใหม่ทั้งก้อนตอนเที่ยงคืน เร็วไปห้าชั่วโมง
    |
    | ห้าชั่วโมงต่อเดือน x 12 = 60 ชั่วโมงต่อปีที่พนักงานใช้เกินเพดานได้
    | และเป็นช่วงที่ไม่มีใครเฝ้าดู
    */

    public function test_the_cap_still_counts_last_nights_usage_after_midnight_on_the_first(): void
    {
        // ตีสองของวันที่ 1 ตุลาคม — วันขายยังเป็น 30 กันยายน
        $this->travelTo(Carbon::parse('2026-10-01 02:00:00', $this->branch->timezone));

        $customer = $this->employee();

        // ใช้ไปแล้ว 80 จากบิลเมื่อหัวค่ำ ซึ่งถูกบันทึกในงวด 2026-09
        $this->seedUsage($customer, 80, '2026-09', '2026-09-30');

        // เพดาน 100 เหลือ 20 — สั่ง 5 จานประหยัดได้ 100 แต่ต้องได้แค่ 20
        $order = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 5]], $customer->fresh());

        $this->assertSame('20.00', $order->fresh()->staff_discount, 'ยอดเมื่อคืนต้องยังนับอยู่');
    }

    public function test_a_bill_after_midnight_is_recorded_in_the_business_month_not_the_clock_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 02:00:00', $this->branch->timezone));

        $customer = $this->employee();
        $order = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 1]], $customer);

        $this->assertSame('2026-09-30', $order->business_date->toDateString(), 'ฟิกซ์เจอร์: วันขายต้องเป็นเมื่อวาน');

        $preview = app(StaffBenefitService::class)->preview($order->fresh(), $customer->fresh());

        $this->assertSame('2026-09', $preview['period'], 'งวดของบิลต้องเป็นเดือนของวันขาย');
    }

    public function test_the_cap_resets_once_the_business_month_really_turns_over(): void
    {
        // สิบโมงเช้าของวันที่ 1 — พ้นเวลาตัดรอบแล้ว วันขายเป็นเดือนใหม่จริง
        $this->travelTo(Carbon::parse('2026-10-01 10:00:00', $this->branch->timezone));

        $customer = $this->employee();
        $this->seedUsage($customer, 80, '2026-09', '2026-09-30');

        $order = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 5]], $customer->fresh());

        $this->assertSame('100.00', $order->fresh()->staff_discount, 'ยอดของเดือนก่อนต้องไม่ตามมากินวงเงินเดือนใหม่');
    }

    public function test_the_usage_recorded_at_closing_lands_in_the_same_month_the_cap_was_checked_against(): void
    {
        /*
        | ด่านสำคัญที่สุดของชุดนี้ — ตัวเช็คเพดานกับตัวบันทึกต้องพูดถึงเดือนเดียวกัน
        | ถ้าสองตัวนี้หลุดจากกันอีก เพดานจะรั่วแบบเดิมโดยไม่มีเทสต์ไหนร้อง
        */
        $this->travelTo(Carbon::parse('2026-10-01 02:00:00', $this->branch->timezone));
        $this->actingAs($this->staff);

        $customer = $this->employee();
        $order = $this->placeOrder([['product_id' => $this->noodle->id, 'qty' => 2]], $customer);

        $preview = app(StaffBenefitService::class)->preview($order->fresh(), $customer->fresh());

        app(OnlineOrderService::class)->accept($order->fresh(), $this->staff);
        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'promptpay', 'amount' => 60],
        ]);

        $usage = StaffBenefitUsage::where('order_id', $order->id)->firstOrFail();

        $this->assertSame($preview['period'], $usage->period);
        $this->assertSame('2026-09', $usage->period);
    }

    public function test_the_balance_the_customer_sees_uses_the_business_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 02:00:00', $this->branch->timezone));

        $customer = $this->employee();
        $this->seedUsage($customer, 80, '2026-09', '2026-09-30');

        $balance = app(StaffBenefitService::class)->balanceFor($customer->fresh(), $this->branch);

        $this->assertSame('2026-09', $balance['period'], 'หน้าบัญชีลูกค้าต้องบอกงวดเดียวกับที่แคชเชียร์เห็น');
        $this->assertSame(80.0, $balance['used']);
        $this->assertSame(20.0, $balance['remaining']);
    }

    public function test_a_shop_that_does_not_sell_past_midnight_sees_no_difference(): void
    {
        // ตัดรอบเที่ยงคืน = เดือนของวันขายตรงกับเดือนตามนาฬิกาเสมอ
        $this->branch->update(['business_day_start' => '00:00:00']);
        $this->travelTo(Carbon::parse('2026-10-01 02:00:00', $this->branch->timezone));

        $this->assertSame('2026-10', $this->branch->fresh()->currentPeriod());
    }

    /** เงินสวัสดิการที่ถูกใช้ไปแล้วในงวดหนึ่ง — ใส่ตรง ๆ เพราะจำลอง "บิลของเมื่อคืน" */
    private function seedUsage(Customer $customer, float $amount, string $period, string $businessDate): StaffBenefitUsage
    {
        return StaffBenefitUsage::create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'order_id' => Order::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'branch_id' => $this->branch->id,
                'order_no' => 'SEED'.substr(md5($period.$businessDate), 0, 6),
                'business_date' => $businessDate,
                'type' => OrderType::Takeaway,
                'status' => 'paid',
            ])->id,
            'employee_code' => $customer->employee_code,
            'period' => $period,
            'discount_amount' => $amount,
            'order_total' => 200,
            'business_date' => $businessDate,
        ]);
    }
}
