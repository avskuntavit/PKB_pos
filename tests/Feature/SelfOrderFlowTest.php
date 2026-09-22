<?php

namespace Tests\Feature;

use App\Enums\KitchenTicketStatus;
use App\Enums\OrderSource;
use App\Enums\PrintGroup;
use App\Enums\ServiceCallType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Product;
use App\Models\User;
use App\Services\KitchenService;
use App\Services\PaymentService;
use App\Services\SelfOrderService;
use App\Services\TableSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected DiningTable $table;

    protected Product $noodle;

    protected Product $drink;

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
            'name' => 'หมี่ขาว หมูหมัก',
            'price' => 50,
            'cost' => 18,
            'print_group' => PrintGroup::Kitchen,
        ]);

        $this->drink = Product::create([
            'branch_id' => $this->branch->id,
            'name' => 'เป๊ปซี่',
            'price' => 20,
            'cost' => 12,
            'print_group' => PrintGroup::Bar,
        ]);

        $this->staff = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'พนักงานทดสอบ',
            'email' => 'staff@foodpos.test',
            'password' => 'password',
            'role' => 'manager',
        ]);
    }

    public function test_every_table_gets_a_unique_qr_token(): void
    {
        $other = DiningTable::create(['branch_id' => $this->branch->id, 'name' => 'A2', 'seats' => 2]);

        $this->assertNotEmpty($this->table->qr_token);
        $this->assertNotSame($this->table->qr_token, $other->qr_token);
        $this->assertStringContainsString('/t/'.$this->table->qr_token, $this->table->qrUrl());
    }

    public function test_guest_can_order_without_logging_in(): void
    {
        $response = $this->get('/t/'.$this->table->qr_token);
        $response->assertOk();

        $this->post('/t/'.$this->table->qr_token.'/orders', [
            'lines' => [
                ['product_id' => $this->noodle->id, 'qty' => 2, 'note' => 'ไม่ใส่ผักชี'],
            ],
        ])->assertRedirect();

        $item = \App\Models\OrderItem::first();

        $this->assertNotNull($item);
        $this->assertSame(OrderSource::SelfOrder, $item->source);
        $this->assertSame('pending', $item->approval_status);
        $this->assertSame('100.00', $item->order->subtotal);
    }

    public function test_customer_items_do_not_reach_the_kitchen_before_approval(): void
    {
        $session = app(TableSessionService::class)->resolve($this->table);

        $order = app(SelfOrderService::class)->submit($session, [
            ['product_id' => $this->noodle->id, 'qty' => 1],
        ]);

        $this->assertSame(0, KitchenTicket::count());

        // พนักงานกดส่งครัวตรง ๆ ก็ยังไม่ส่งรายการที่ยังไม่ถูกยืนยัน
        $sent = app(\App\Services\OrderService::class)->sendToKitchen($order->fresh());

        $this->assertSame(0, $sent);
        $this->assertSame(0, KitchenTicket::count());
    }

    public function test_approval_sends_items_to_the_kitchen(): void
    {
        $this->actingAs($this->staff);

        $session = app(TableSessionService::class)->resolve($this->table);

        $order = app(SelfOrderService::class)->submit($session, [
            ['product_id' => $this->noodle->id, 'qty' => 1],
            ['product_id' => $this->drink->id, 'qty' => 2],
        ]);

        $itemIds = $order->items()->pluck('id')->all();
        app(SelfOrderService::class)->approve($order, $itemIds, $this->staff->id);

        // อาหารกับเครื่องดื่มอยู่คนละจุดผลิต จึงต้องแตกเป็น 2 ใบ
        $this->assertSame(2, KitchenTicket::count());
        $this->assertEqualsCanonicalizing(
            [PrintGroup::Kitchen->value, PrintGroup::Bar->value],
            KitchenTicket::get()->map(fn ($t) => $t->print_group->value)->all(),
        );

        $this->assertSame('approved', $order->items()->first()->fresh()->approval_status);
        $this->assertSame('sent', $order->items()->first()->fresh()->status);
    }

    public function test_rejected_items_are_voided_and_removed_from_the_bill(): void
    {
        $this->actingAs($this->staff);

        $session = app(TableSessionService::class)->resolve($this->table);

        $order = app(SelfOrderService::class)->submit($session, [
            ['product_id' => $this->noodle->id, 'qty' => 2],
        ]);

        $this->assertSame('100.00', $order->fresh()->subtotal);

        app(SelfOrderService::class)->reject($order, $order->items()->pluck('id')->all(), 'ของหมด');

        $this->assertSame('0.00', $order->fresh()->subtotal);
        $this->assertSame('void', $order->items()->first()->fresh()->status);
    }

    public function test_bill_cannot_be_paid_while_items_await_approval(): void
    {
        $this->actingAs($this->staff);

        $session = app(TableSessionService::class)->resolve($this->table);

        $order = app(SelfOrderService::class)->submit($session, [
            ['product_id' => $this->noodle->id, 'qty' => 1],
        ]);

        $this->expectException(\DomainException::class);

        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'cash', 'amount' => 50, 'received' => 50],
        ]);
    }

    public function test_paying_closes_the_table_session_so_the_qr_stops_accepting_orders(): void
    {
        $this->actingAs($this->staff);

        $session = app(TableSessionService::class)->resolve($this->table);

        $order = app(SelfOrderService::class)->submit($session, [
            ['product_id' => $this->noodle->id, 'qty' => 1],
        ]);

        app(SelfOrderService::class)->approve($order, $order->items()->pluck('id')->all(), $this->staff->id);

        app(PaymentService::class)->pay($order->fresh(), [
            ['method' => 'cash', 'amount' => 50, 'received' => 50],
        ]);

        $this->assertSame('closed', $session->fresh()->status);
        $this->assertNull(app(TableSessionService::class)->validateToken($session->session_token));

        $this->expectException(\DomainException::class);
        app(SelfOrderService::class)->submit($session->fresh(), [
            ['product_id' => $this->noodle->id, 'qty' => 1],
        ]);
    }

    public function test_scanning_after_payment_opens_a_fresh_session(): void
    {
        $this->actingAs($this->staff);

        $first = app(TableSessionService::class)->resolve($this->table);
        app(TableSessionService::class)->close($first, 'paid');

        $second = app(TableSessionService::class)->resolve($this->table->fresh());

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('active', $second->status);
    }

    public function test_kitchen_ticket_moves_through_its_stages(): void
    {
        $this->actingAs($this->staff);

        $session = app(TableSessionService::class)->resolve($this->table);
        $order = app(SelfOrderService::class)->submit($session, [
            ['product_id' => $this->noodle->id, 'qty' => 1],
        ]);

        app(SelfOrderService::class)->approve($order, $order->items()->pluck('id')->all(), $this->staff->id);

        $ticket = KitchenTicket::first();
        $kitchen = app(KitchenService::class);

        $this->assertSame(KitchenTicketStatus::Queued, $ticket->status);

        $kitchen->advance($ticket);
        $this->assertSame(KitchenTicketStatus::Preparing, $ticket->fresh()->status);

        $kitchen->advance($ticket->fresh());
        $this->assertSame(KitchenTicketStatus::Ready, $ticket->fresh()->status);

        $kitchen->advance($ticket->fresh());
        $this->assertSame(KitchenTicketStatus::Served, $ticket->fresh()->status);

        // เสิร์ฟแล้วรายการในบิลต้องขยับตาม เพื่อให้หน้า POS เห็นตรงกับครัว
        $this->assertSame('served', $order->items()->first()->fresh()->status);
    }

    public function test_duplicate_service_calls_are_merged(): void
    {
        $session = app(TableSessionService::class)->resolve($this->table);
        $selfOrders = app(SelfOrderService::class);

        $first = $selfOrders->callStaff($session, ServiceCallType::Bill);
        $second = $selfOrders->callStaff($session, ServiceCallType::Bill);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\ServiceCall::count());
    }

    public function test_guest_cannot_order_a_product_from_another_branch(): void
    {
        $other = Branch::create([
            'code' => 'T2',
            'name' => 'อีกสาขา',
            'vat_rate' => 7,
            'vat_included' => true,
            'business_day_start' => '05:00:00',
        ]);

        $foreign = Product::create([
            'branch_id' => $other->id,
            'name' => 'เมนูสาขาอื่น',
            'price' => 999,
        ]);

        $session = app(TableSessionService::class)->resolve($this->table);

        $order = app(SelfOrderService::class)->submit($session, [
            ['product_id' => $foreign->id, 'qty' => 1],
            ['product_id' => $this->noodle->id, 'qty' => 1],
        ]);

        // เมนูข้ามสาขาถูกข้ามไปเงียบ ๆ เหลือเฉพาะของสาขาตัวเอง
        $this->assertSame(1, $order->items()->count());
        $this->assertSame($this->noodle->id, $order->items()->first()->product_id);
    }
}
