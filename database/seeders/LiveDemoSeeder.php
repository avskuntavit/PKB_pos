<?php

namespace Database\Seeders;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentIntent;
use App\Enums\TableStatus;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServiceCall;
use App\Models\TableSession;
use App\Models\User;
use App\Services\KitchenService;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * สร้างสถานการณ์ "กำลังขายอยู่ตอนนี้" ให้ลองกดเล่นได้ทันทีหลัง seed
 *
 * ต่อสาขาจะได้:
 *   - 1 โต๊ะ มีบิลเปิด + ใบสั่งครัวค้างอยู่บนหน้าจอครัว
 *   - 1 โต๊ะ ลูกค้าสแกนสั่งเอง มีรายการรอพนักงานยืนยัน
 *   - 1 โต๊ะ กดเรียกเก็บเงินไว้
 */
class LiveDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Branch::all() as $branch) {
            $cashier = User::where('branch_id', $branch->id)->where('role', 'cashier')->first()
                ?? User::where('branch_id', $branch->id)->first();

            $tables = DiningTable::where('branch_id', $branch->id)->orderBy('name')->take(3)->get();

            if ($tables->count() < 3) {
                continue;
            }

            $this->staffOrderWithKitchenTicket($branch, $tables[0], $cashier);
            $this->selfOrderWaitingApproval($branch, $tables[1]);
            $this->tableCallingForBill($branch, $tables[2], $cashier);
            $this->onlinePreOrders($branch);
        }
    }

    /** โต๊ะที่พนักงานสั่งให้และส่งครัวไปแล้ว — ใบจะค้างอยู่บนหน้าจอครัว */
    protected function staffOrderWithKitchenTicket(Branch $branch, DiningTable $table, ?User $cashier): void
    {
        $order = $this->makeOpenOrder($branch, $table, $cashier, OrderSource::Pos, guests: 3);

        $products = Product::forCatalog($branch->id)->inRandomOrder()->take(3)->get();

        foreach ($products as $product) {
            $this->makeItem($order, $product, random_int(1, 2), [
                'status' => 'sent',
                'sent_at' => now()->subMinutes(random_int(4, 18)),
                'source' => OrderSource::Pos,
                'created_by' => $cashier?->id,
            ]);
        }

        $this->refreshTotals($order);

        // ดึงกลับมาเป็น Eloquent collection เพื่อให้ eager load ได้
        $items = $order->items()->with('product', 'modifiers')->where('status', 'sent')->get();

        app(KitchenService::class)->createTickets($order, $items, OrderSource::Pos);
    }

    /** โต๊ะที่ลูกค้าสแกน QR สั่งเอง — รายการค้างรอพนักงานกดยืนยันบนหน้า POS */
    protected function selfOrderWaitingApproval(Branch $branch, DiningTable $table): void
    {
        $order = $this->makeOpenOrder($branch, $table, null, OrderSource::SelfOrder, guests: 2);

        $session = TableSession::create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'order_id' => $order->id,
            'guest_count' => 2,
            'status' => 'active',
            'order_count' => 1,
            'started_at' => now()->subMinutes(12),
            'last_activity_at' => now()->subMinutes(2),
        ]);

        $products = Product::forCatalog($branch->id)->inRandomOrder()->take(2)->get();

        foreach ($products as $product) {
            $this->makeItem($order, $product, random_int(1, 2), [
                'status' => 'pending',
                'source' => OrderSource::SelfOrder,
                'approval_status' => 'pending',
                'table_session_id' => $session->id,
                'note' => 'ไม่ใส่ผักชี',
            ]);
        }

        $this->refreshTotals($order);
    }

    /** โต๊ะที่กินเสร็จแล้วกดเรียกเก็บเงิน */
    protected function tableCallingForBill(Branch $branch, DiningTable $table, ?User $cashier): void
    {
        $order = $this->makeOpenOrder($branch, $table, $cashier, OrderSource::SelfOrder, guests: 4);

        $session = TableSession::create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'order_id' => $order->id,
            'guest_count' => 4,
            'status' => 'active',
            'order_count' => 2,
            'started_at' => now()->subMinutes(45),
            'last_activity_at' => now()->subMinutes(3),
        ]);

        foreach (Product::forCatalog($branch->id)->inRandomOrder()->take(4)->get() as $product) {
            $this->makeItem($order, $product, 1, [
                'status' => 'served',
                'sent_at' => now()->subMinutes(35),
                'source' => OrderSource::SelfOrder,
                'approval_status' => 'approved',
                'table_session_id' => $session->id,
            ]);
        }

        $this->refreshTotals($order);

        ServiceCall::create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'order_id' => $order->id,
            'table_session_id' => $session->id,
            'type' => 'bill',
            'status' => 'open',
            'business_date' => $branch->businessDateFor()->toDateString(),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
    }

    /** ออเดอร์ล่วงหน้าจากหน้าร้านออนไลน์ — ใบหนึ่งรอร้านกดรับ อีกใบร้านรับแล้วกำลังทำ */
    protected function onlinePreOrders(Branch $branch): void
    {
        $products = Product::forCatalog($branch->id)->inRandomOrder()->take(3)->get();

        if ($products->isEmpty()) {
            return;
        }

        $scenarios = [
            [
                'name' => 'คุณพลอย',
                'phone' => '0811111111',
                'status' => FulfilmentStatus::Placed,
                'intent' => PaymentIntent::PromptPay,
                'pickup' => now()->addMinutes(25),
                'type' => OrderType::Takeaway,
            ],
            [
                'name' => 'คุณต้น',
                'phone' => '0822222222',
                'status' => FulfilmentStatus::Preparing,
                'intent' => PaymentIntent::KhonLaKhrueng,
                'pickup' => now()->addMinutes(8),
                'type' => OrderType::Takeaway,
            ],
        ];

        foreach ($scenarios as $i => $scenario) {
            $businessDate = $branch->businessDateFor();
            $accepted = $scenario['status'] !== FulfilmentStatus::Placed;

            $order = Order::create([
                'uuid' => (string) Str::uuid(),
                'branch_id' => $branch->id,
                'order_no' => 'B'.$businessDate->format('ymd').'8'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'business_date' => $businessDate->toDateString(),
                'type' => $scenario['type'],
                'status' => OrderStatus::Open,
                'source' => OrderSource::Online,
                'guest_count' => 1,
                'opened_at' => now()->subMinutes(15),
                'contact_name' => $scenario['name'],
                'contact_phone' => $scenario['phone'],
                'pickup_at' => $scenario['pickup'],
                'payment_intent' => $scenario['intent'],
                'track_token' => Str::random(40),
                'fulfilment_status' => $scenario['status'],
                'accepted_at' => $accepted ? now()->subMinutes(10) : null,
            ]);

            foreach ($products->take(2) as $product) {
                $this->makeItem($order, $product, 1, [
                    'status' => $accepted ? 'sent' : 'pending',
                    'sent_at' => $accepted ? now()->subMinutes(9) : null,
                    'source' => OrderSource::Online,
                    'approval_status' => $accepted ? 'approved' : 'pending',
                ]);
            }

            $this->refreshTotals($order);

            $order->statusEvents()->create(['status' => FulfilmentStatus::Placed]);

            if ($accepted) {
                $order->statusEvents()->create(['status' => FulfilmentStatus::Accepted]);
                $order->statusEvents()->create(['status' => $scenario['status']]);

                $items = $order->items()->with('product', 'modifiers')->where('status', 'sent')->get();
                app(KitchenService::class)->createTickets($order, $items, OrderSource::Online);
            }
        }
    }

    /* ---------- ตัวช่วย ---------- */

    protected function makeOpenOrder(
        Branch $branch,
        DiningTable $table,
        ?User $cashier,
        OrderSource $source,
        int $guests,
    ): Order {
        $businessDate = $branch->businessDateFor();

        $table->update(['status' => TableStatus::Occupied]);

        return Order::create([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'order_no' => 'B'.$businessDate->format('ymd').'9'.str_pad((string) $table->id, 3, '0', STR_PAD_LEFT),
            'business_date' => $businessDate->toDateString(),
            'type' => OrderType::DineIn,
            'status' => OrderStatus::Open,
            'source' => $source,
            'guest_count' => $guests,
            'opened_by' => $cashier?->id,
            'opened_at' => now()->subMinutes(random_int(10, 50)),
        ]);
    }

    protected function makeItem(Order $order, Product $product, int $qty, array $attributes)
    {
        return $order->items()->create(array_merge([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'category_name' => $product->category?->name,
            'unit_price' => $product->price,
            'unit_cost' => $product->cost,
            'qty' => $qty,
            'line_total' => Money::round((float) $product->price * $qty),
        ], $attributes));
    }

    protected function refreshTotals(Order $order): void
    {
        $order->load('activeItems');

        $subtotal = (float) $order->activeItems->sum('line_total');
        $cost = (float) $order->activeItems->sum(fn ($i) => (float) $i->unit_cost * (float) $i->qty);

        $order->update([
            'subtotal' => $subtotal,
            'grand_total' => $subtotal,
            'tax_amount' => Money::extractVat($subtotal, (float) $order->branch->vat_rate),
            'cost_total' => Money::round($cost),
        ]);
    }
}
