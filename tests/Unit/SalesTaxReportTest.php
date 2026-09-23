<?php

namespace Tests\Unit;

use App\Services\SalesTaxReportService;
use PHPUnit\Framework\TestCase;

/**
 * เลขที่ใบกำกับภาษีที่ขาดหายไป
 *
 * เลขใบกำกับต้องเรียงต่อเนื่อง ถ้ามีเลขหาย ผู้ทำบัญชีต้องตอบให้ได้ว่าหายเพราะอะไร
 * รายงานนี้มีไว้ให้รู้ตั้งแต่ตอนปิดเดือน ไม่ใช่ตอนถูกตรวจ
 */
class SalesTaxReportTest extends TestCase
{
    protected SalesTaxReportService $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new SalesTaxReportService;
    }

    public function test_finds_the_gap_in_the_middle(): void
    {
        $this->assertSame(['0003'], $this->report->missingIn([1, 2, 4, 5]));
    }

    public function test_numbering_always_starts_at_one(): void
    {
        /*
        | ชุดที่เริ่มที่ 3 แปลว่า 1 กับ 2 หายไป ไม่ใช่ว่าวันนั้นเริ่มนับที่ 3
        | ตัวออกเลขรีเซ็ตเป็น 1 ทุกวันขายเสมอ ถ้าเลขแรกไม่ใช่ 1 แปลว่ามีบิลหายไปจริง
        */
        $this->assertSame(['0001', '0002'], $this->report->missingIn([3, 4]));
    }

    public function test_no_gap_when_continuous(): void
    {
        $this->assertSame([], $this->report->missingIn([1, 2, 3]));
    }

    public function test_empty_set_has_no_gap(): void
    {
        // วันที่ไม่มีบิลเลย ไม่ใช่วันที่เลขหาย
        $this->assertSame([], $this->report->missingIn([]));
    }

    public function test_ignores_duplicates_and_unordered_input(): void
    {
        $this->assertSame(['0002'], $this->report->missingIn([3, 1, 3, 1]));
    }

    public function test_ignores_numbers_below_one(): void
    {
        $this->assertSame([], $this->report->missingIn([0, -5, 1]));
    }

    public function test_pads_to_four_digits(): void
    {
        // ต้องตรงกับรูปแบบที่ตัวออกเลขใช้ ไม่งั้นเอาไปค้นในระบบไม่เจอ
        $this->assertSame(['0001', '0002'], $this->report->missingIn([3]));
    }

    /**
     * ตัวเลขลำดับท้ายเลขที่ใบเสร็จ
     *
     * เป็น protected เพราะไม่ใช่ API ที่ข้างนอกควรเรียก แต่เป็นตัวที่ตัดสินว่า
     * ใบไหนถูกนับเข้ารายงานบ้าง ผิดตรงนี้แล้วรายงานจะบอกว่าเลขหายทั้งที่ไม่หาย
     * จึงยอมใช้ reflection เพื่อให้มันมีเทสต์
     *
     * @dataProvider receiptNumbers
     */
    public function test_reads_the_sequence_from_a_receipt_number(?string $receiptNo, ?int $expected): void
    {
        $method = new \ReflectionMethod(SalesTaxReportService::class, 'sequenceOf');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($this->report, $receiptNo));
    }

    public static function receiptNumbers(): array
    {
        return [
            'รูปแบบปกติ' => ['R2609220012', 12],
            'เลขแรกของวัน' => ['R2609220001', 1],
            'ลำดับห้าหลัก' => ['R26092200123', 123],
            'ไม่มีเลขที่ใบเสร็จ' => [null, null],
            'รูปแบบของระบบเก่า' => ['INV-2026-0001', null],
            'ไม่มีตัวนำหน้า R' => ['2609220012', null],
            'ลำดับสั้นเกินไป' => ['R260922012', null],
            'ข้อความว่าง' => ['', null],
        ];
    }
}
