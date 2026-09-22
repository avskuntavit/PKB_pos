/**
 * คีย์กันคำสั่งซ้ำ — คู่กับ middleware IdempotentRequest ฝั่งเซิร์ฟเวอร์
 *
 * ── กฎข้อเดียวที่ต้องจำ ────────────────────────────────────
 * หนึ่งคีย์ = หนึ่ง "ความตั้งใจ" ของผู้ใช้ ไม่ใช่หนึ่ง request
 *
 * กดชำระเงินแล้วเน็ตหลุด กดใหม่อีกที = ความตั้งใจเดิม ต้องใช้คีย์เดิม
 * เซิร์ฟเวอร์จะได้รู้ว่าถ้าครั้งแรกบันทึกไปแล้ว ครั้งที่สองไม่ต้องทำซ้ำ
 *
 * พอสำเร็จแล้วค่อยหมุนคีย์ใหม่ เพราะครั้งถัดไปคือความตั้งใจใหม่จริง ๆ
 * ถ้าไม่หมุน บิลถัดไปของโต๊ะเดิมจะถูกปฏิเสธว่า "บันทึกไปแล้ว"
 */
import { ref } from 'vue'

export const IDEMPOTENCY_HEADER = 'X-Idempotency-Key'

/** สุ่มคีย์ — ใช้ crypto ถ้ามี ถ้าไม่มีก็ถอยไปใช้เวลาบวกเลขสุ่ม */
export function newIdempotencyKey(): string {
    const uuid = globalThis.crypto?.randomUUID?.()

    if (uuid) return uuid

    // เบราว์เซอร์เก่าหรือหน้าที่ไม่ได้เสิร์ฟผ่าน https จะไม่มี crypto.randomUUID
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}

/**
 * คีย์ที่ผูกกับคอมโพเนนต์หนึ่งตัว
 *
 * headers() เอาไปใส่ใน options ของ router.post / form.post ได้ตรง ๆ
 * แล้วเรียก rotate() ใน onSuccess
 */
export function useIdempotencyKey() {
    const key = ref(newIdempotencyKey())

    const rotate = () => {
        key.value = newIdempotencyKey()
    }

    const headers = () => ({ [IDEMPOTENCY_HEADER]: key.value })

    return { key, rotate, headers }
}
