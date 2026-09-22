<?php

namespace App\Services;

use App\Models\Branch;

/**
 * สร้าง payload สำหรับ QR พร้อมเพย์ ตามมาตรฐาน EMVCo QR Code (Merchant-Presented)
 *
 * ผลลัพธ์เป็นสตริง แล้วไปวาดเป็นภาพ QR ที่ฝั่งเบราว์เซอร์
 * ไม่ต้องเรียก API ใคร ไม่ต้องส่งข้อมูลร้านออกนอกระบบ
 *
 * โครงสร้างเป็น TLV ซ้อนกัน: แต่ละฟิลด์ = tag 2 หลัก + ความยาว 2 หลัก + ค่า
 * ปิดท้ายด้วย CRC-16/CCITT-FALSE ของทั้งสตริง (รวม "6304" ด้วย)
 *
 * หมายเหตุ: QR นี้เป็นการโอนพร้อมเพย์ตรงเข้าบัญชีร้าน
 * ระบบไม่รู้ว่าเงินเข้าแล้วหรือยัง — พนักงานต้องดูสลิป/แอปธนาคารยืนยันเอง
 */
class PromptPayService
{
    private const ID_PAYLOAD_FORMAT = '00';
    private const ID_POI_METHOD = '01';
    private const ID_MERCHANT_INFO = '29';
    private const ID_COUNTRY = '58';
    private const ID_CURRENCY = '53';
    private const ID_AMOUNT = '54';
    private const ID_MERCHANT_NAME = '59';
    private const ID_MERCHANT_CITY = '60';
    private const ID_CRC = '63';

    private const GUID_PROMPTPAY = 'A000000677010111';
    private const CURRENCY_THB = '764';
    private const COUNTRY_TH = 'TH';

    /** QR ที่ระบุยอดแล้ว ใช้ได้ครั้งเดียว */
    private const POI_DYNAMIC = '12';

    /** QR ที่ไม่ระบุยอด ลูกค้ากรอกเอง */
    private const POI_STATIC = '11';

    public function forBranch(Branch $branch, ?float $amount = null): ?string
    {
        if (blank($branch->promptpay_id)) {
            return null;
        }

        return $this->build($branch->promptpay_id, $amount, $branch->promptpay_name);
    }

    public function build(string $promptPayId, ?float $amount = null, ?string $merchantName = null): string
    {
        $target = $this->normalizeId($promptPayId);

        if ($target === null) {
            throw new \InvalidArgumentException('รูปแบบพร้อมเพย์ไม่ถูกต้อง ต้องเป็นเบอร์มือถือ 10 หลัก หรือเลขประจำตัว 13 หลัก');
        }

        [$accountTag, $accountValue] = $target;

        $payload = $this->tlv(self::ID_PAYLOAD_FORMAT, '01')
            .$this->tlv(self::ID_POI_METHOD, $amount !== null ? self::POI_DYNAMIC : self::POI_STATIC)
            .$this->tlv(self::ID_MERCHANT_INFO,
                $this->tlv('00', self::GUID_PROMPTPAY).$this->tlv($accountTag, $accountValue)
            )
            .$this->tlv(self::ID_CURRENCY, self::CURRENCY_THB);

        if ($amount !== null) {
            $payload .= $this->tlv(self::ID_AMOUNT, number_format($amount, 2, '.', ''));
        }

        $payload .= $this->tlv(self::ID_COUNTRY, self::COUNTRY_TH);

        if (filled($merchantName)) {
            $payload .= $this->tlv(self::ID_MERCHANT_NAME, $this->sanitizeName($merchantName));
        }

        $payload .= $this->tlv(self::ID_MERCHANT_CITY, 'Bangkok');

        // CRC คิดจากสตริงที่มี "6304" ต่อท้ายแล้ว
        $payload .= self::ID_CRC.'04';

        return $payload.$this->crc16($payload);
    }

    /**
     * แปลงเลขพร้อมเพย์เป็นรูปแบบที่ QR ต้องการ
     *
     * เบอร์มือถือ 0812345678 -> 0066812345678 (tag 01)
     * เลขบัตรประชาชน/เลขผู้เสียภาษี 13 หลัก -> ใส่ตรง ๆ (tag 02)
     * เลข e-Wallet 15 หลัก -> tag 03
     */
    private function normalizeId(string $id): ?array
    {
        $digits = preg_replace('/\D/', '', $id) ?? '';

        return match (strlen($digits)) {
            10 => ['01', '00'.'66'.substr($digits, 1)],
            13 => ['02', $digits],
            15 => ['03', $digits],
            default => null,
        };
    }

    /** ชื่อร้านใน QR รับเฉพาะ ASCII และยาวไม่เกิน 25 ตัว */
    private function sanitizeName(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $name) ?? '';
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '');

        return substr($ascii !== '' ? $ascii : 'MERCHANT', 0, 25);
    }

    private function tlv(string $tag, string $value): string
    {
        return $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /** CRC-16/CCITT-FALSE: polynomial 0x1021, ค่าเริ่มต้น 0xFFFF */
    private function crc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000)
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
