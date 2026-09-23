<?php

namespace Tests\Unit;

use App\Enums\ReconcileStatus;
use App\Services\ActivityLogger;
use App\Services\BankReconciliationService;
use App\Services\CashSettlementService;
use PHPUnit\Framework\TestCase;

/**
 * ยอดที่ควรเข้าบัญชีธนาคาร แยกตามช่องทางต่อวัน
 *
 * ทดสอบ channelsFor() ซึ่งเป็นฟังก์ชันล้วนและเป็นหัวใจของหน้ากระทบยอด
 * ถ้าตัวเลขตรงนี้ผิด ทุกวันจะขึ้นว่า "ไม่ตรง" ทั้งที่ไม่มีอะไรผิด
 * แล้วสุดท้ายคนใช้จะเลิกเชื่อหน้านี้ไปเลย ซึ่งแย่กว่าไม่มีหน้านี้
 */
class BankReconciliationTest extends TestCase
{
    protected function service(): BankReconciliationService
    {
        // ไม่ต้องมี Laravel — channelsFor() ไม่แตะฐานข้อมูลและไม่แตะ container
        $logger = new ActivityLogger;

        return new BankReconciliationService(new CashSettlementService($logger), $logger);
    }

    /** ตัวช่วยสร้างยอดรับเงินหนึ่งช่องทาง */
    protected function payment(float $gross, float $fee = 0, int $count = 1): array
    {
        return ['gross' => $gross, 'fee' => $fee, 'count' => $count];
    }

    public function test_deducts_fee_because_bank_transfers_the_net_amount(): void
    {
        // บัตรเครดิตถูกหักค่าธรรมเนียมก่อนเงินเข้าบัญชี
        // ถ้าเอายอดเต็มไปเทียบกับสเตทเมนต์ จะขึ้นว่าไม่ตรงทุกวัน
        $rows = $this->service()->channelsFor(
            ['credit_card' => $this->payment(1000.0, 22.5, 3)],
            [],
            null,
            [],
        );

        $this->assertCount(1, $rows);
        $this->assertSame(977.5, $rows[0]['expected']);
        $this->assertSame('บัตรเครดิต', $rows[0]['label']);
        $this->assertSame(ReconcileStatus::Pending->value, $rows[0]['status']);
    }

    public function test_deducts_refunds(): void
    {
        $rows = $this->service()->channelsFor(
            ['promptpay' => $this->payment(500.0)],
            ['promptpay' => 120.0],
            null,
            [],
        );

        $this->assertSame(380.0, $rows[0]['expected']);
        $this->assertSame(120.0, $rows[0]['refund']);
    }

    public function test_cash_uses_the_settlement_amount_not_the_cash_sales(): void
    {
        /*
        | ขายเงินสดได้ 2000 แต่ใบนำส่งคิดได้ 1850 เพราะมีเงินออกจากลิ้นชัก 150
        | ยอดที่ต้องเข้าบัญชีคือยอดที่พนักงานต้องโอน ไม่ใช่ยอดขายเงินสด
        | ถ้าคิดใหม่ที่นี่ เลขในหน้ากระทบยอดกับหน้านำส่งเงินสดจะไม่ตรงกัน
        */
        $rows = $this->service()->channelsFor(
            ['cash' => $this->payment(2000.0, 0, 10)],
            [],
            ['expected' => 1850.0, 'declared' => 1850.0, 'status' => 'submitted'],
            [],
        );

        $this->assertSame(1850.0, $rows[0]['expected']);
        $this->assertSame(2000.0, $rows[0]['gross'], 'ยอดขายเงินสดต้องยังเห็นอยู่เพื่อให้ไล่ที่มาได้');
        $this->assertSame(1850.0, $rows[0]['declared']);
        $this->assertSame('submitted', $rows[0]['settlement_status']);
    }

    public function test_diff_is_actual_minus_expected(): void
    {
        $rows = $this->service()->channelsFor(
            ['delivery_app' => $this->payment(3000.0, 900.0, 12)],
            [],
            null,
            ['delivery_app' => [
                'id' => 7,
                'actual' => 2050.0,
                'status' => ReconcileStatus::Mismatched->value,
                'reference' => 'GP-2609',
                'note' => 'แอปหัก GP เพิ่ม',
                'reconciled_at' => null,
                'reconciled_by' => 'มานี',
            ]],
        );

        $this->assertSame(2100.0, $rows[0]['expected']);
        $this->assertSame(-50.0, $rows[0]['diff']);
        $this->assertSame('มานี', $rows[0]['reconciled_by']);
        $this->assertSame(7, $rows[0]['id']);
    }

    public function test_diff_is_zero_while_nobody_has_reconciled_yet(): void
    {
        // ยังไม่มีใครกด = ยังไม่รู้ว่าต่างเท่าไหร่ ไม่ใช่ต่าง -2100
        $rows = $this->service()->channelsFor(
            ['credit_card' => $this->payment(2100.0)],
            [],
            null,
            [],
        );

        $this->assertNull($rows[0]['actual']);
        $this->assertSame(0.0, $rows[0]['diff']);
    }

    public function test_hides_channels_with_no_activity(): void
    {
        $rows = $this->service()->channelsFor(
            ['ewallet' => $this->payment(0.0, 0.0, 0)],
            [],
            null,
            [],
        );

        $this->assertSame([], $rows);
    }

    public function test_keeps_a_channel_somebody_already_reconciled_even_at_zero(): void
    {
        $rows = $this->service()->channelsFor([], [], null, [
            'transfer' => [
                'id' => 1,
                'actual' => 0.0,
                'status' => ReconcileStatus::Matched->value,
                'reference' => null,
                'note' => null,
                'reconciled_at' => null,
                'reconciled_by' => 'สมชาย',
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(0.0, $rows[0]['actual'], 'ต้องเป็น 0.0 ไม่ใช่ null — มีคนยืนยันแล้วว่าไม่มีเงินเข้า');
    }

    public function test_channel_order_puts_cash_first(): void
    {
        // เงินสดเป็นก้อนที่เสี่ยงที่สุด ต้องเห็นก่อนเสมอ
        $rows = $this->service()->channelsFor([
            'delivery_app' => $this->payment(100.0, 30.0),
            'cash' => $this->payment(200.0),
            'promptpay' => $this->payment(300.0),
        ], [], null, []);

        $this->assertSame(['cash', 'promptpay', 'delivery_app'], array_column($rows, 'channel'));
    }

    public function test_rounds_to_two_decimals(): void
    {
        $rows = $this->service()->channelsFor(
            ['credit_card' => $this->payment(100.005, 0.001)],
            [],
            null,
            [],
        );

        $this->assertSame(100.0, $rows[0]['expected']);
    }

    public function test_totals_across_days(): void
    {
        $service = $this->service();

        $days = [
            [
                'business_date' => '2026-09-20',
                'channels' => $service->channelsFor(
                    ['cash' => $this->payment(1000.0), 'credit_card' => $this->payment(500.0, 10.0)],
                    [],
                    ['expected' => 1000.0, 'declared' => null, 'status' => 'pending'],
                    [],
                ),
                'expected_total' => 1490.0,
                'actual_total' => 0.0,
                'diff_total' => 0.0,
                'open_count' => 2,
            ],
            [
                'business_date' => '2026-09-19',
                'channels' => $service->channelsFor(
                    ['credit_card' => $this->payment(200.0, 4.0)],
                    [],
                    null,
                    ['credit_card' => [
                        'id' => 2,
                        'actual' => 196.0,
                        'status' => ReconcileStatus::Matched->value,
                        'reference' => null,
                        'note' => null,
                        'reconciled_at' => null,
                        'reconciled_by' => null,
                    ]],
                ),
                'expected_total' => 196.0,
                'actual_total' => 196.0,
                'diff_total' => 0.0,
                'open_count' => 0,
            ],
        ];

        $byChannel = $service->channelTotals($days);

        $this->assertSame(['cash', 'credit_card'], array_column($byChannel, 'channel'));
        $this->assertSame(686.0, $byChannel[1]['expected'], 'บัตรสองวันรวมกัน 490 + 196');
        $this->assertSame(14.0, $byChannel[1]['fee']);
        $this->assertSame(1, $byChannel[1]['open_count'], 'นับเฉพาะวันที่ยังไม่ติ๊ก');

        $totals = $service->totals($days);

        $this->assertSame(2, $totals['day_count']);
        $this->assertSame(1686.0, $totals['expected']);
        $this->assertSame(196.0, $totals['actual']);
        $this->assertSame(2, $totals['open_count']);
    }
}
