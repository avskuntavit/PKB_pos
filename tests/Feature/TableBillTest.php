<?php

namespace Tests\Feature;

use App\Enums\KitchenTicketStatus;
use App\Enums\ServiceCallType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\ServiceCall;
use App\Models\User;
use App\Services\OrderService;
use App\Services\TableBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * บิลของโต๊ะ ในมุมของลูกค้าที่นั่งอยู่ (แถบล่างบนหน้า /order)
 *
 * ── ทำไมต้องมีชุดนี้ ────────────────────────────────────────────────
 * ตัวเลขชุดนี้อยู่บนมือถือลูกค้า และลูกค้าเอาไปบวกลบตามเองได้
 * ถ้าบรรทัดที่โชว์บวกไม่ได้ยอดสุทธิ เขาจะเรียกพนักงานมาถามว่าคิดเงินถูกหรือเปล่า
 * ซึ่งเป็นสิ่งที่หน้านี้ตั้งใจจะกำจัดตั้งแต่แรก
 *
 * ── ด่านสำคัญที่สุดของชุดนี้ ────────────────────────────────────────
 * `test_the_lines_on_the_customers_phone_add_up_to_the_total`
 * ส่วนลดมีห้าชนิด ถ้าลืมชนิดใดชนิดหนึ่งในบรรทัด "ส่วนลด" ยอดจะบวกไม่ลง
 * และไม่มี error ที่ไหนเลย — `staff_discount` เคยหายไปแบบนั้นจริง ๆ
 */
class TableBillTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected DiningTable $table;

    protected Product $noodle;      // 100 บาท

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        /*
        | ตั้งค่าบริการ 10% และราคารวม VAT แล้ว โดยตั้งใจ
        |
        | ร้านที่ราคารวม VAT มีสมการที่ลูกค้าตรวจเองได้:
        |   subtotal − ส่วนลด + ค่าบริการ = ยอดสุทธิ
        | (ภาษีอยู่ข้างในราคาแล้ว ไม่บวกเพิ่ม)
        | ถ้าตั้งค่าบริการเป็น 0 เทสต์จะผ่านแม้ payload ลืมบวกค่าบริการ
        */
        $this->branch = Branch::create([
            'code' => 'TB1',
            'name' => 'สาขาทดสอบ',
            'vat_rate' => 7,
            'vat_included' => true,
            'service_charge_rate' => 10,
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
            'name' => 'หมี่ขาว',
            'price' => 100,
            'cost' => 30,
        ]);

        $this->actingAs(User::create([
            'branch_id' => $this->branch->id,
            'name' => 'แคชเชียร์',
            'email' => 'tb@foodpos.test',
            'password' => 'password',
            'role' => 'cashier',
        ]));
    }

    /* ---------- ด่านเงิน: บรรทัดบนจอต้องบวกได้ยอดสุทธิ ---------- */

    public function test_the_lines_on_the_customers_phone_add_up_to_the_total(): void
    {
        $order = $this->billWith(2);   // subtotal 200

        // ส่วนลดครบทั้งห้าชนิดในบิลเดียว
        $order->activeItems()->firstOrFail()->update(['discount' => 10]);

        $this->promo('ลดท้ายบิล 20', rewardValue: 20);

        $order->forceFill([
            'bill_discount' => 30,
            'voucher_discount' => 5,
            'staff_discount' => 15,
        ])->save();

        app(OrderService::class)->recalculate($order);

        $payload = app(TableBillService::class)->payload($order->fresh());

        // พิสูจน์ฟิกซ์เจอร์ก่อน — ส่วนลดต้องครบห้าชนิดจริง ไม่ใช่ศูนย์ทั้งแถว
        $order->refresh();
        $this->assertSame('10.00', $order->item_discount);
        $this->assertSame('30.00', $order->bill_discount);
        $this->assertSame('20.00', $order->promotion_discount);
        $this->assertSame('5.00', $order->voucher_discount);
        $this->assertSame('15.00', $order->staff_discount);

        $this->assertSame(80.0, $payload['totals']['discount'], 'ส่วนลดที่โชว์ต้องรวมครบทั้งห้าชนิด');
        $this->assertReconciles($payload);
    }

    public function test_a_staff_discount_alone_does_not_break_the_total(): void
    {
        /*
        | แยกเทสต์ออกมาเพราะนี่คือชนิดที่หายไปจริง
        | ถ้ารวมอยู่ในเทสต์ข้างบนตัวเดียว พอมันตกจะอ่านไม่ออกว่าขาดชนิดไหน
        */
        $order = $this->billWith(2);
        $order->forceFill(['staff_discount' => 40])->save();

        app(OrderService::class)->recalculate($order);

        $payload = app(TableBillService::class)->payload($order->fresh());

        $this->assertSame(40.0, $payload['totals']['discount']);
        $this->assertReconciles($payload);
    }

    public function test_a_bill_with_no_discount_at_all_still_adds_up(): void
    {
        $payload = app(TableBillService::class)->payload($this->billWith(2));

        $this->assertSame(0.0, $payload['totals']['discount']);
        $this->assertSame(200.0, $payload['totals']['subtotal']);
        $this->assertSame(20.0, $payload['totals']['service_charge'], 'ค่าบริการ 10% ของ 200');
        $this->assertReconciles($payload);
    }

    /* ---------- แถบโผล่เมื่อไหร่ ---------- */

    public function test_a_table_with_no_bill_shows_nothing(): void
    {
        $this->assertNull(app(TableBillService::class)->forTable($this->table->fresh()));
    }

    public function test_a_table_just_opened_with_an_empty_bill_shows_nothing(): void
    {
        // พนักงานเพิ่งเปิดโต๊ะ ยังไม่มีใครสั่ง — ไม่ต้องเด้งแถบขึ้นมากวน
        app(OrderService::class)->open($this->branch, $this->table);

        $this->assertNull(app(TableBillService::class)->forTable($this->table->fresh()));
    }

    public function test_a_table_with_food_on_the_bill_shows_the_panel(): void
    {
        $this->billWith(1);

        $bill = app(TableBillService::class)->forTable($this->table->fresh());

        $this->assertNotNull($bill);
        $this->assertSame('A1', $bill['table']);
    }

    public function test_another_tables_bill_never_shows_here(): void
    {
        $other = DiningTable::create(['branch_id' => $this->branch->id, 'name' => 'A2', 'seats' => 2]);

        $this->billWith(1);   // ของโต๊ะ A1

        $this->assertNull(app(TableBillService::class)->forTable($other->fresh()));
    }

    /* ---------- นับจานและจัดรอบ ---------- */

    public function test_it_counts_dishes_not_lines(): void
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch, $this->table);
        $orders->addItem($order, $this->noodle, 3);

        // สั่งหมี่ 3 จานในบรรทัดเดียว = 3 จาน ไม่ใช่ 1
        $this->assertSame(3, app(TableBillService::class)->payload($order->refresh())['item_count']);
    }

    public function test_a_cancelled_dish_leaves_the_bill_entirely(): void
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch, $this->table);
        $orders->addItem($order, $this->noodle, 1);
        $keep = $orders->addItem($order, $this->noodle, 1);
        $orders->voidItem($order->refresh()->activeItems()->firstOrFail(), 'ลูกค้าเปลี่ยนใจ');

        $payload = app(TableBillService::class)->payload($order->fresh());

        $this->assertSame(1, $payload['item_count']);
        $this->assertSame([$keep->id], collect($payload['rounds'])->flatMap(fn ($r) => $r['items'])->pluck('id')->all());
    }

    public function test_dishes_are_grouped_by_the_round_they_were_ordered_in(): void
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch, $this->table);

        $first = $orders->addItem($order, $this->noodle, 1);
        $second = $orders->addItem($order, $this->noodle, 1);
        $second->update(['round' => 2]);

        $rounds = app(TableBillService::class)->payload($order->fresh())['rounds'];

        $this->assertCount(2, $rounds);
        $this->assertSame([1, 2], collect($rounds)->pluck('round')->all(), 'รอบแรกต้องอยู่บนสุด');
        $this->assertSame($first->id, $rounds[0]['items'][0]['id']);
        $this->assertNotNull($rounds[0]['placed_at']);
    }

    /* ---------- สถานะรายจานที่ลูกค้าเห็น ---------- */

    public function test_a_dish_waiting_for_staff_approval_says_so_before_anything_else(): void
    {
        /*
        | รายการที่ยังไม่ถูกยืนยัน ครัวยังไม่เห็นเลย
        | ถ้าไปโชว์ว่า "รอเข้าครัว" ลูกค้าจะนั่งรอของที่ไม่มีใครทำ
        */
        $order = $this->billWith(1);
        $item = $order->activeItems()->firstOrFail();
        $item->update(['approval_status' => 'pending', 'status' => 'sent']);

        $this->assertSame('waiting_approval', $this->firstItem($order)['status']);
    }

    public function test_a_dish_nobody_has_sent_to_the_kitchen_yet_says_waiting(): void
    {
        $order = $this->billWith(1);

        $this->assertSame('waiting_kitchen', $this->firstItem($order)['status']);
        $this->assertSame('รอเข้าครัว', $this->firstItem($order)['status_label']);
    }

    public function test_a_dish_the_kitchen_is_cooking_says_so(): void
    {
        $order = $this->billWith(1);
        app(OrderService::class)->sendToKitchen($order);

        $this->setTicketStatus($order, KitchenTicketStatus::Preparing);

        $this->assertSame('preparing', $this->firstItem($order)['status']);
    }

    public function test_a_dish_ready_to_be_carried_out_says_so(): void
    {
        $order = $this->billWith(1);
        app(OrderService::class)->sendToKitchen($order);

        $this->setTicketStatus($order, KitchenTicketStatus::Ready);

        $this->assertSame('ready', $this->firstItem($order)['status']);
    }

    public function test_a_dish_already_on_the_table_says_served(): void
    {
        $order = $this->billWith(1);
        $order->activeItems()->firstOrFail()->update(['status' => 'served']);

        $this->assertSame('served', $this->firstItem($order)['status']);
    }

    public function test_it_reports_how_many_dishes_are_waiting_for_approval(): void
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch, $this->table);
        $orders->addItem($order, $this->noodle, 1)->update(['approval_status' => 'pending']);
        $orders->addItem($order, $this->noodle, 1);

        $this->assertSame(1, app(TableBillService::class)->payload($order->fresh())['waiting_approval']);
    }

    /* ---------- เรียกพนักงานมาเก็บเงิน ---------- */

    public function test_asking_for_the_bill_raises_one_request_for_the_staff(): void
    {
        $order = $this->billWith(1);

        $call = app(TableBillService::class)->callForBill($order);

        $this->assertSame(ServiceCallType::Bill, $call->type);
        $this->assertSame($this->table->id, (int) $call->dining_table_id);
        $this->assertSame(1, ServiceCall::count());
        $this->assertTrue(app(TableBillService::class)->payload($order->fresh())['bill_called']);
    }

    public function test_tapping_it_repeatedly_does_not_pile_up_requests(): void
    {
        // กดรัวไม่ควรทำให้พนักงานเห็นรายการเดิมซ้ำสิบแถว
        $order = $this->billWith(1);
        $bills = app(TableBillService::class);

        $first = $bills->callForBill($order);
        $again = $bills->callForBill($order);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, ServiceCall::count());
    }

    public function test_a_request_that_the_staff_already_closed_can_be_raised_again(): void
    {
        $order = $this->billWith(1);
        $bills = app(TableBillService::class);

        $first = $bills->callForBill($order);
        $first->update(['status' => 'done']);

        // พนักงานปิดเรื่องแล้ว แถบต้องไม่ค้างว่ากำลังเรียกอยู่
        $this->assertFalse($bills->payload($order->fresh())['bill_called']);

        // และลูกค้าต้องเรียกใหม่ได้ ไม่ใช่ถูกล็อกด้วยคำขอที่จบไปแล้ว
        $this->assertNotSame($first->id, $bills->callForBill($order)->id);
        $this->assertSame(2, ServiceCall::count());
    }

    /* ---------- ตัวช่วย ---------- */

    /**
     * สมการที่ลูกค้าตรวจเองได้บนหน้าจอ
     *
     * ร้านที่ราคารวม VAT แล้ว: subtotal − ส่วนลด + ค่าบริการ = ยอดสุทธิ
     * ภาษีอยู่ข้างในราคาแล้วจึงไม่บวกเพิ่ม (ดู OrderService::recalculate)
     */
    protected function assertReconciles(array $payload): void
    {
        $t = $payload['totals'];

        $this->assertSame(
            round($t['subtotal'] - $t['discount'] + $t['service_charge'], 2),
            round($t['grand_total'], 2),
            'บรรทัดที่โชว์บนมือถือลูกค้าต้องบวกได้ยอดสุทธิ — ถ้าตกแปลว่าลืมส่วนลดชนิดใดชนิดหนึ่ง',
        );
    }

    protected function billWith(float $qty): Order
    {
        $orders = app(OrderService::class);
        $order = $orders->open($this->branch, $this->table);
        $orders->addItem($order, $this->noodle, $qty);

        return $order->refresh();
    }

    /** @return array<string, mixed> */
    protected function firstItem(Order $order): array
    {
        return app(TableBillService::class)->payload($order->fresh())['rounds'][0]['items'][0];
    }

    protected function setTicketStatus(Order $order, KitchenTicketStatus $status): void
    {
        $order->kitchenTickets()->firstOrFail()->update(['status' => $status]);
    }

    protected function promo(string $name, float $rewardValue): Promotion
    {
        return Promotion::create([
            'branch_id' => $this->branch->id,
            'name' => $name,
            'trigger_type' => 'none',
            'reward_type' => 'bill_amount',
            'reward_value' => $rewardValue,
        ]);
    }
}
