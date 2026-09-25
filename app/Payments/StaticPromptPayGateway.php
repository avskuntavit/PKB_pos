<?php

namespace App\Payments;

use App\Models\PaymentCharge;
use App\Models\PaymentProviderAccount;
use App\Services\PromptPayService;

/**
 * QR พร้อมเพย์ของร้านเอง — ไม่ผ่านเกตเวย์
 *
 * ── ทำไมต้องมี driver นี้ ทั้งที่มันตรวจยอดไม่ได้ ──────────────────────
 * 1. เป็นของที่ระบบทำได้อยู่แล้ววันนี้ ไม่ต้องรอสัญญากับเจ้าไหน
 * 2. **เป็นทางถอยเวลาเกตเวย์ล่ม** — ร้านต้องรับเงินได้ต่อแม้ผู้ให้บริการดับ
 *    ถ้าไม่มีทางถอย วันที่เกตเวย์ล่มคือวันที่ร้านรับโอนไม่ได้เลย
 * 3. เงินเข้าบัญชีร้านตรง ไม่มีค่าธรรมเนียม ร้านเล็กที่ยอมดูสลิปเองก็ใช้ตัวนี้ได้
 *
 * ── สิ่งที่ driver นี้ตั้งใจ "ไม่" ทำ ─────────────────────────────────
 * ไม่แกล้งตอบว่าจ่ายแล้ว ไม่เดาจากอะไรทั้งนั้น `pollCharge()` คืน pending เสมอ
 *
 * การเดาว่าเงินเข้าแล้วคือการปิดบิลที่ไม่มีเงินจริง ซึ่งแย่กว่าการยอมรับว่าตรวจไม่ได้
 * เพราะอย่างน้อยการยอมรับทำให้พนักงานรู้ว่าต้องไปขอดูสลิป
 */
class StaticPromptPayGateway implements PaymentGateway
{
    public function __construct(protected PromptPayService $promptPay) {}

    public function name(): string
    {
        return 'static';
    }

    /**
     * QR คงที่ของร้านไม่มีวันหมดอายุ
     *
     * ซึ่งเป็นเหตุผลหนึ่งที่ตัวนี้ตรวจยอดอัตโนมัติไม่ได้:
     * ไม่มีอะไรผูกการโอนเข้ากับบิลใบไหนเลย ลูกค้าสแกนพรุ่งนี้ก็เข้าบัญชีเดียวกัน
     */
    public function expiryWindow(): ExpiryWindow
    {
        return ExpiryWindow::never();
    }

    public function createCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): ChargeResult
    {
        $charge->loadMissing('branch');
        $branch = $charge->branch;

        if (! $branch) {
            throw new \RuntimeException('ไม่พบสาขาของรายการนี้');
        }

        $payload = $this->promptPay->forBranch($branch, (float) $charge->amount);

        if ($payload === null) {
            throw new \RuntimeException(
                'สาขานี้ยังไม่ได้ตั้งพร้อมเพย์ — ใส่เบอร์หรือเลขประจำตัวที่ตั้งค่าสาขาก่อน',
            );
        }

        /*
        | ไม่มี providerChargeId เพราะไม่มีเกตเวย์
        |
        | และไม่มี expiresAt ด้วย — QR คงที่ของร้านไม่หมดอายุ
        | ลูกค้าสแกนวันนี้หรือพรุ่งนี้ก็เข้าบัญชีเดียวกัน ซึ่งเป็นเหตุผลหนึ่ง
        | ที่ตัวนี้ตรวจยอดอัตโนมัติไม่ได้: ไม่มีอะไรผูกการโอนกับบิลใบไหนเลย
        */
        return new ChargeResult(
            providerChargeId: null,
            qrPayload: $payload,
            expiresAt: null,
            raw: [
                'note' => 'QR คงที่ของร้าน ไม่ผ่านเกตเวย์',
                'carries_amount' => PromptPayService::carriesAmount((float) $charge->amount),
            ],
        );
    }

    public function pollCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): ChargeStatusResult
    {
        return ChargeStatusResult::pending(
            'QR ของร้านตรวจยอดอัตโนมัติไม่ได้ — พนักงานต้องดูสลิปแล้วกดรับเงินเอง',
        );
    }

    public function cancelCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): void
    {
        // ไม่มีอะไรให้ยกเลิกฝั่งไหน — ฝั่งเราตั้งสถานะเองใน PaymentChargeService
    }
}
