<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_extracts_vat_from_price_that_already_includes_it(): void
    {
        // 107 บาท รวม VAT 7% แล้ว -> ภาษีในนั้นคือ 7 บาท
        $this->assertSame(7.0, Money::extractVat(107, 7));
    }

    public function test_adds_vat_on_top_of_price(): void
    {
        $this->assertSame(7.0, Money::addVat(100, 7));
    }

    public function test_rounding_modes(): void
    {
        $this->assertSame(101.0, Money::applyRounding(100.25, 1)); // ปัดขึ้น
        $this->assertSame(100.0, Money::applyRounding(100.75, 2)); // ปัดลง
        $this->assertSame(101.0, Money::applyRounding(100.75, 3)); // ปัดใกล้สุด
        $this->assertSame(100.75, Money::applyRounding(100.75, 0)); // ไม่ปัด
    }

    public function test_percent(): void
    {
        $this->assertSame(12.5, Money::percent(250, 5));
    }
}
