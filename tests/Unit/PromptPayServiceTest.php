<?php

namespace Tests\Unit;

use App\Services\PromptPayService;
use PHPUnit\Framework\TestCase;

class PromptPayServiceTest extends TestCase
{
    protected PromptPayService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PromptPayService;
    }

    /** ถอด TLV ของ EMVCo กลับมาเป็น array เพื่อตรวจทีละฟิลด์ */
    private function parse(string $payload): array
    {
        $out = [];
        $i = 0;

        while ($i < strlen($payload)) {
            $tag = substr($payload, $i, 2);
            $len = (int) substr($payload, $i + 2, 2);
            $out[$tag] = substr($payload, $i + 4, $len);
            $i += 4 + $len;
        }

        return $out;
    }

    public function test_mobile_number_is_converted_to_the_promptpay_format(): void
    {
        $fields = $this->parse($this->service->build('0899999999', 100));
        $merchant = $this->parse($fields['29']);

        $this->assertSame('A000000677010111', $merchant['00']);
        $this->assertSame('0066899999999', $merchant['01']);  // 08x -> 0066 8x
    }

    public function test_national_id_uses_its_own_tag(): void
    {
        $merchant = $this->parse($this->parse($this->service->build('1234567890123'))['29']);

        $this->assertSame('1234567890123', $merchant['02']);
    }

    public function test_amount_makes_it_a_one_time_qr(): void
    {
        $withAmount = $this->parse($this->service->build('0899999999', 250.5));

        $this->assertSame('12', $withAmount['01']);      // dynamic
        $this->assertSame('250.50', $withAmount['54']);  // ทศนิยม 2 ตำแหน่งเสมอ
        $this->assertSame('764', $withAmount['53']);     // THB
        $this->assertSame('TH', $withAmount['58']);

        $withoutAmount = $this->parse($this->service->build('0899999999'));

        $this->assertSame('11', $withoutAmount['01']);   // static
        $this->assertArrayNotHasKey('54', $withoutAmount);
    }

    public function test_checksum_matches_the_payload(): void
    {
        $payload = $this->service->build('0899999999', 100);

        $body = substr($payload, 0, -4);
        $checksum = substr($payload, -4);

        // คำนวณ CRC-16/CCITT-FALSE ซ้ำด้วยวิธีตรง ๆ แล้วต้องได้ค่าเดียวกัน
        $crc = 0xFFFF;

        for ($i = 0; $i < strlen($body); $i++) {
            $crc ^= ord($body[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        $this->assertSame(strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT)), $checksum);
    }

    public function test_thai_shop_name_is_stripped_to_ascii(): void
    {
        // ฟิลด์ชื่อร้านใน QR รองรับแค่ ASCII — ชื่อไทยล้วนต้องไม่ทำให้ payload พัง
        $fields = $this->parse($this->service->build('0899999999', 50, 'ร้านก๋วยเตี๋ยว Ladprao'));

        $this->assertSame('Ladprao', trim($fields['59']));
        $this->assertLessThanOrEqual(25, strlen($fields['59']));
    }

    public function test_invalid_promptpay_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->build('12345');
    }
}
