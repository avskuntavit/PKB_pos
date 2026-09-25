<?php

namespace App\Payments;

/**
 * ช่วงอายุ QR ที่เกตเวย์เจ้านั้นยอมรับ
 *
 * ── ทำไมต้องเป็นของ driver ไม่ใช่ค่าเดียวใน config ─────────────────────
 * แต่ละเจ้ากำหนดช่วงไม่เหมือนกัน และบางเจ้า **บังคับ** ไม่ใช่แนะนำ
 *
 *   Stripe (PaymentIntent)  ตั้งต้น 24 ชม. · Checkout ตั้งขั้นต่ำได้ 30 นาที
 *   Beam (Bolt Intent)      บังคับ 90–600 วินาที (1.5–10 นาที)
 *   QR ของร้านเอง            ไม่หมดอายุ
 *
 * ค่าที่ร้านตั้งไว้ใน config อาจอยู่นอกช่วงของเจ้าที่ใช้อยู่ (ตั้ง 15 นาที
 * แต่ Beam รับสูงสุด 10 นาที) ถ้าส่งไปตรง ๆ เกตเวย์จะปฏิเสธทั้งคำขอ
 * แล้วพนักงานจะเห็นแค่ "ออก QR ไม่ได้" โดยไม่รู้ว่าเพราะอะไร
 *
 * จึงให้ driver ประกาศช่วงของตัวเอง แล้วบีบค่าของร้านให้เข้าช่วงก่อนส่ง
 */
final readonly class ExpiryWindow
{
    /**
     * @param  int  $minSeconds  สั้นกว่านี้เกตเวย์ไม่รับ
     * @param  int|null  $maxSeconds  null = ไม่มีเพดาน (หรือไม่หมดอายุเลย)
     */
    public function __construct(
        public int $minSeconds,
        public ?int $maxSeconds = null,
    ) {
        if ($minSeconds < 0) {
            throw new \InvalidArgumentException('อายุขั้นต่ำติดลบไม่ได้');
        }

        if ($maxSeconds !== null && $maxSeconds < $minSeconds) {
            throw new \InvalidArgumentException('เพดานอายุน้อยกว่าขั้นต่ำไม่ได้');
        }
    }

    /** เจ้าที่ QR ไม่หมดอายุ */
    public static function never(): self
    {
        return new self(0, 0);
    }

    /** QR ไม่หมดอายุเลยใช่ไหม */
    public function isUnlimited(): bool
    {
        return $this->maxSeconds === 0;
    }

    /**
     * บีบค่าที่ร้านตั้งไว้ให้เข้าช่วงที่เจ้านี้รับ
     *
     * คืน 0 = ไม่ต้องตั้งวันหมดอายุ (เจ้าที่ QR ไม่หมดอายุ)
     */
    public function clamp(int $seconds): int
    {
        /*
        | ไม่ต้องแยกเคส "ไม่หมดอายุ" ออกมา
        |
        | never() คือ min 0 / max 0 ทางปกติจึงบีบทุกค่าลงมาเป็น 0 เองอยู่แล้ว
        | (เคยเขียนแยกไว้ แต่เป็นสาขาที่ไม่มีอินพุตไหนเดินเข้าไปได้ — ตัวสร้าง
        | ปฏิเสธเพดานที่น้อยกว่าขั้นต่ำ จึงไม่มี ExpiryWindow(90, 0) ให้เกิดขึ้น)
        */
        $seconds = max($this->minSeconds, $seconds);

        return $this->maxSeconds === null ? $seconds : min($this->maxSeconds, $seconds);
    }

    /** ค่าที่ร้านตั้งไว้อยู่นอกช่วงของเจ้านี้ไหม — ไว้เตือนตอนตั้งค่า */
    public function wouldClamp(int $seconds): bool
    {
        return ! $this->isUnlimited() && $this->clamp($seconds) !== $seconds;
    }
}
