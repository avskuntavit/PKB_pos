<?php

namespace App\Services;

use App\Enums\ChargeStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentCharge;
use App\Models\PaymentProviderAccount;
use App\Models\User;
use App\Payments\ChargeStatusResult;
use App\Payments\PaymentGateway;
use App\Payments\PollSchedule;
use App\Payments\StaticPromptPayGateway;
use App\Support\Money;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ออก QR รับเงิน และตัดสินว่าเงินที่เข้ามาลงบิลได้หรือไม่
 *
 * ── ที่เดียวที่ตัดสินเรื่องเงิน ──────────────────────────────────────────
 * driver ของแต่ละเจ้ามีหน้าที่คุยกับเจ้านั้นเท่านั้น
 * กฎว่า "เงินเท่าไหร่ถือว่าลงบิลได้" อยู่ที่นี่ที่เดียว
 * ไม่งั้นร้านจะได้พฤติกรรมต่างกันตามเจ้าที่ใช้อยู่ แล้วไม่มีใครตอบได้ว่าทำไม
 *
 * ── สามสิ่งที่ระบบนี้ต้องไม่ทำพลาด ──────────────────────────────────────
 * 1. ลงบิลซ้ำ — ไล่ถามซ้ำหรือสองเครื่องถามพร้อมกัน ต้องลงบิลครั้งเดียว
 * 2. ลงบิลด้วยยอดที่ไม่ตรง — เงินเข้า 90 บิล 100 ห้ามปิดบิลแล้วทิ้งส่วนต่าง
 * 3. ทำเงินที่เข้ามาแล้วหาย — บิลถูกปิดด้วยเงินสดไปก่อน แล้วเงินโอนเข้าทีหลัง
 *    เงินก้อนนั้นต้องไปโผล่ที่ไหนที่หนึ่งให้คนตัดสิน ไม่ใช่หายไปเงียบ ๆ
 *
 * ── ปิดบิลเองหรือรอพนักงาน ─────────────────────────────────────────────
 *   สั่งออนไลน์ (จ่ายก่อนมารับ)  ปิดเอง — ไม่มีพนักงานยืนอยู่กับลูกค้าตอนจ่าย
 *   ที่เคาน์เตอร์ / สั่งที่โต๊ะ    รอพนักงานกด — บิลอื่นของโต๊ะข้างกันอาจถูกปิดผิดใบ
 * ตัวที่รอพนักงานจะขึ้นสถานะ "จ่ายแล้ว รอปิดบิล" ให้เห็นบนหน้า POS
 */
class PaymentChargeService
{
    /** ยอมคลาดกันได้เท่าการปัดเศษของทั้งสองฝั่ง */
    public const TOLERANCE = 0.01;

    /**
     * ล็อกของบิลหนึ่งใบมีอายุเท่าไหร่
     *
     * ยาวพอให้ครอบการยิง HTTP ไปหาเกตเวย์ที่ช้าที่สุดที่ยังพอรับได้
     * แต่ต้องมีวันหมดอายุเสมอ — ถ้า process ตายกลางทางโดยไม่คืนล็อก
     * บิลนั้นจะออก QR ไม่ได้อีกเลยจนกว่าจะมีคนไปล้าง cache
     */
    protected const OPEN_LOCK_SECONDS = 20;

    /** รอล็อกนานสุดเท่าไหร่ก่อนยอมแพ้ — สั้นกว่าอายุล็อกเพื่อให้ได้คำตอบก่อนหมดอายุ */
    protected const OPEN_WAIT_SECONDS = 10;

    public function __construct(protected ActivityLogger $logger) {}

    /* ---------- ออก QR ---------- */

    /**
     * ออก QR ให้บิลใบนี้ — กดซ้ำได้ ไม่เกิดใบใหม่ถ้ายอดยังเท่าเดิม
     *
     * ── ทำไมต้องใช้ใบเดิมถ้ายอดเท่าเดิม ────────────────────────────
     * พนักงานกดโชว์ QR สองครั้งเป็นเรื่องปกติ (ลูกค้าขอดูใหม่ จอดับ กดพลาด)
     * ถ้าออกใบใหม่ทุกครั้ง เกตเวย์จะมีรายการค้างเป็นสิบใบต่อบิล
     * แล้วตอนกระทบยอดปลายเดือนไม่มีใครรู้ว่าใบไหนคือใบจริง
     *
     * ── ทำไมยอดเปลี่ยนต้องออกใบใหม่ ───────────────────────────────
     * ลูกค้าสั่งเพิ่มหลังออก QR แล้ว ถ้าปล่อยให้สแกนใบเดิมจะเก็บเงินขาด
     * และการแก้ยอดของ QR ที่ออกไปแล้วเป็นสิ่งที่เกตเวย์ส่วนใหญ่ไม่ให้ทำ
     */
    public function open(Order $order, ?User $staff = null): PaymentCharge
    {
        /*
        | หนึ่งบิลออก QR ได้ทีละคำขอเท่านั้น
        |
        | ── ทำไมแค่ reusable() ไม่พอ ──────────────────────────────────
        | สองเครื่องกดโชว์ QR ของบิลเดียวกันพร้อมกัน ทั้งคู่จะเห็นว่า "ยังไม่มีใบไหน
        | ใช้ได้" แล้วต่างคนต่างออกใบใหม่ — ลูกค้าได้ QR สองใบของบิลเดียว
        | สแกนใบไหนก็ได้ แต่ฝั่งเรามีสองรายการค้างอยู่ที่เกตเวย์
        | นี่คือบั๊กคลาสเดียวกับ select-แล้ว-insert ที่แก้ไปแล้วในคิวออฟไลน์
        |
        | ── ทำไมไม่ใช้ lockForUpdate ──────────────────────────────────
        | เพราะระหว่างนั้นต้องยิง HTTP ไปหาเกตเวย์ซึ่งกินเวลาเป็นวินาที
        | การถือล็อกแถวในฐานข้อมูลคร่อมการเรียก API คือวิธีทำให้ทั้งร้านค้าง
        |
        | ── ชนล็อกแล้วทำอะไร ─────────────────────────────────────────
        | รอสั้น ๆ แล้วอ่านใหม่ อีกคำขอจะสร้างใบให้เสร็จแล้ว จึงคืนใบเดียวกันไป
        | ซึ่งตรงกับสิ่งที่พนักงานคาดหวังอยู่แล้ว: กดสองที ได้ QR ใบเดิม
        */
        $lock = Cache::lock('payment-charge:open:'.$order->id, self::OPEN_LOCK_SECONDS);

        /*
        | block() แบบ **ไม่ส่ง callback** — ได้ล็อกแล้วคืน true และปล่อยให้เราถือต่อ
        | ถ้าส่ง callback เข้าไป Laravel จะรัน callback แล้ว **คืนล็อกทันที**
        | โค้ดที่เหลือจะทำงานนอกล็อก คือได้ไฟล์ที่ดูเหมือนกันเลยแต่ไม่กันอะไรเลย
        */
        try {
            $lock->block(self::OPEN_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // อีกคำขอค้างนานผิดปกติ — บอกพนักงานตรง ๆ ดีกว่าออกใบที่สองเงียบ ๆ
            throw new \DomainException('กำลังออก QR ของบิลนี้อยู่ กรุณารอสักครู่แล้วลองใหม่');
        }

        try {
            return $this->openExclusively($order, $staff);
        } finally {
            $lock->release();
        }
    }

    /** ตัวจริงของ open() — ถูกเรียกใต้ล็อกของบิลนั้นเสมอ */
    protected function openExclusively(Order $order, ?User $staff = null): PaymentCharge
    {
        if (! $order->isOpen()) {
            throw new \DomainException('บิลนี้ปิดไปแล้ว ออก QR ใหม่ไม่ได้');
        }

        $amount = Money::round((float) $order->grand_total);

        if ($amount <= 0) {
            throw new \DomainException('บิลนี้ยังไม่มียอดให้เก็บเงิน');
        }

        $order->loadMissing('branch');
        $branch = $order->branch;

        if ($existing = $this->reusable($order, $amount)) {
            return $existing;
        }

        // ยอดเปลี่ยนไปแล้ว — ใบเก่าต้องถูกปิดก่อน ไม่ปล่อยให้ค้างสองใบพร้อมกัน
        foreach (PaymentCharge::open()->where('order_id', $order->id)->get() as $stale) {
            $this->cancel($stale, 'ยอดบิลเปลี่ยน จึงออก QR ใบใหม่');
        }

        $provider = $this->providerFor($branch);
        $account = $this->accountFor($branch, $provider);

        $gateway = $this->gateway($provider);

        /*
        | บีบอายุที่ร้านตั้งไว้ให้เข้าช่วงของเจ้านี้ **ก่อน** คุยกับเขา
        |
        | ตั้ง 15 นาทีไว้ใน config แต่ Beam Bolt รับสูงสุด 10 นาที
        | ถ้าส่งไปตรง ๆ เกตเวย์ปฏิเสธทั้งคำขอ แล้วพนักงานเห็นแค่ "ออก QR ไม่ได้"
        |
        | นโยบายอยู่ที่นี่ ไม่ใช่ในแต่ละ driver — ไม่งั้นร้านจะได้อายุ QR
        | ต่างกันตามเจ้าที่ใช้อยู่โดยไม่มีใครตั้งใจ
        */
        $seconds = $gateway->expiryWindow()->clamp(
            max(1, (int) config('pos.payments.expire_minutes', 15)) * 60,
        );

        $charge = PaymentCharge::create([
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'provider' => $provider,
            'method' => PaymentMethod::PromptPay->value,
            'amount' => $amount,
            'currency' => $branch->currency ?: 'THB',
            'status' => ChargeStatus::Pending,
            'expires_at' => $seconds > 0 ? now()->addSeconds($seconds) : null,
            'created_by' => $staff?->getAuthIdentifier(),
        ]);

        try {
            $result = $gateway->createCharge($charge->fresh('branch'), $account);
        } catch (\Throwable $e) {
            /*
            | ออก QR ไม่สำเร็จ = ยังไม่มีอะไรเกิดขึ้นกับเงิน
            | ลบแถวทิ้งไม่ได้เพราะต้องตอบได้ว่าเคยพยายามออกแล้วพลาด
            | จึงปิดเป็น failed แล้วโยนต่อให้หน้าจอบอกพนักงาน
            */
            $charge->update([
                'status' => ChargeStatus::Failed,
                'failure_message' => mb_substr($e->getMessage(), 0, 255),
            ]);

            throw $e;
        }

        $charge->update([
            'provider_charge_id' => $result->providerChargeId,
            'qr_payload' => $result->qrPayload,
            // เกตเวย์บอกวันหมดอายุจริงมาก็เชื่อของเขา — ของเราเป็นแค่ค่าที่ขอไป
            'expires_at' => $result->expiresAt ?? $charge->expires_at,
            'raw' => $result->raw,
        ]);

        $this->logger->log('payment_charge.open', $order, [
            'provider' => $provider->value,
            'amount' => $amount,
            'charge_uuid' => $charge->uuid,
        ], $branch);

        return $charge->fresh();
    }

    /** ใบที่ยังใช้ได้อยู่ของบิลนี้ — ยอดต้องตรงและยังไม่หมดอายุ */
    protected function reusable(Order $order, float $amount): ?PaymentCharge
    {
        return PaymentCharge::open()
            ->where('order_id', $order->id)
            ->whereNotNull('qr_payload')
            ->get()
            ->first(fn (PaymentCharge $c) => ! $c->hasExpired()
                && abs((float) $c->amount - $amount) <= self::TOLERANCE);
    }

    public function cancel(PaymentCharge $charge, string $reason): PaymentCharge
    {
        if (! $charge->status->isOpen()) {
            return $charge;
        }

        // บอกเกตเวย์ด้วยถ้าเขารับ — ล้มก็ไม่เป็นไร ฝั่งเราปิดของเราเอง
        try {
            $this->gateway($charge->provider)->cancelCharge($charge, $this->accountFor($charge->branch, $charge->provider));
        } catch (\Throwable) {
            // เจ้าที่ยกเลิกไม่ได้หรือติดต่อไม่ได้ — รายการฝั่งเขาจะหมดอายุเอง
        }

        $charge->update([
            'status' => ChargeStatus::Cancelled,
            'failure_message' => mb_substr($reason, 0, 255),
        ]);

        return $charge->fresh();
    }

    /* ---------- ไล่ถามว่าเงินเข้าหรือยัง ---------- */

    /**
     * รอบไล่ถาม — เรียกจาก `pos:poll-charges` ทุกนาที
     *
     * เรียงจากใบที่ถูกถามนานสุดก่อน เพื่อไม่ให้ใบใดใบหนึ่งถูกทิ้งไว้
     * ขณะที่ใบใหม่ถูกถามซ้ำ ๆ (`last_polled_at` ว่างมาก่อนทุกใบ)
     *
     * @return array{polled: int, settled: int, needs_decision: int, failed: int}
     */
    public function pollOpen(int $limit = 50): array
    {
        $charges = PaymentCharge::open()
            ->whereNotNull('provider_charge_id')
            ->orderByRaw('CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_polled_at')
            ->limit($limit)
            ->get();

        $stat = ['polled' => 0, 'settled' => 0, 'needs_decision' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => 0];

        foreach ($charges as $charge) {
            /*
            | หมดอายุแล้ว — ปิดที่ฝั่งเราโดยไม่ต้องถาม
            |
            | ถูกต้องกว่า (เกตเวย์ก็จะตอบว่าหมดอายุ) และประหยัดการเรียก API
            | ซึ่งสำคัญเพราะ Beam คืน HTTP 429 ถ้าถามถี่เกิน
            */
            if ($charge->hasExpired()) {
                $charge->update([
                    'status' => ChargeStatus::Expired,
                    'failure_message' => 'หมดอายุโดยไม่มีเงินเข้า',
                ]);
                $stat['expired']++;

                continue;
            }

            /*
            | ยังไม่ถึงเวลาถามใบนี้
            |
            | ทั้ง Stripe และ Beam ไม่แนะนำให้วน cron ถามทุกนาที และ Beam
            | มีกันสแปม — จึงถามถี่เฉพาะนาทีแรก ๆ ที่ลูกค้ายืนอยู่หน้าเคาน์เตอร์
            | แล้วห่างลงเรื่อย ๆ (ดู App\Payments\PollSchedule)
            */
            if (! PollSchedule::isDue(
                (int) $charge->created_at->diffInSeconds(now()),
                $charge->last_polled_at === null ? null : (int) $charge->last_polled_at->diffInSeconds(now()),
            )) {
                $stat['skipped']++;

                continue;
            }

            $stat['polled']++;
            $after = $this->poll($charge);

            match (true) {
                $after->status === ChargeStatus::Paid => $stat['settled']++,
                $after->status->needsDecision() => $stat['needs_decision']++,
                $after->status === ChargeStatus::Failed => $stat['failed']++,
                default => null,
            };
        }

        return $stat;
    }

    /**
     * ถามให้หน้าจอที่กำลังโชว์ QR อยู่
     *
     * ── ทำไมไม่เรียก poll() ตรง ๆ ────────────────────────────────────
     * เพราะจังหวะถูกกำหนดโดยเบราว์เซอร์ ไม่ใช่โดยเรา หน้าจอที่ถามทุกสองวินาที
     * (หรือเปิดค้างไว้หลายจอ) จะยิงเกตเวย์จนได้ HTTP 429 แล้ว **ทุกบิลในร้าน
     * จะตรวจเงินไม่ได้พร้อมกัน** เพราะโดนกันทั้งร้าน ไม่ใช่แค่จอที่ถามถี่
     *
     * ตัวนี้ตัดสองเรื่องออกจากกัน: หน้าจอถามเราถี่แค่ไหนก็ได้
     * แต่เราถามเกตเวย์ไม่เกินนาทีละ 12 ครั้งต่อหนึ่งใบ (PollSchedule::SCREEN_FLOOR_SECONDS)
     * คำขอที่มาเร็วกว่านั้นได้สถานะล่าสุดจากฐานข้อมูลไป ซึ่งเป็นคำตอบที่ถูกอยู่แล้ว
     *
     * ── หมดอายุแล้วปิดเองโดยไม่ถาม ───────────────────────────────────
     * เหมือน pollOpen() — ถูกกว่าและตรงกว่า ไม่งั้นหน้าจอจะค้างที่ "รอเงินเข้า"
     * ทั้งที่ QR ใช้ไม่ได้แล้ว จนกว่าตัวตั้งเวลาจะมาถึงในอีกหนึ่งนาที
     */
    public function pollForScreen(PaymentCharge $charge): PaymentCharge
    {
        if (! $charge->status->isOpen()) {
            return $charge;
        }

        if ($charge->hasExpired()) {
            $charge->update([
                'status' => ChargeStatus::Expired,
                'failure_message' => 'หมดอายุโดยไม่มีเงินเข้า',
            ]);

            return $charge->fresh();
        }

        // เจ้าที่ถามไม่ได้ (QR ของร้านเอง) — ไม่แกล้งถาม และไม่แกล้งตอบว่าจ่ายแล้ว
        if ($charge->provider_charge_id === null || ! $charge->provider->supportsPolling()) {
            return $charge;
        }

        if (! PollSchedule::isDueForScreen(
            $charge->last_polled_at === null ? null : (int) $charge->last_polled_at->diffInSeconds(now()),
        )) {
            return $charge;
        }

        return $this->poll($charge);
    }

    /* ---------- ตอนพนักงานกดปิดบิล ---------- */

    /**
     * จัดการ QR ของบิลนี้หลังพนักงานปิดบิลไปแล้ว
     *
     * เรียกทันทีหลัง `PaymentService::pay()` สำเร็จ — ทำสองอย่างที่ต่างกันคนละขั้ว
     * แล้วแต่ว่าเงินเข้ามาก่อนหรือยัง
     *
     * ── เงินเข้าแล้ว → ผูกเข้ากับแถว payments ───────────────────────
     * เพื่อให้ตอบได้ว่าเงินก้อนนั้นไปเข้าบิลแถวไหน ไม่ใช่มีสองบันทึก
     * ที่ไม่มีใครรู้ว่าเป็นก้อนเดียวกัน (ตอนกระทบยอดปลายเดือนจะกลายเป็นเงินซ้อน)
     *
     * ── ยังไม่เข้า → ยกเลิก QR ที่ค้างอยู่ ──────────────────────────
     * **ข้อนี้สำคัญกว่าที่เห็น** ถ้าปล่อย QR ค้างไว้แล้วบิลถูกปิดด้วยเงินสด
     * ลูกค้า (หรือคนถัดไปที่ถ่ายรูป QR ไว้) ยังสแกนได้อยู่ เงินจะเข้ามาโดยไม่มี
     * บิลรองรับ กลายเป็น `unmatched` ที่ต้องมีคนตามคืนเงิน — ทั้งที่กันได้ตั้งแต่ต้นทาง
     *
     * ไม่โยน exception ไม่ว่ากรณีไหน — บิลปิดไปแล้วและเงินเข้าครบแล้ว
     * ความผิดพลาดตรงนี้ไม่ควรทำให้พนักงานเห็นว่า "ปิดบิลไม่สำเร็จ" ทั้งที่สำเร็จ
     */
    public function settleAfterPayment(Order $order): void
    {
        if ($charge = $this->paidUnsettled($order)) {
            if ($payment = $this->paymentFor($order, $charge)) {
                $this->attachToPayment($charge, $payment);
            }
        }

        foreach (PaymentCharge::open()->where('order_id', $order->id)->get() as $stale) {
            $this->cancel($stale, 'บิลถูกปิดด้วยวิธีอื่นแล้ว');
        }
    }

    /**
     * แถว payments ที่เป็นเงินก้อนเดียวกับ QR ใบนี้
     *
     * เทียบด้วยยอด ไม่ใช่หยิบแถวแรกที่เป็นพร้อมเพย์ — บิลที่แบ่งจ่ายหลายทาง
     * อาจมีพร้อมเพย์สองบรรทัด (โอนสองรอบ) แล้วการผูกผิดบรรทัดทำให้
     * คำตอบว่า "เงินก้อนนี้อยู่ที่ไหน" ผิดโดยที่ยอดรวมยังถูก จึงไม่มีใครเห็น
     */
    protected function paymentFor(Order $order, PaymentCharge $charge): ?Payment
    {
        $taken = PaymentCharge::where('order_id', $order->id)
            ->whereNotNull('settled_payment_id')
            ->pluck('settled_payment_id')
            ->all();

        return $order->payments()
            ->where('method', PaymentMethod::PromptPay->value)
            ->whereNotIn('id', $taken ?: [0])
            ->get()
            ->first(fn (Payment $p) => abs((float) $p->amount - (float) $charge->amount) <= self::TOLERANCE);
    }

    /** ถามเกตเวย์หนึ่งใบแล้วทำตามคำตอบ */
    public function poll(PaymentCharge $charge): PaymentCharge
    {
        if (! $charge->status->isOpen()) {
            return $charge;
        }

        $touch = fn (array $extra = []) => $charge->update($extra + [
            'last_polled_at' => now(),
            'poll_attempts' => (int) $charge->poll_attempts + 1,
        ]);

        try {
            $result = $this->gateway($charge->provider)->pollCharge(
                $charge,
                $this->accountFor($charge->branch, $charge->provider),
            );
        } catch (\Throwable $e) {
            /*
            | ถามไม่ได้ ≠ ยังไม่จ่าย
            |
            | คงสถานะ pending ไว้เพื่อให้รอบหน้ามาถามอีก และเก็บเหตุผลไว้ให้คนดู
            | ถ้าเปลี่ยนเป็น failed ตรงนี้ เงินที่เข้าไปแล้วจะไม่มีใครมาเก็บอีกเลย
            */
            $touch(['failure_message' => mb_substr(PaymentCharge::UNREACHABLE_PREFIX.$e->getMessage(), 0, 255)]);

            return $charge->fresh();
        }

        return $this->apply($charge, $result, $touch);
    }

    /** ทำตามคำตอบของเกตเวย์ */
    protected function apply(PaymentCharge $charge, ChargeStatusResult $result, callable $touch): PaymentCharge
    {
        if ($result->status === ChargeStatus::Pending) {
            // หมดอายุแล้วแต่เกตเวย์ยังบอกว่ารออยู่ — ปิดของเราเองไม่ต้องรอเขา
            $touch($charge->hasExpired()
                ? ['status' => ChargeStatus::Expired, 'failure_message' => 'หมดอายุโดยไม่มีเงินเข้า']
                : ['failure_message' => $result->message]);

            return $charge->fresh();
        }

        if ($result->status !== ChargeStatus::Paid) {
            $touch([
                'status' => $result->status,
                'failure_message' => mb_substr((string) $result->message, 0, 255),
                'raw' => $result->raw,
            ]);

            return $charge->fresh();
        }

        $touch(['raw' => $result->raw]);

        return $this->settle($charge->fresh(), $result);
    }

    /* ---------- เงินเข้าแล้ว — ลงบิลได้ไหม ---------- */

    /**
     * ตัดสินว่าเงินที่เข้ามาลงบิลได้หรือไม่ แล้วลงให้ครั้งเดียว
     *
     * ── ด่านกันลงซ้ำ ─────────────────────────────────────────────
     * จองแถวด้วย UPDATE แบบมีเงื่อนไข แล้วดูจำนวนแถวที่ถูกแก้
     * ไม่ใช่ select มาดูก่อนแล้วค่อย update — สองเครื่องที่ select พร้อมกัน
     * จะเห็นว่า "ยังไม่ลง" ทั้งคู่ นี่คือบั๊กคลาสเดียวกับคิวออฟไลน์ที่เคยแก้ไปแล้ว
     *
     * ทั้งก้อนอยู่ใน transaction — ถ้าลงบิลพลาดกลางทาง การจองต้องถูกคืนด้วย
     * ไม่งั้นจะเหลือแถวที่บอกว่า paid แต่ไม่มี payments รองรับ
     */
    protected function settle(PaymentCharge $charge, ChargeStatusResult $result): PaymentCharge
    {
        $paid = Money::round($result->paidAmount);
        $paidAt = $result->paidAt ?? now();

        return DB::transaction(function () use ($charge, $paid, $paidAt) {
            $order = Order::lockForUpdate()->find($charge->order_id);

            $outcome = $this->outcomeFor($charge, $order, $paid);

            $claimed = PaymentCharge::whereKey($charge->id)
                ->where('status', ChargeStatus::Pending->value)
                ->update([
                    'status' => $outcome['status'],
                    'paid_amount' => $paid,
                    'paid_at' => $paidAt,
                    'failure_message' => $outcome['message'],
                    'updated_at' => now(),
                ]);

            // มีคนจองไปก่อนแล้ว — ไม่ต้องทำอะไรซ้ำ
            if ($claimed === 0) {
                return $charge->fresh();
            }

            $charge = $charge->fresh();

            if ($outcome['status'] !== ChargeStatus::Paid) {
                $this->logger->log('payment_charge.needs_decision', $order, [
                    'charge_uuid' => $charge->uuid,
                    'status' => $outcome['status']->value,
                    'paid' => $paid,
                    'asked' => (float) $charge->amount,
                ], $charge->branch);

                return $charge;
            }

            /*
            | บิลที่จ่ายก่อนมารับ ปิดเองได้ — ไม่มีพนักงานยืนอยู่กับลูกค้าตอนจ่าย
            | บิลที่เคาน์เตอร์รอพนักงานกด เพราะโต๊ะข้างกันอาจถูกปิดผิดใบ
            */
            if ($order->source === OrderSource::Online) {
                $payment = app(PaymentService::class)->pay($order, [[
                    'method' => PaymentMethod::PromptPay->value,
                    'amount' => $paid,
                    'reference' => $charge->provider_charge_id ?? $charge->uuid,
                ]])->payments()->latest('id')->first();

                $charge->update(['settled_payment_id' => $payment?->id]);
            }

            $this->logger->log('payment_charge.paid', $order, [
                'charge_uuid' => $charge->uuid,
                'amount' => $paid,
                'closed_bill' => $order->source === OrderSource::Online,
            ], $charge->branch);

            return $charge->fresh();
        });
    }

    /**
     * เงินก้อนนี้ควรถูกจัดเป็นอะไร
     *
     * @return array{status: ChargeStatus, message: ?string}
     */
    protected function outcomeFor(PaymentCharge $charge, ?Order $order, float $paid): array
    {
        if (! $order || ! $order->isOpen()) {
            return [
                'status' => ChargeStatus::Unmatched,
                'message' => 'บิลถูกปิดไปแล้วด้วยวิธีอื่น เงินก้อนนี้ยังไม่มีบิลรองรับ',
            ];
        }

        $asked = Money::round((float) $charge->amount);
        $due = Money::round((float) $order->grand_total);

        // ยอดบิลขยับหลังออก QR — ลูกค้าสั่งเพิ่มหรือพนักงานแก้บิล
        if (abs($asked - $due) > self::TOLERANCE) {
            return [
                'status' => ChargeStatus::Mismatch,
                'message' => 'ยอดบิลเปลี่ยนหลังออก QR (ตอนออก '
                    .number_format($asked, 2).' ตอนนี้ '.number_format($due, 2).')',
            ];
        }

        // จ่ายมาไม่ครบ — ปิดบิลแล้วทิ้งส่วนต่างไม่ได้
        if ($paid + self::TOLERANCE < $asked) {
            return [
                'status' => ChargeStatus::Mismatch,
                'message' => 'เงินที่เข้ามาน้อยกว่ายอดที่ขอ ('
                    .number_format($paid, 2).' < '.number_format($asked, 2).')',
            ];
        }

        // จ่ายเกิน — เงินส่วนเกินต้องมีคนตัดสินว่าคืนหรือบันทึกเป็นรับเกิน
        if ($paid > $asked + self::TOLERANCE) {
            return [
                'status' => ChargeStatus::Mismatch,
                'message' => 'เงินที่เข้ามามากกว่ายอดที่ขอ ('
                    .number_format($paid, 2).' > '.number_format($asked, 2).')',
            ];
        }

        return ['status' => ChargeStatus::Paid, 'message' => null];
    }

    /**
     * ผูกรายการที่จ่ายแล้วเข้ากับแถว payments ที่พนักงานสร้างตอนกดปิดบิล
     *
     * ใช้กับบิลหน้าเคาน์เตอร์ที่ระบบไม่ปิดเอง — เพื่อให้ตอบได้ว่าเงินก้อนนี้
     * ไปเข้าบิลแถวไหน ไม่ใช่มีสองบันทึกที่ไม่รู้ว่าเป็นก้อนเดียวกัน
     */
    public function attachToPayment(PaymentCharge $charge, Payment $payment): PaymentCharge
    {
        if ($charge->isSettled()) {
            return $charge;
        }

        $charge->update(['settled_payment_id' => $payment->id]);

        return $charge->fresh();
    }

    /**
     * ใบที่เงินเข้าแล้วแต่ยังรอพนักงานกดปิดบิล
     *
     * ── ทำไมต้องเช็คว่าบิลยังเปิดอยู่ด้วย ────────────────────────────
     * "รอพนักงานกดปิดบิล" เป็นจริงได้ก็ต่อเมื่อยังมีบิลให้ปิด
     *
     * บิลที่ปิดไปแล้วแต่ผูกแถว payments ไม่ได้ (เช่น แบ่งจ่ายหลายบรรทัด
     * จนไม่มีบรรทัดไหนยอดตรงกับ QR) จะค้างเป็น paid + ยังไม่ผูก ตลอดไป
     * ถ้าไม่เช็ค หน้า POS จะขึ้น "เงินเข้าแล้ว กดเพื่อปิดบิล" ค้างอยู่บนบิล
     * ที่ปิดไปแล้ว แล้วพนักงานจะกดปิดซ้ำหรือสงสัยว่าเงินหาย
     *
     * ตัวที่ต้องมองเห็นใบพวกนั้นคือ `settleAfterPayment()` ซึ่งทำงาน
     * **หลัง** บิลถูกปิดไปแล้ว จึงใช้ `paidUnsettled()` ที่ไม่สนสถานะบิล
     */
    public function awaitingCashier(Order $order): ?PaymentCharge
    {
        return $order->isOpen() ? $this->paidUnsettled($order) : null;
    }

    /** เงินเข้าแล้วแต่ยังไม่ได้ผูกกับแถว payments — ไม่สนว่าบิลปิดหรือยัง */
    protected function paidUnsettled(Order $order): ?PaymentCharge
    {
        return PaymentCharge::where('order_id', $order->id)
            ->where('status', ChargeStatus::Paid->value)
            ->whereNull('settled_payment_id')
            ->latest('id')
            ->first();
    }

    /* ---------- ผู้ให้บริการ ---------- */

    /**
     * สาขานี้ใช้เจ้าไหน
     *
     * บัญชีที่เปิดใช้งานและกรอกกุญแจครบเป็นตัวตัดสิน ไม่ใช่ค่าใน config —
     * ค่าใน config เป็นแค่ทางถอยเวลายังไม่มีใครตั้งบัญชี
     *
     * ── ทำไมถอยไป static เสมอ ไม่ใช่โยน error ──────────────────────
     * ร้านต้องรับเงินได้ต่อแม้เกตเวย์ยังไม่ได้ตั้งหรือถูกปิดไป
     * QR ของร้านเองตรวจยอดอัตโนมัติไม่ได้ แต่ยังรับเงินได้จริง
     */
    public function providerFor(Branch $branch): PaymentProvider
    {
        $account = PaymentProviderAccount::where('branch_id', $branch->id)
            ->active()
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->get()
            ->first(fn (PaymentProviderAccount $a) => $a->isUsable() && $a->provider->isGateway());

        if ($account) {
            return $account->provider;
        }

        $fallback = PaymentProvider::tryFrom((string) config('pos.payments.default_provider', 'static'));

        return $fallback ?? PaymentProvider::Static_;
    }

    /**
     * บัญชีของเจ้านี้ที่สาขานี้
     *
     * ── ทำไมไม่กรอง is_active ─────────────────────────────────────
     * `is_active` หมายถึง "ใช้กับ QR ใบใหม่" ไม่ใช่ "ยังคุยกับเจ้านี้ได้"
     *
     * ตอนย้ายเจ้า ร้านปิดของเดิมแล้วเปิดของใหม่ แต่ QR ของเจ้าเดิมที่ออกไปแล้ว
     * ยังค้างอยู่และต้องไล่ถามต่อจนจบ ถ้ากรองตรงนี้ด้วย ตัวไล่ถามจะได้ account
     * เป็น null แล้ว driver คุยกับเกตเวย์ไม่ได้ — เงินที่เข้าใบเก่าจะไม่มีใครเห็น
     *
     * ตัวที่ตัดสินว่าใบใหม่จะใช้เจ้าไหนคือ `providerFor()` ซึ่งกรอง active อยู่แล้ว
     */
    public function accountFor(?Branch $branch, PaymentProvider $provider): ?PaymentProviderAccount
    {
        if (! $branch || ! $provider->needsCredentials()) {
            return null;
        }

        return PaymentProviderAccount::where('branch_id', $branch->id)
            ->where('provider', $provider->value)
            ->first();
    }

    /**
     * ตัวคุยกับเจ้านั้น
     *
     * เจ้าที่ยังไม่ได้เขียน driver ให้โยน error พร้อมบอกชื่อ — ดีกว่าถอยไป static
     * เงียบ ๆ เพราะร้านที่ตั้งใจเก็บผ่านเกตเวย์แล้วได้ QR ที่ตรวจยอดไม่ได้
     * จะไม่รู้เลยว่าระบบไม่ได้ทำตามที่ตั้งไว้
     */
    public function gateway(PaymentProvider $provider): PaymentGateway
    {
        /*
        | จุดต่อของเจ้าใหม่ — ผูก `payment.gateway.beam` ไว้ที่ AppServiceProvider
        | แล้วตัวนี้หยิบไปใช้เอง ไม่ต้องมาแก้ match ข้างล่างทุกครั้งที่เพิ่มเจ้า
        |
        | เทสต์ก็ใช้ทางเดียวกัน จึงทดสอบเส้นทางเงินได้ครบทุกกรณีโดยไม่ต้องยิง API จริง
        */
        $bound = 'payment.gateway.'.$provider->value;

        if (app()->bound($bound)) {
            return app($bound);
        }

        return match ($provider) {
            PaymentProvider::Static_ => app(StaticPromptPayGateway::class),
            default => throw new \RuntimeException(
                'ยังไม่มีตัวเชื่อมของ '.$provider->label().' — ดู claude/payment-gateway-design.md',
            ),
        };
    }
}
