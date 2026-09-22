<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * สร้างยอดขายย้อนหลัง 30 วัน ให้หน้ารายงานมีข้อมูลจริงให้ดู
 */
class DemoSalesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Branch::all() as $branch) {
            $products = Product::forCatalog($branch->id)->get();
            $tables = DiningTable::where('branch_id', $branch->id)->get();
            $staff = User::where('branch_id', $branch->id)->get();
            $customers = $this->makeCustomers($branch);

            for ($dayOffset = 29; $dayOffset >= 0; $dayOffset--) {
                $date = Carbon::today()->subDays($dayOffset);
                $this->seedDay($branch, $date, $products, $tables, $staff, $customers);
            }
        }
    }

    protected function makeCustomers(Branch $branch)
    {
        $names = ['คุณกันต์', 'คุณแนน', 'คุณโอ๊ต', 'คุณฟ้า', 'คุณเบนซ์'];

        return collect($names)->map(fn ($name, $i) => Customer::create([
            'branch_id' => $branch->id,
            'name' => $name,
            'phone' => '08'.str_pad((string) (10000000 + $i + $branch->id * 7), 8, '0', STR_PAD_LEFT),
            'tier' => $i < 2 ? 'gold' : 'regular',
        ]));
    }

    protected function seedDay(Branch $branch, Carbon $date, $products, $tables, $staff, $customers): void
    {
        $cashier = $staff->random();

        $shift = Shift::create([
            'branch_id' => $branch->id,
            'shift_no' => 'S'.$date->format('ymd').'-01',
            'business_date' => $date->toDateString(),
            'opened_by' => $cashier->id,
            'opened_at' => $date->copy()->setTime(9, 0),
            'opening_cash' => 1000,
            'status' => 'closed',
            'closed_by' => $cashier->id,
            'closed_at' => $date->copy()->setTime(21, 0),
        ]);

        // วันหยุดคนเยอะกว่าวันธรรมดา
        $isWeekend = in_array($date->dayOfWeek, [0, 6], true);
        $billCount = random_int($isWeekend ? 22 : 12, $isWeekend ? 38 : 26);
        $cashTotal = 0.0;

        for ($i = 0; $i < $billCount; $i++) {
            $hour = $this->randomBusyHour();
            $openedAt = $date->copy()->setTime($hour, random_int(0, 59));
            $type = $this->randomType();

            $order = Order::create([
                'uuid' => (string) Str::uuid(),
                'branch_id' => $branch->id,
                'shift_id' => $shift->id,
                'dining_table_id' => $type === OrderType::DineIn ? $tables->random()->id : null,
                'customer_id' => random_int(1, 5) === 1 ? $customers->random()->id : null,
                'order_no' => 'B'.$date->format('ymd').str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'receipt_no' => 'R'.$date->format('ymd').str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'business_date' => $date->toDateString(),
                'type' => $type,
                'status' => OrderStatus::Paid,
                'guest_count' => $type === OrderType::DineIn ? random_int(1, 4) : 1,
                'opened_by' => $cashier->id,
                'closed_by' => $cashier->id,
                'opened_at' => $openedAt,
                'closed_at' => $openedAt->copy()->addMinutes(random_int(8, 65)),
            ]);

            $subtotal = 0.0;
            $cost = 0.0;

            foreach ($products->random(random_int(1, 5)) as $product) {
                $qty = random_int(1, 3);
                $lineTotal = Money::round((float) $product->price * $qty);
                $subtotal += $lineTotal;
                $cost += (float) $product->cost * $qty;

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'category_name' => $product->category?->name,
                    'unit_price' => $product->price,
                    'unit_cost' => $product->cost,
                    'qty' => $qty,
                    'line_total' => $lineTotal,
                    'status' => 'served',
                    'created_by' => $cashier->id,
                    'sent_at' => $openedAt,
                ]);
            }

            $tax = Money::extractVat($subtotal, (float) $branch->vat_rate);

            $order->update([
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'grand_total' => $subtotal,
                'paid_amount' => $subtotal,
                'cost_total' => Money::round($cost),
            ]);

            // เงินสดราว ๆ 1 ใน 3 ของบิล ที่เหลือโอน/พร้อมเพย์
            $method = random_int(1, 3) === 1 ? PaymentMethod::Cash : PaymentMethod::PromptPay;
            $received = $method->isCash() ? ceil($subtotal / 100) * 100 : $subtotal;

            $order->payments()->create([
                'shift_id' => $shift->id,
                'method' => $method,
                'amount' => $subtotal,
                'received' => $received,
                'change' => Money::round($received - $subtotal),
                'paid_at' => $order->closed_at,
                'created_by' => $cashier->id,
            ]);

            if ($method->isCash()) {
                $cashTotal += $subtotal;
            }

            foreach (['order.open', 'order.item_sent', 'order.pay'] as $action) {
                ActivityLog::create([
                    'branch_id' => $branch->id,
                    'user_id' => $cashier->id,
                    'action' => $action,
                    'subject_type' => Order::class,
                    'subject_id' => $order->id,
                    'business_date' => $date->toDateString(),
                    'created_at' => $openedAt,
                    'updated_at' => $openedAt,
                ]);
            }
        }

        $expected = Money::round(1000 + $cashTotal);

        $shift->update([
            'expected_cash' => $expected,
            'counted_cash' => $expected,
            'cash_diff' => 0,
        ]);
    }

    /** สุ่มชั่วโมงแบบให้กระจุกช่วงมื้อเที่ยงและมื้อเย็น */
    protected function randomBusyHour(): int
    {
        $weights = [
            9 => 2, 10 => 3, 11 => 8, 12 => 14, 13 => 10, 14 => 5,
            15 => 3, 16 => 3, 17 => 6, 18 => 12, 19 => 11, 20 => 6, 21 => 3,
        ];

        $pool = [];

        foreach ($weights as $hour => $weight) {
            $pool = array_merge($pool, array_fill(0, $weight, $hour));
        }

        return $pool[array_rand($pool)];
    }

    protected function randomType(): OrderType
    {
        return match (random_int(1, 10)) {
            1, 2 => OrderType::Takeaway,
            3 => OrderType::Delivery,
            default => OrderType::DineIn,
        };
    }
}
