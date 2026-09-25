<?php

namespace App\Http\Controllers\Pos;

use App\Enums\ChargeStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentCharge;
use App\Services\PaymentChargeService;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QR รับเงินบนหน้า POS
 *
 * ── ทำไมเป็น JSON ไม่ใช่ Inertia ─────────────────────────────────────────
 * หน้าต่าง QR ต้องถามสถานะซ้ำ ๆ ขณะลูกค้ายืนสแกนอยู่ ถ้าตอบเป็น Inertia redirect
 * ทุกครั้ง หน้าจะถูกวาดใหม่ทั้งหน้าและหน้าต่างจะปิดตัวเองทุกรอบ
 *
 * ── ค่าจริงที่ส่งออกไปมีอะไรบ้าง ─────────────────────────────────────────
 * `qr_payload` ถูกส่งออกไปโดยตั้งใจ — มันคือสิ่งที่ต้องเอาไปวาดให้ลูกค้าสแกน
 * แต่ไม่มีอะไรเกี่ยวกับบัญชีเกตเวย์ (กุญแจ · merchant id) ออกไปด้วยเลย
 * และ `raw` ซึ่งเป็นคำตอบดิบของเกตเวย์ก็ไม่ถูกส่ง เพราะไม่รู้ว่าข้างในมีอะไรบ้าง
 */
class PaymentChargeController extends Controller
{
    public function __construct(protected PaymentChargeService $charges) {}

    /** ออก QR ให้บิลนี้ — กดซ้ำได้ ใบเดิมถูกใช้ต่อถ้ายอดยังเท่าเดิม */
    public function store(Order $order): JsonResponse
    {
        $this->authorizeOrder($order);

        try {
            $charge = $this->charges->open($order, auth()->user());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            /*
            | เกตเวย์ติดต่อไม่ได้ / ปฏิเสธคำขอ
            |
            | ข้อความของเกตเวย์คนอ่านไม่รู้เรื่อง และอาจมีรายละเอียดภายในติดมา
            | จึงบอกสิ่งที่พนักงาน **ทำต่อได้** แทน — เก็บเงินสดหรือใช้ QR ของร้าน
            | รายละเอียดจริงถูกเก็บไว้ใน payment_charges.failure_message แล้ว
            */
            report($e);

            return response()->json([
                'message' => 'ออก QR ไม่สำเร็จ กรุณาเก็บเงินด้วยวิธีอื่นไปก่อน',
            ], 502);
        }

        return response()->json(['charge' => $this->payload($charge)]);
    }

    /** ถามสถานะ — หน้าจอเรียกซ้ำระหว่างลูกค้าสแกน */
    public function show(PaymentCharge $charge): JsonResponse
    {
        $this->authorizeCharge($charge);

        /*
        | ถามเกตเวย์ไม่ได้ **ไม่ใช่** เหตุให้หน้าจอพัง
        |
        | ใบนี้ยังค้างอยู่และตัวตั้งเวลาจะมาถามต่อเอง หน้าจอจึงควรเห็นสถานะล่าสุด
        | ที่เรามีพร้อมป้ายว่าตรวจไม่ได้ชั่วคราว ดีกว่าขึ้น error แดงแล้วพนักงาน
        | เข้าใจว่าเงินไม่เข้า ทั้งที่ยังไม่รู้ด้วยซ้ำ
        |
        | ── ทำไม stale ไม่ได้มาจาก catch ─────────────────────────────────
        | `poll()` จงใจกลืน exception ไว้เอง (ตัวไล่ถามต้องทำงานต่อได้ทั้งรอบ)
        | แล้วบันทึกเหตุผลไว้ใน failure_message แทน — การอ่าน stale จาก catch
        | จึงเป็นโค้ดที่ไม่เคยทำงาน และป้ายจะไม่ขึ้นเลยในกรณีที่มันถูกสร้างมาเพื่อ
        |
        | ตัว try/catch ยังอยู่เป็นกันชนของข้อผิดพลาดอื่นที่หลุดออกมาได้จริง
        | (เช่น container ผูก driver ไม่ได้) ซึ่งไม่ควรทำให้หน้าจอค้าง
        */
        try {
            $charge = $this->charges->pollForScreen($charge);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'charge' => $this->payload($charge->fresh()),
                'stale' => true,
            ]);
        }

        return response()->json([
            'charge' => $this->payload($charge),
            'stale' => $charge->lastPollFailed(),
        ]);
    }

    /** ยกเลิก QR — ลูกค้าเปลี่ยนใจไปจ่ายเงินสด */
    public function destroy(Request $request, PaymentCharge $charge): JsonResponse
    {
        $this->authorizeCharge($charge);

        // เงินเข้ามาแล้วยกเลิกไม่ได้ — ต้องคืนเงิน ไม่ใช่ทำให้รายการหายไป
        if ($charge->status->hasMoney()) {
            return response()->json([
                'message' => 'รายการนี้มีเงินเข้ามาแล้ว ยกเลิกไม่ได้',
                'charge' => $this->payload($charge),
            ], 422);
        }

        $charge = $this->charges->cancel(
            $charge,
            $request->string('reason')->value() ?: 'พนักงานยกเลิกที่หน้าจอ',
        );

        return response()->json(['charge' => $this->payload($charge)]);
    }

    /* ---------- ภายใน ---------- */

    protected function authorizeOrder(Order $order): void
    {
        abort_unless($order->branch_id === CurrentBranch::id(), 403);
    }

    protected function authorizeCharge(PaymentCharge $charge): void
    {
        abort_unless($charge->branch_id === CurrentBranch::id(), 403);
    }

    /** @return array<string, mixed> */
    protected function payload(PaymentCharge $charge): array
    {
        return static::chargePayload($charge);
    }

    /**
     * รูปเดียวกับที่หน้า Terminal ได้ตอนโหลดหน้า
     *
     * เป็น static เพื่อให้ PosController หยิบไปใช้ได้ — ถ้าสองที่ประกอบข้อมูล
     * กันเอง วันหนึ่งหน้าจอจะได้ฟิลด์ไม่ครบตอนโหลดหน้า แล้วครบตอนถามซ้ำ
     * ซึ่งเป็นบั๊กที่เห็นแค่วินาทีแรกและหาสาเหตุยากมาก
     *
     * @return array<string, mixed>
     */
    public static function chargePayload(PaymentCharge $charge): array
    {
        return [
            'id' => $charge->id,
            'uuid' => $charge->uuid,
            'order_id' => $charge->order_id,
            'status' => $charge->status->value,
            'status_label' => $charge->status->label(),
            'provider' => $charge->provider->value,
            'provider_label' => $charge->provider->label(),
            // หน้าจอต้องบอกพนักงานให้ตรงว่ารอระบบตรวจได้ไหม หรือต้องดูสลิปเอง
            'verifies_automatically' => $charge->provider->supportsPolling(),
            'amount' => (float) $charge->amount,
            'paid_amount' => $charge->paid_amount === null ? null : (float) $charge->paid_amount,
            'qr_payload' => $charge->qr_payload,
            'expires_at' => $charge->expires_at?->toIso8601String(),
            'note' => static::noteFor($charge),
            'settled' => $charge->isSettled(),
        ];
    }

    /**
     * ข้อความที่หน้าจอเอาไปแสดงได้ — **ของเรา ไม่ใช่ของเกตเวย์**
     *
     * ── ทำไมไม่ส่ง failure_message ตรง ๆ ──────────────────────────────
     * คอลัมน์นั้นเก็บข้อความดิบของเกตเวย์ไว้ด้วย (ทั้งตอนออก QR ไม่สำเร็จ
     * และตอนถามไม่ได้) ซึ่งอาจมีรหัสภายในหรือ merchant id ติดมา
     * `store()` ระวังข้อนี้อยู่แล้ว การปล่อยให้ `show()` ส่งออกไปจึงเป็นช่องที่
     * เปิดไว้ข้างหลังโดยไม่มีใครเห็น — และข้อความเกตเวย์คนอ่านไม่รู้เรื่องอยู่ดี
     *
     * ข้อความจริงยังอยู่ใน `payment_charges.failure_message` ไว้ตามเรื่องทีหลัง
     */
    protected static function noteFor(PaymentCharge $charge): ?string
    {
        // ถามไม่ได้ — หน้าจอใช้ธง stale ไม่ต้องมีข้อความ
        if ($charge->lastPollFailed()) {
            return null;
        }

        return match ($charge->status) {
            // ข้อความของเกตเวย์ — แทนด้วยสิ่งที่พนักงานทำต่อได้
            ChargeStatus::Failed => 'เกตเวย์ปฏิเสธคำขอ — ออก QR ใบใหม่ หรือเก็บเงินด้วยวิธีอื่น',
            // ที่เหลือเป็นข้อความที่ระบบนี้เขียนเองทั้งหมด (หมดอายุ · ยกเลิก · ยอดไม่ตรง)
            default => $charge->failure_message,
        };
    }
}
