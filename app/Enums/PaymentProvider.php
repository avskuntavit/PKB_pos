<?php

namespace App\Enums;

/**
 * ผู้ให้บริการที่ออก QR รับเงินให้เรา
 *
 * ── ทำไมต้องเป็น enum + driver ตั้งแต่ตอนที่มีเจ้าเดียว ──────────────────
 * ร้านใช้ PaySolutions กับ Xendit อยู่ และวางแผนจะย้ายไป Beam หรือ Stripe
 * ถ้าเขียนการคุยกับเจ้าใดเจ้าหนึ่งปนลงไปในเส้นทางรับเงิน วันที่ย้ายเจ้าคือวันที่
 * ต้องรื้อเส้นทางเงินทั้งเส้น ซึ่งเป็นเส้นที่แก้ผิดแล้วเสียหายที่สุดในระบบ
 *
 * แยกไว้แบบนี้ การย้ายเจ้าคือเขียนคลาสใหม่คลาสเดียวกับสลับค่าใน .env
 * และช่วงย้ายสามารถเปิดสองเจ้าพร้อมกันได้ (สาขาละเจ้า) โดยไม่ต้องหยุดร้าน
 */
enum PaymentProvider: string
{
    /**
     * QR พร้อมเพย์คงที่ของร้านเอง — ไม่มีเกตเวย์
     *
     * เงินเข้าบัญชีร้านตรง ไม่มีค่าธรรมเนียมเกตเวย์ แต่ระบบไม่มีทางรู้ว่าเข้าหรือยัง
     * พนักงานต้องดูสลิป ตัวนี้คือของที่ระบบทำได้อยู่แล้ววันนี้ และเป็นทางถอย
     * เวลาเกตเวย์ล่ม — ร้านต้องรับเงินได้ต่อแม้ผู้ให้บริการจะดับ
     */
    case Static_ = 'static';

    case PaySolutions = 'paysolutions';

    case Xendit = 'xendit';

    case Beam = 'beam';

    case Stripe = 'stripe';

    public function label(): string
    {
        return match ($this) {
            self::Static_ => 'QR พร้อมเพย์ของร้าน (ไม่ผ่านเกตเวย์)',
            self::PaySolutions => 'PaySolutions',
            self::Xendit => 'Xendit',
            self::Beam => 'Beam',
            self::Stripe => 'Stripe',
        };
    }

    /** คุยกับระบบข้างนอกผ่าน API ไหม */
    public function isGateway(): bool
    {
        return $this !== self::Static_;
    }

    /**
     * ถามได้ไหมว่าจ่ายแล้วหรือยัง
     *
     * static ตอบไม่ได้ และต้อง **ไม่แกล้งตอบ** — การเดาว่าเงินเข้าแล้ว
     * คือการปิดบิลที่ไม่มีเงินจริง ซึ่งแย่กว่าการยอมรับว่าตรวจไม่ได้
     */
    public function supportsPolling(): bool
    {
        return $this->isGateway();
    }

    /** ต้องกรอกกุญแจก่อนใช้งานไหม */
    public function needsCredentials(): bool
    {
        return $this->isGateway();
    }

    /**
     * กุญแจที่เจ้านี้ต้องการ
     *
     * ── ทำไมอยู่ที่ enum ไม่ใช่ที่หน้าจอ ──────────────────────────────
     * หน้าจอต้องวาดช่องกรอกตามเจ้าที่เลือก และฝั่งเซิร์ฟเวอร์ต้องตรวจว่ากรอกครบ
     * ถ้าสองฝั่งถือรายการของตัวเอง วันหนึ่งจะไม่ตรงกัน แล้วร้านกรอกครบตามที่จอบอก
     * แต่ระบบยังบอกว่าไม่ครบ
     *
     * ── `confirmed` หมายถึงอะไร ──────────────────────────────────────
     * `false` = ชื่อช่องยังไม่ได้เทียบกับเอกสารของเจ้านั้นจริง เป็นการคาดไว้ก่อน
     * จะยืนยันตอนเขียน driver (ซึ่งเป็นตอนที่มีเอกสารกับ test key อยู่ในมือ)
     * หน้าจอขึ้นป้ายเตือนให้เห็นชัด ไม่ปล่อยให้ร้านกรอกไปแล้วมารู้ทีหลังว่าชื่อผิด
     *
     * @return array<int, array{key: string, label: string, hint: string, secret: bool, required: bool, confirmed: bool}>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            // ไม่มีเกตเวย์ ไม่มีกุญแจ
            self::Static_ => [],

            /*
            | Stripe — ยืนยันแล้ว ชื่อคีย์เป็นมาตรฐานของเขาและไม่เปลี่ยนมานาน
            | secret key ขึ้นต้น sk_test_ / sk_live_ · webhook secret ขึ้นต้น whsec_
            */
            self::Stripe => [
                self::field('secret_key', 'Secret key', 'ขึ้นต้นด้วย sk_test_ หรือ sk_live_', confirmed: true),
                self::field('publishable_key', 'Publishable key', 'ขึ้นต้นด้วย pk_ — ไม่ใช่ความลับ ใส่หรือไม่ใส่ก็ได้',
                    secret: false, required: false, confirmed: true),
                self::field('webhook_secret', 'Webhook signing secret', 'ขึ้นต้นด้วย whsec_ — ใส่เมื่อเปิดใช้ webhook',
                    required: false, confirmed: true),
            ],

            self::Beam => [
                self::field('merchant_id', 'Merchant ID', 'รหัสร้านที่ Beam ออกให้'),
                self::field('api_key', 'API key', 'กุญแจสำหรับเรียก API'),
                self::field('webhook_secret', 'Webhook secret', 'ใส่เมื่อเปิดใช้ webhook', required: false),
            ],

            self::Xendit => [
                self::field('secret_key', 'Secret API key', 'กุญแจฝั่งเซิร์ฟเวอร์ของ Xendit'),
                self::field('webhook_token', 'Callback verification token', 'ใช้ตรวจว่า callback มาจาก Xendit จริง',
                    required: false),
            ],

            self::PaySolutions => [
                self::field('merchant_id', 'Merchant ID', 'รหัสร้านที่ PaySolutions ออกให้'),
                self::field('api_key', 'API key', 'กุญแจสำหรับเรียก API'),
                self::field('secret_key', 'Secret key', 'ใช้ตรวจลายเซ็นของ callback', required: false),
            ],
        };
    }

    /** ชื่อช่องของเจ้านี้ถูกยืนยันกับเอกสารจริงแล้วครบไหม */
    public function fieldsConfirmed(): bool
    {
        $fields = $this->credentialFields();

        return $fields === [] || ! collect($fields)->contains(fn (array $f) => ! $f['confirmed']);
    }

    /** @return array<int, string> */
    public function requiredCredentialKeys(): array
    {
        return collect($this->credentialFields())
            ->filter(fn (array $f) => $f['required'])
            ->pluck('key')
            ->all();
    }

    /** @return array{key: string, label: string, hint: string, secret: bool, required: bool, confirmed: bool} */
    protected static function field(
        string $key,
        string $label,
        string $hint,
        bool $secret = true,
        bool $required = true,
        bool $confirmed = false,
    ): array {
        return compact('key', 'label', 'hint', 'secret', 'required', 'confirmed');
    }

    /** @return array<int, array{value: string, label: string, is_gateway: bool, fields: array<int, array<string, mixed>>, fields_confirmed: bool}> */
    public static function options(): array
    {
        return array_map(fn (self $p) => [
            'value' => $p->value,
            'label' => $p->label(),
            'is_gateway' => $p->isGateway(),
            'fields' => $p->credentialFields(),
            'fields_confirmed' => $p->fieldsConfirmed(),
        ], self::cases());
    }
}
