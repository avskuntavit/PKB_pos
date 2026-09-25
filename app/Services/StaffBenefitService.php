<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StaffBenefitUsage;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * สวัสดิการส่วนลดพนักงานองค์กร
 *
 * กติกา:
 *   - ลดเป็น "ราคาพนักงาน" รายเมนู (products.staff_price) ไม่ใช่ลดเปอร์เซ็นต์ทั้งบิล
 *     เมนูที่ไม่ได้ตั้งราคาพนักงานไว้ = ไม่มีสิทธิ์ลด
 *   - เครื่องดื่มแอลกอฮอล์ไม่เข้าเงื่อนไข (ตั้งปิดได้ที่สาขา)
 *   - มีเพดานวงเงินต่อคนต่อเดือน ถ้าเกินจะลดให้เท่าที่เหลือ แล้วส่วนที่เกินลูกค้าจ่ายเอง
 *
 * มูลค่าส่วนลดถูกบันทึกแยกไว้ที่ staff_benefit_usages
 * เพื่อให้บัญชีดึงไปตั้งเป็นค่าใช้จ่ายสวัสดิการของบริษัทได้
 */
class StaffBenefitService
{
    /**
     * คำนวณส่วนลดที่บิลนี้จะได้ โดยยังไม่บันทึกอะไร
     *
     * @return array{
     *     eligible: bool,
     *     discount: float,
     *     capped: bool,
     *     cap: float,
     *     used_this_month: float,
     *     remaining: float,
     *     period: string,
     *     lines: array<int, array{name: string, qty: float, normal: float, staff: float, saved: float}>,
     *     excluded: array<int, string>,
     *     reason: string|null
     * }
     */
    public function preview(Order $order, ?Customer $customer = null): array
    {
        $customer ??= $order->customer;
        $branch = $order->branch;

        $empty = [
            'eligible' => false,
            'discount' => 0.0,
            'capped' => false,
            'cap' => 0.0,
            'used_this_month' => 0.0,
            'remaining' => 0.0,
            'period' => $branch->currentPeriod(),
            'lines' => [],
            'excluded' => [],
            'reason' => null,
        ];

        if (! $branch->staff_benefit_enabled) {
            return array_merge($empty, ['reason' => 'สาขานี้ปิดสิทธิ์สวัสดิการพนักงานไว้']);
        }

        if (! $customer?->isVerifiedEmployee()) {
            return array_merge($empty, ['reason' => 'ยังไม่ได้รับการยืนยันสิทธิ์พนักงานองค์กร']);
        }

        /*
        | ต้องเป็น load ไม่ใช่ loadMissing — เคยเป็น loadMissing แล้วสวัสดิการไม่เคยทำงานเลย
        |
        | `OrderService::recalculate()` โหลด relation นี้ไว้ก่อนหน้าด้วย
        | `activeItems.product:id,category_id` คือดึงมาแค่สองคอลัมน์เพื่อความเร็ว
        | แล้ว `place()` ก็เรียก recalculate() ทันทีก่อนจะมาเรียกสวัสดิการ
        |
        | loadMissing เห็นว่า relation "โหลดแล้ว" จึงไม่ทำอะไรเลย
        | เราจึงได้ Product ที่ไม่มี staff_price กับ is_alcohol ติดมาด้วย
        | `staffPrice()` อ่านคอลัมน์ที่ไม่ได้ถูกดึงมา ได้ null → ข้ามทุกบรรทัด → ส่วนลด 0 เสมอ
        | และมันเงียบสนิท เพราะ Eloquent คืน null ให้แอตทริบิวต์ที่ไม่ได้โหลด ไม่ได้โยน error
        |
        | บทเรียน: ชั้นที่ต้องการคอลัมน์เฉพาะ ต้องโหลดเองให้ครบ
        | ห้ามฝากความหวังไว้กับว่าใครโหลดอะไรมาให้ก่อนหน้า
        */
        $order->load('activeItems.product');

        $lines = [];
        $excluded = [];
        $raw = 0.0;

        foreach ($order->activeItems as $item) {
            $product = $item->product;

            if (! $product) {
                continue;
            }

            if ($branch->staff_benefit_exclude_alcohol && $product->is_alcohol) {
                $excluded[] = $product->name;

                continue;
            }

            $staffPrice = $product->staffPrice();

            if ($staffPrice === null) {
                continue;
            }

            $qty = (float) $item->qty;
            $normal = (float) $item->unit_price;
            $saved = Money::round(max(0, $normal - $staffPrice) * $qty);

            if ($saved <= 0) {
                continue;
            }

            $raw += $saved;

            $lines[] = [
                'order_item_id' => $item->id,
                'name' => $item->product_name,
                'qty' => $qty,
                'normal' => $normal,
                'staff' => $staffPrice,
                'saved' => $saved,
            ];
        }

        $raw = Money::round($raw);
        $cap = (float) $branch->staff_benefit_monthly_cap;

        /*
        | งวดของ "บิลใบนี้" ไม่ใช่เดือนตามนาฬิกา
        |
        | record() บันทึกด้วย $order->business_date->format('Y-m')
        | ถ้าตรงนี้อ่านด้วย now() ทั้งสองจะไม่ตรงกันในช่วงหลังเที่ยงคืนถึงเวลาตัดรอบ
        | ของวันที่ 1 — ยอดที่ใช้ไปแล้วจะถูกมองข้าม แล้วเพดานเปิดให้ใช้ใหม่ทั้งก้อน
        |
        | ต้องเป็นงวดของบิล ไม่ใช่งวดของสาขาตอนนี้ เพราะบิลที่เปิดค้างข้ามคืน
        | หรือบิลย้อนหลังต้องถูกคิดในงวดที่มันเกิด
        */
        $period = $order->business_date?->format('Y-m') ?? $branch->currentPeriod();
        $used = $customer->benefitUsedIn($period);

        // เพดาน 0 = ไม่จำกัด
        $remaining = $cap > 0 ? max(0, Money::round($cap - $used)) : $raw;
        $discount = $cap > 0 ? min($raw, $remaining) : $raw;

        return [
            'eligible' => $discount > 0,
            'discount' => Money::round($discount),
            'capped' => $cap > 0 && $discount < $raw,
            'cap' => $cap,
            'used_this_month' => $used,
            'remaining' => $cap > 0 ? Money::round($remaining - $discount) : 0.0,
            'period' => $period,
            'lines' => $lines,
            'excluded' => $excluded,
            'reason' => $raw > 0 && $discount <= 0 ? 'ใช้วงเงินสวัสดิการของเดือนนี้ครบแล้ว' : null,
        ];
    }

    /**
     * ใส่ส่วนลดลงบิลจริง — เรียกตอนผูกสมาชิกเข้ากับบิล หรือก่อนรับเงิน
     * เรียกซ้ำได้ ระบบจะคำนวณใหม่ทั้งหมดทุกครั้ง ไม่ลดซ้อน
     */
    public function apply(Order $order, OrderService $orders, ?Customer $customer = null): array
    {
        $preview = $this->preview($order, $customer);

        $order->update(['staff_discount' => $preview['discount']]);
        $orders->recalculate($order);

        return $preview;
    }

    /** บันทึกการใช้สิทธิ์ตอนปิดบิล — ตัวเลขนี้คือสิ่งที่บริษัทต้องตั้งเบิก */
    public function record(Order $order): ?StaffBenefitUsage
    {
        $discount = (float) $order->staff_discount;

        if ($discount <= 0 || ! $order->customer_id) {
            return null;
        }

        return DB::transaction(function () use ($order, $discount) {
            // บิลหนึ่งบันทึกครั้งเดียว กันนับซ้ำเวลามีการแก้ไขบิลย้อนหลัง
            return StaffBenefitUsage::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'branch_id' => $order->branch_id,
                    'customer_id' => $order->customer_id,
                    'employee_code' => $order->customer?->employee_code,
                    'period' => $order->business_date->format('Y-m'),
                    'discount_amount' => Money::round($discount),
                    'order_total' => (float) $order->grand_total,
                    'business_date' => $order->business_date->toDateString(),
                ]
            );
        });
    }

    /** ยกเลิกการบันทึกเมื่อบิลถูกทำลายหรือคืนเงินเต็มจำนวน */
    public function revoke(Order $order): void
    {
        StaffBenefitUsage::where('order_id', $order->id)->delete();
    }

    /** สรุปสิทธิ์คงเหลือของพนักงานคนหนึ่ง สำหรับแสดงบนหน้าบัญชีลูกค้า */
    public function balanceFor(Customer $customer, Branch $branch): array
    {
        $cap = (float) $branch->staff_benefit_monthly_cap;

        // งวดของวันขายปัจจุบัน — ตัวเลขบนหน้าบัญชีลูกค้าต้องตรงกับที่แคชเชียร์เห็น
        $period = $branch->currentPeriod();
        $used = $customer->benefitUsedIn($period);

        return [
            'enabled' => (bool) $branch->staff_benefit_enabled,
            'cap' => $cap,
            'used' => $used,
            'remaining' => $cap > 0 ? max(0, Money::round($cap - $used)) : null,
            'unlimited' => $cap <= 0,
            'period' => $period,
            'excludes_alcohol' => (bool) $branch->staff_benefit_exclude_alcohol,
        ];
    }
}
