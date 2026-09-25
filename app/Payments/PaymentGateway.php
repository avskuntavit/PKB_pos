<?php

namespace App\Payments;

use App\Models\PaymentCharge;
use App\Models\PaymentProviderAccount;

/**
 * ผู้ให้บริการออก QR รับเงิน — สัญญาที่ทุกเจ้าต้องทำได้
 *
 * ── ขอบเขตของสัญญานี้ — จงใจให้แคบ ────────────────────────────────────
 * ตัว driver มีหน้าที่ **คุยกับเจ้านั้น** เท่านั้น: ออก QR · ถามสถานะ · ยกเลิก
 * มันไม่ตัดสินใจว่าจะลงบิลไหม ไม่แตะตาราง payments ไม่ปิดบิล ไม่คิดส่วนต่าง
 * ทั้งหมดนั้นเป็นงานของ `PaymentChargeService` ที่เดียว
 *
 * เหตุผล: กฎว่า "เงินเท่าไหร่ถือว่าลงบิลได้" เป็นนโยบายของร้าน ไม่ใช่ของเกตเวย์
 * ถ้าแต่ละ driver ตัดสินเอง ร้านจะได้พฤติกรรมต่างกันตามเจ้าที่ใช้อยู่
 * แล้วไม่มีใครตอบได้ว่าทำไมบิลใบนี้ปิดเองแต่ใบนั้นไม่ปิด
 *
 * ── driver ต้องไม่กลืน error ──────────────────────────────────────────
 * ติดต่อไม่ได้ / ตอบมาไม่รู้เรื่อง = **โยน exception**
 * ห้ามคืน "ยังไม่จ่าย" เพราะนั่นแยกไม่ออกจาก "ถามแล้วเขาบอกว่ายังไม่จ่าย"
 * และตัวไล่ถามจะเลิกสนใจรายการนั้นทั้งที่จริง ๆ อาจมีเงินเข้าไปแล้ว
 */
interface PaymentGateway
{
    /** ชื่อที่บันทึกลง payment_charges.provider */
    public function name(): string;

    /**
     * ช่วงอายุ QR ที่เจ้านี้ยอมรับ
     *
     * บางเจ้า **บังคับ** ไม่ใช่แนะนำ — Beam Bolt รับ 90–600 วินาทีเท่านั้น
     * ค่าที่ร้านตั้งไว้ใน config จึงต้องถูกบีบให้เข้าช่วงก่อนส่ง ไม่งั้นเกตเวย์
     * ปฏิเสธทั้งคำขอ แล้วพนักงานเห็นแค่ "ออก QR ไม่ได้" โดยไม่รู้ว่าเพราะอะไร
     */
    public function expiryWindow(): ExpiryWindow;

    /**
     * ออก QR ให้ลูกค้าสแกน
     *
     * ยอดที่ขออ่านจาก `$charge->amount` (บาท) — driver ต้องไม่คิดยอดเอง
     * และต้องแปลงหน่วยด้วย `Money::toSatang()` ห้ามคูณ 100 เอง
     * (Stripe และ Beam รับเป็นสตางค์ · ผิดหน่วยคือผิด 100 เท่า)
     *
     * วันหมดอายุที่ต้องขอจากเกตเวย์อ่านจาก `$charge->expires_at` ซึ่งถูกบีบ
     * ให้เข้าช่วงของเจ้านี้มาแล้ว — driver ไม่ต้องบีบซ้ำ
     *
     * @throws \RuntimeException เมื่อออก QR ไม่ได้
     */
    public function createCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): ChargeResult;

    /**
     * ถามเกตเวย์ว่ารายการนี้จ่ายแล้วหรือยัง
     *
     * @throws \RuntimeException เมื่อถามไม่ได้ (ห้ามคืนค่าว่ายังไม่จ่าย)
     */
    public function pollCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): ChargeStatusResult;

    /**
     * ยกเลิก QR ที่ยังไม่มีเงินเข้า
     *
     * เจ้าที่ยกเลิกไม่ได้ให้ปล่อยผ่านเงียบ ๆ — ฝั่งเราจะตั้งสถานะเป็น cancelled เอง
     * และรายการที่ค้างอยู่ฝั่งเขาจะหมดอายุไปตามเวลาของมัน
     */
    public function cancelCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): void;
}
