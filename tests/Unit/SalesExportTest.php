<?php

namespace Tests\Unit;

use App\Services\ActivityLogger;
use App\Services\SalesExportService;
use PHPUnit\Framework\TestCase;

/**
 * ยอดสรุปที่ส่งให้ระบบบัญชี (SAM)
 *
 * ── ตัวเลขสองชุดที่ไม่เท่ากันและไม่ควรเท่า ──────────────────
 * ยอดขาย       นับเฉพาะบิลที่ counts_as_sale
 * ช่องทางเงิน   นับเงินที่เคลื่อนจริงทุกบิล แล้วแยกยอดคืนไว้ต่างหาก
 * ถ้าใครมาแก้ให้สองชุดนี้เท่ากันเมื่อไหร่ ยอดที่ส่งให้บัญชีจะผิดทันที
 */
class SalesExportTest extends TestCase
{
    protected SalesExportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // summarise() เป็นฟังก์ชันล้วน ไม่แตะฐานข้อมูลและไม่แตะ container
        $this->service = new SalesExportService(new ActivityLogger);
    }

    /** ตัวช่วยสร้างบิลหนึ่งใบตามรูปแบบที่ billOf() สร้าง */
    protected function bill(array $over = []): array
    {
        return array_merge([
            'key' => 'HQ|B001',
            'status' => 'paid',
            'counts_as_sale' => true,
            'amounts' => [
                'subtotal' => 100.0,
                'discount_total' => 0.0,
                'service_charge' => 0.0,
                'delivery_fee' => 0.0,
                'net_amount' => 93.46,
                'tax_amount' => 6.54,
                'rounding' => 0.0,
                'grand_total' => 100.0,
            ],
            'payments' => [],
            'refunds' => [],
        ], $over);
    }

    public function test_cancelled_bills_stay_in_the_payload_but_not_in_the_sales_total(): void
    {
        /*
        | บิลที่ยกเลิกยังต้องถูกส่งไป เพราะเลขที่ใบกำกับที่ออกไปแล้วต้องอธิบายได้ทุกเลข
        | แต่ห้ามนับเป็นยอดขาย ปลายทางใช้ธง counts_as_sale ตัดสินเอง
        */
        $summary = $this->service->summarise([
            $this->bill(),
            $this->bill([
                'key' => 'HQ|B002',
                'status' => 'void',
                'counts_as_sale' => false,
                'amounts' => [
                    'subtotal' => 500.0, 'discount_total' => 0.0, 'service_charge' => 0.0,
                    'delivery_fee' => 0.0, 'net_amount' => 467.29, 'tax_amount' => 32.71,
                    'rounding' => 0.0, 'grand_total' => 500.0,
                ],
            ]),
        ]);

        $this->assertSame(1, $summary['bill_count']);
        $this->assertSame(1, $summary['void_count']);
        $this->assertSame(100.0, $summary['grand_total']);
        $this->assertSame(93.46, $summary['net_amount']);
    }

    public function test_payment_channels_count_money_that_actually_moved(): void
    {
        $summary = $this->service->summarise([
            $this->bill(['payments' => [
                ['method' => 'cash', 'amount' => 60.0, 'fee' => 0.0],
                ['method' => 'promptpay', 'amount' => 40.0, 'fee' => 0.0],
            ]]),
            $this->bill([
                'key' => 'HQ|B002',
                'status' => 'refunded',
                'counts_as_sale' => false,
                'payments' => [['method' => 'cash', 'amount' => 200.0, 'fee' => 0.0]],
                'refunds' => [['method' => 'cash', 'amount' => 200.0]],
            ]),
        ]);

        $byMethod = array_column($summary['by_payment_method'], null, 'method');

        $this->assertSame(260.0, $byMethod['cash']['amount'], 'รับเงินสดมาจริง 260 แม้ใบหนึ่งจะคืนไปแล้ว');
        $this->assertSame(200.0, $byMethod['cash']['refund']);
        $this->assertSame(60.0, $byMethod['cash']['net']);
        $this->assertSame(40.0, $byMethod['promptpay']['amount']);

        $this->assertSame(1, $summary['bill_count'], 'บิลที่คืนเงินไม่นับเป็นยอดขาย');
        $this->assertSame(1, $summary['refund_count']);
    }

    public function test_channel_fee_is_deducted_from_net(): void
    {
        $summary = $this->service->summarise([
            $this->bill(['payments' => [['method' => 'delivery_app', 'amount' => 1000.0, 'fee' => 300.0]]]),
        ]);

        $this->assertSame(700.0, $summary['by_payment_method'][0]['net']);
        $this->assertSame(300.0, $summary['by_payment_method'][0]['fee']);
    }

    public function test_channel_order_is_stable(): void
    {
        // ปลายทางอาจเทียบไฟล์สองวันทีละบรรทัด ลำดับต้องไม่สลับไปมาเอง
        $summary = $this->service->summarise([
            $this->bill(['payments' => [
                ['method' => 'promptpay', 'amount' => 10.0, 'fee' => 0.0],
                ['method' => 'cash', 'amount' => 10.0, 'fee' => 0.0],
            ]]),
        ]);

        $this->assertSame(['cash', 'promptpay'], array_column($summary['by_payment_method'], 'method'));
    }

    public function test_a_day_with_no_bills(): void
    {
        $summary = $this->service->summarise([]);

        $this->assertSame(0, $summary['bill_count']);
        $this->assertSame(0.0, $summary['grand_total'], 'ต้องเป็น 0.0 ไม่ใช่ null — ปลายทางคำนวณต่อได้ทันที');
        $this->assertSame([], $summary['by_payment_method']);
    }
}
