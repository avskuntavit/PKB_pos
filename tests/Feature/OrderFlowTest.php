<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

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
        ]);

        $this->actingAs(User::create([
            'branch_id' => $this->branch->id,
            'name' => 'แคชเชียร์ทดสอบ',
            'email' => 'test@foodpos.test',
            'password' => 'password',
            'role' => 'cashier',
        ]));
    }

    public function test_it_totals_a_bill_with_vat_included(): void
    {
        $category = Category::create(['branch_id' => $this->branch->id, 'name' => 'ก๋วยเตี๋ยว']);

        $product = Product::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'หมี่ขาว หมูหมัก',
            'price' => 50,
            'cost' => 18,
        ]);

        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);
        $orders->addItem($order, $product, 3);

        $order->refresh();

        $this->assertSame('150.00', $order->subtotal);
        $this->assertSame('150.00', $order->grand_total);
        $this->assertSame('9.81', $order->tax_amount); // 150 * 7 / 107
        $this->assertSame('54.00', $order->cost_total);
    }

    public function test_paying_closes_the_bill_and_records_change(): void
    {
        $product = Product::create([
            'branch_id' => $this->branch->id,
            'name' => 'ข้าวเปล่า',
            'price' => 15,
            'cost' => 4,
        ]);

        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);
        $orders->addItem($order, $product, 2);

        app(PaymentService::class)->pay($order->refresh(), [
            ['method' => 'cash', 'amount' => 30, 'received' => 100],
        ]);

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('70.00', $order->change_amount);
        $this->assertNotNull($order->receipt_no);
    }

    public function test_it_refuses_to_pay_less_than_the_bill_total(): void
    {
        $product = Product::create([
            'branch_id' => $this->branch->id,
            'name' => 'ชาเย็น',
            'price' => 35,
        ]);

        $orders = app(OrderService::class);
        $order = $orders->open($this->branch);
        $orders->addItem($order, $product, 2);

        $this->expectException(\DomainException::class);

        app(PaymentService::class)->pay($order->refresh(), [
            ['method' => 'cash', 'amount' => 50, 'received' => 50],
        ]);
    }
}
