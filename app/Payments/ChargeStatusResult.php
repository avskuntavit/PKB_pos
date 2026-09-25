<?php

namespace App\Payments;

use App\Enums\ChargeStatus;
use Illuminate\Support\Carbon;

/**
 * คำตอบของเกตเวย์เมื่อถามว่า "รายการนี้จ่ายแล้วหรือยัง"
 *
 * ── สถานะที่ driver ตอบได้มีแค่สี่ตัว ──────────────────────────────────
 * pending · paid · expired · failed
 *
 * `mismatch` กับ `unmatched` เป็นข้อสรุปของ **ฝั่งเรา** หลังเอาไปเทียบกับบิล
 * ไม่ใช่สิ่งที่เกตเวย์รู้ — เขาไม่รู้ว่าบิลของเราเท่าไหร่หรือถูกปิดไปแล้วหรือยัง
 * ถ้าปล่อยให้ driver ตอบสองตัวนี้ได้ กฎการเทียบยอดจะกระจายไปอยู่ในทุก driver
 */
final readonly class ChargeStatusResult
{
    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public ChargeStatus $status,
        public float $paidAmount = 0.0,
        public ?Carbon $paidAt = null,
        public ?string $message = null,
        public array $raw = [],
    ) {
        if ($status->needsDecision()) {
            throw new \InvalidArgumentException(
                'driver ตอบสถานะ '.$status->value.' ไม่ได้ — เป็นข้อสรุปของฝั่งเราหลังเทียบกับบิล',
            );
        }
    }

    public static function pending(?string $message = null, array $raw = []): self
    {
        return new self(ChargeStatus::Pending, message: $message, raw: $raw);
    }
}
