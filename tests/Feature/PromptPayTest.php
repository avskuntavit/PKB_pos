<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Services\PromptPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QR พร้อมเพย์ที่ลูกค้าสแกนโอนเงินจริง
 *
 * ── ทำไมต้องมีเทสต์ชุดนี้ ────────────────────────────────────────────────
 * นี่คือจุดที่เงินของลูกค้าออกจากมือ ถ้า payload ผิดไปตัวเดียว แอปธนาคารจะปฏิเสธ
 * หรือแย่กว่านั้นคือโอนไปผิดบัญชี และร้านจะรู้ตัวก็ต่อเมื่อมีลูกค้ามาบ่น
 * เดิมทั้งไฟล์ `PromptPayService` ไม่มีเทสต์แตะเลยสักตัว
 *
 * ── วิธีตรวจ CRC ─────────────────────────────────────────────────────────
 * เทสต์นี้คิด CRC ขึ้นมาเองอีกชุดหนึ่ง (ดู crc16() ท้ายไฟล์) แทนที่จะเรียกของในเซอร์วิส
 * ถ้าเรียกตัวเดียวกันมาเทียบกันเอง เทสต์จะผ่านเสมอต่อให้สูตรผิดทั้งคู่
 */
class PromptPayTest extends TestCase
{
    use RefreshDatabase;

    protected PromptPayService $pp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pp = app(PromptPayService::class);
    }

    /* ---------- โครงสร้าง payload ---------- */

    public function test_the_checksum_is_correct(): void
    {
        foreach ([null, 100.0, 25.5, 1234.05] as $amount) {
            $payload = $this->pp->build('0812345678', $amount);

            $this->assertSame(
                $this->crc16(substr($payload, 0, -4)),
                substr($payload, -4),
                'CRC ต้องตรง ไม่งั้นแอปธนาคารปฏิเสธ QR ทั้งใบ',
            );
        }
    }

    public function test_every_field_declares_its_real_length(): void
    {
        $fields = $this->parse($this->pp->build('0812345678', 100, 'Pakabao POS'));

        // parse() จะโยนเองถ้าความยาวที่ประกาศไม่ตรงกับค่าจริง
        $this->assertNotEmpty($fields);
    }

    public function test_fields_come_in_ascending_tag_order(): void
    {
        // EMVCo อ่านเรียงลำดับ แอปธนาคารบางตัวเข้มเรื่องนี้
        $tags = array_keys($this->parse($this->pp->build('0812345678', 100, 'Shop')));
        $sorted = $tags;
        sort($sorted);

        $this->assertSame($sorted, $tags);
    }

    /* ---------- เลขพร้อมเพย์แต่ละแบบ ---------- */

    public function test_a_mobile_number_becomes_the_international_form(): void
    {
        $account = $this->account($this->pp->build('0812345678', 100));

        $this->assertSame(['01' => '0066812345678'], $account, '081… ต้องกลายเป็น 0066…');
    }

    public function test_punctuation_in_the_mobile_number_is_ignored(): void
    {
        $this->assertSame(
            $this->pp->build('0812345678', 100),
            $this->pp->build('081-234-5678', 100),
            'เจ้าของร้านพิมพ์ขีดคั่นได้ ต้องได้ QR ใบเดียวกัน',
        );
    }

    public function test_a_thirteen_digit_id_is_used_as_is(): void
    {
        $this->assertSame(['02' => '1234567890123'], $this->account($this->pp->build('1234567890123', 100)));
    }

    public function test_a_fifteen_digit_ewallet_id_uses_its_own_tag(): void
    {
        $this->assertSame(['03' => '123456789012345'], $this->account($this->pp->build('123456789012345', 100)));
    }

    public function test_an_id_that_is_not_a_known_length_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // 11 หลัก — สิ่งที่ได้เมื่อเจ้าของร้านพิมพ์ +66812345678
        $this->pp->build('+66812345678', 100);
    }

    /* ---------- ยอดเงิน ---------- */

    public function test_a_qr_with_an_amount_is_marked_single_use(): void
    {
        $fields = $this->parse($this->pp->build('0812345678', 100));

        $this->assertSame('12', $fields['01'], 'ระบุยอดแล้วต้องเป็น QR ใช้ครั้งเดียว');
        $this->assertSame('100.00', $fields['54']);
        $this->assertSame('764', $fields['53'], 'สกุลเงินต้องเป็นบาท');
        $this->assertSame('TH', $fields['58']);
    }

    public function test_a_qr_without_an_amount_has_no_amount_field_at_all(): void
    {
        $fields = $this->parse($this->pp->build('0812345678'));

        $this->assertSame('11', $fields['01']);
        $this->assertArrayNotHasKey('54', $fields, 'ไม่ระบุยอด = ต้องไม่มีฟิลด์ยอดเลย ไม่ใช่ใส่ 0');
    }

    public function test_the_amount_always_has_two_decimals(): void
    {
        $this->assertSame('25.50', $this->parse($this->pp->build('0812345678', 25.5))['54']);
        $this->assertSame('1234.05', $this->parse($this->pp->build('0812345678', 1234.05))['54']);
    }

    /* ---------- ชื่อร้าน ---------- */

    public function test_a_thai_shop_name_falls_back_instead_of_breaking_the_qr(): void
    {
        // QR รับเฉพาะ ASCII ชื่อไทยล้วนจึงเหลือว่าง ต้องมีค่าสำรอง ไม่ใช่ฟิลด์ว่าง
        $this->assertSame('MERCHANT', $this->parse($this->pp->build('0812345678', 100, 'ร้านปากเบา'))['59']);
    }

    public function test_a_long_shop_name_is_cut_to_the_limit(): void
    {
        $name = $this->parse($this->pp->build('0812345678', 100, str_repeat('A', 40)))['59'];

        $this->assertSame(25, strlen($name));
    }

    public function test_no_shop_name_means_no_name_field(): void
    {
        $this->assertArrayNotHasKey('59', $this->parse($this->pp->build('0812345678', 100)));
    }

    /* ---------- ระดับสาขา — จุดที่ลูกค้าเห็นจริง ---------- */

    public function test_a_branch_without_promptpay_has_no_qr(): void
    {
        $this->assertNull($this->pp->forBranch($this->makeBranch(null)));
    }

    /**
     * เลขที่ตั้งไว้ผิดรูปแบบต้องไม่ทำให้หน้าลูกค้าพัง
     *
     * หน้าติดตามออเดอร์เรียกเมธอดนี้ตรง ๆ ถ้าโยน exception ลูกค้าจะเจอหน้า error
     * ทั้งหน้า ทั้งที่แค่จะดูว่าอาหารถึงไหนแล้ว
     */
    public function test_a_badly_configured_branch_hides_the_qr_instead_of_crashing(): void
    {
        $this->assertNull($this->pp->forBranch($this->makeBranch('+66812345678'), 100));
    }

    /**
     * ยอดต่ำกว่าขั้นต่ำต้องไม่ถูกฝังลงใน QR
     *
     * ผู้ให้บริการบางเจ้าไม่ออก QR ที่ระบุยอดต่ำ ๆ ให้ ลูกค้าจะสแกนแล้วขึ้น error
     * ยอด 0 (คูปองกินหมดทั้งบิล) ก็อยู่ในกลุ่มเดียวกัน
     * คืน QR แบบให้กรอกยอดเองแทน — สแกนได้ โอนได้ แค่พิมพ์เลขเอง
     */
    public function test_an_amount_below_the_minimum_is_left_out_of_the_qr(): void
    {
        $branch = $this->makeBranch('0812345678');

        foreach ([0.0, 1.0, 9.99] as $small) {
            $payload = $this->pp->forBranch($branch, $small);

            $this->assertNotNull($payload, 'ยอดน้อยต้องยังได้ QR ไม่ใช่ไม่มีอะไรให้สแกน');

            $fields = $this->parse($payload);

            $this->assertArrayNotHasKey('54', $fields, "ยอด {$small} ต้องไม่ถูกฝังลงใน QR");
            $this->assertSame('11', $fields['01']);
        }
    }

    public function test_the_minimum_amount_itself_is_allowed(): void
    {
        $fields = $this->parse($this->pp->forBranch($this->makeBranch('0812345678'), PromptPayService::MIN_AMOUNT));

        $this->assertSame('10.00', $fields['54'], 'เท่ากับขั้นต่ำพอดีต้องใส่ได้');
        $this->assertSame('12', $fields['01']);
    }

    /**
     * หน้าจอกับเซิร์ฟเวอร์ต้องตัดสินด้วยกฎเดียวกัน
     *
     * หน้าติดตามออเดอร์ใช้ค่านี้บอกลูกค้าว่า "ต้องพิมพ์ยอดเอง" ถ้าสองที่เขียนเงื่อนไข
     * แยกกัน วันหนึ่งจะบอกว่ายอดอยู่ใน QR ทั้งที่ไม่ได้อยู่ แล้วร้านได้เงินไม่ครบ
     */
    public function test_the_screen_and_the_server_agree_on_which_amounts_fit(): void
    {
        $this->assertFalse(PromptPayService::carriesAmount(null));
        $this->assertFalse(PromptPayService::carriesAmount(0.0));
        $this->assertFalse(PromptPayService::carriesAmount(9.99));
        $this->assertTrue(PromptPayService::carriesAmount(10.0));
        $this->assertTrue(PromptPayService::carriesAmount(1500.0));

        // ค่าที่ประกาศไว้ต้องตรงกับที่ QR ทำจริง
        $branch = $this->makeBranch('0812345678');

        foreach ([9.99, 10.0, 50.0] as $amount) {
            $fields = $this->parse($this->pp->forBranch($branch, $amount));

            $this->assertSame(
                PromptPayService::carriesAmount($amount),
                array_key_exists('54', $fields),
                "carriesAmount({$amount}) ต้องตรงกับสิ่งที่อยู่ใน QR จริง",
            );
        }
    }

    public function test_a_branch_qr_carries_the_branch_name(): void
    {
        $fields = $this->parse($this->pp->forBranch($this->makeBranch('0812345678', 'Pakabao'), 50));

        $this->assertSame('Pakabao', $fields['59']);
        $this->assertSame('50.00', $fields['54']);
    }

    /* ---------- ด่านตอนตั้งค่า ---------- */

    public function test_the_settings_page_refuses_an_id_that_cannot_make_a_qr(): void
    {
        $this->assertTrue(PromptPayService::isValidId('0812345678'));
        $this->assertTrue(PromptPayService::isValidId('081-234-5678'));
        $this->assertTrue(PromptPayService::isValidId('1234567890123'));
        $this->assertTrue(PromptPayService::isValidId('123456789012345'));

        $this->assertFalse(PromptPayService::isValidId('+66812345678'), '11 หลัก');
        $this->assertFalse(PromptPayService::isValidId('08123456'), 'สั้นเกิน');
        $this->assertFalse(PromptPayService::isValidId('ไม่ใช่เลข'));
        $this->assertFalse(PromptPayService::isValidId(''));
        $this->assertFalse(PromptPayService::isValidId(null));
    }

    /* ---------- ตัวช่วย ---------- */

    /** นับไว้ให้รหัสสาขาไม่ซ้ำ — `code` เป็น unique เรียกซ้ำในเทสต์เดียวกันจะชนกันเอง */
    protected int $branchSeq = 0;

    protected function makeBranch(?string $promptPayId, ?string $promptPayName = null): Branch
    {
        return Branch::create([
            'code' => 'PP'.(++$this->branchSeq),
            'name' => 'สาขาพร้อมเพย์',
            'vat_rate' => 7,
            'vat_included' => true,
            'business_day_start' => '05:00:00',
            'promptpay_id' => $promptPayId,
            'promptpay_name' => $promptPayName,
        ]);
    }

    /**
     * ถอด payload เป็น tag => value (ตัด "6304"+CRC ท้ายออก)
     *
     * โยนเองถ้าความยาวที่ประกาศไม่ตรงกับค่าจริง เพราะนั่นคือ QR ที่อ่านไม่ออก
     *
     * @return array<string, string>
     */
    protected function parse(string $payload): array
    {
        $body = substr($payload, 0, -8);
        $out = [];
        $i = 0;

        while ($i < strlen($body)) {
            $tag = substr($body, $i, 2);
            $len = (int) substr($body, $i + 2, 2);
            $value = substr($body, $i + 4, $len);

            $this->assertSame($len, strlen($value), "ฟิลด์ {$tag} ประกาศความยาว {$len} แต่ค่าจริงไม่เท่านั้น");

            $out[$tag] = $value;
            $i += 4 + $len;
        }

        return $out;
    }

    /** บัญชีปลายทางที่อยู่ในฟิลด์ 29 (ตัด GUID ออก) — @return array<string, string> */
    protected function account(string $payload): array
    {
        $merchant = $this->parse($payload)['29'];
        $out = [];
        $i = 0;

        while ($i < strlen($merchant)) {
            $tag = substr($merchant, $i, 2);
            $len = (int) substr($merchant, $i + 2, 2);

            if ($tag !== '00') {
                $out[$tag] = substr($merchant, $i + 4, $len);
            }

            $i += 4 + $len;
        }

        return $out;
    }

    /** CRC-16/CCITT-FALSE เขียนขึ้นใหม่ในเทสต์ ไม่ได้เรียกของในเซอร์วิส */
    protected function crc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
