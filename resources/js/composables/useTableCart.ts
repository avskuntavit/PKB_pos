import { computed, onBeforeUnmount, ref, watch } from 'vue'

/**
 * ตะกร้าร่วมของโต๊ะ — ฝั่งหน้าจอ
 *
 * ── ต่างจาก useGuestCart ตรงไหน ──────────────────────────────────────────
 * useGuestCart เก็บของใน localStorage ของเครื่องตัวเอง ใครเปิดเครื่องไหนก็เห็นแต่ของตัวเอง
 * ตัวนี้ไม่เก็บอะไรเลยในเครื่อง ของจริงอยู่ที่เซิร์ฟเวอร์ เครื่องนี้แค่วาดตามที่เซิร์ฟเวอร์บอก
 * เพราะโต๊ะเดียวมีมือถือได้หลายเครื่อง และทุกเครื่องต้องเห็นตะกร้าใบเดียวกัน
 *
 * ── ข้อมูลเข้ามาสองทาง ───────────────────────────────────────────────────
 * 1) ทุกคำสั่งที่เครื่องนี้ยิงไป คืนสรุปตะกร้าล่าสุดกลับมาด้วยเสมอ — เห็นผลทันทีที่กด
 * 2) รอบ poll ของ /order/bill พาตะกร้ามาด้วย — เห็นของที่คนอื่นที่โต๊ะเพิ่งกด
 *
 * สองทางนี้ชนกันได้ ดูคำอธิบายที่ applyFromPoll()
 */

export interface SharedCartLine {
    /** id ของแถวในตาราง ใช้เป็นคีย์อ้างอิงตอนสั่งแก้ ไม่ใช่ key ที่ประกอบเองแบบตะกร้าส่วนตัว */
    id: number
    product_id: number
    name: string
    qty: number
    unit_price: number
    line_total: number
    modifier_names: string[]
    note: string | null
    /** ชื่อเล่นของคนที่หยิบใส่ — null ได้ ถ้าเขาข้ามการตั้งชื่อ */
    guest_name: string | null
    /** ของเครื่องนี้เองหรือเปล่า — ใช้ทำตัวหนาบนจอเท่านั้น ไม่ใช่สิทธิ์ในการแก้ */
    mine: boolean
}

export interface SharedCartSummary {
    lines: SharedCartLine[]
    count: number
    total: number
    /** เวลาที่จะส่งเข้าครัวจริง (ISO) — null = ไม่มีใครกดส่งค้างอยู่ */
    submit_at: string | null
    submit_by: string | null
    submit_is_mine: boolean
}

export interface SubmitContact {
    name: string
    phone: string
    payment_intent: string
    note: string | null
}

const EMPTY: SharedCartSummary = {
    lines: [],
    count: 0,
    total: 0,
    submit_at: null,
    submit_by: null,
    submit_is_mine: false,
}

/**
 * ช่วงที่ไม่เชื่อข้อมูลจากรอบ poll หลังเครื่องนี้เพิ่งสั่งอะไรไป
 * ดูเหตุผลเต็มที่ applyFromPoll()
 */
const POLL_TRUST_DELAY_MS = 1500

/** โทเคนกัน CSRF ที่ Laravel ฝังมาให้ในคุกกี้ */
function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

    return match ? decodeURIComponent(match[1]) : ''
}

export function useTableCart() {
    const summary = ref<SharedCartSummary>({ ...EMPTY })

    /** false = รอบการนั่งโต๊ะนี้จบไปแล้ว (พนักงานเพิ่งปิดบิล) ตะกร้าร่วมใช้ไม่ได้อีก */
    const seated = ref(true)
    const error = ref<string | null>(null)

    /** มีคำสั่งของเครื่องนี้ค้างอยู่กี่คำสั่ง — ใช้ปิดปุ่มไม่ให้กดรัว */
    const inFlight = ref(0)
    const busy = computed(() => inFlight.value > 0)

    let lastCommandAt = 0

    /*
    | นาฬิกาที่เดินเฉพาะตอนมีคนกดส่ง
    |
    | ไม่ตั้ง interval ค้างไว้ตลอดอายุหน้า เพราะลูกค้านั่งดูเมนูเป็นสิบนาที
    | โดยไม่มีอะไรนับถอยหลัง การปลุกเครื่องทุกวินาทีในช่วงนั้นคือการกินแบตเปล่า ๆ
    */
    const now = ref(Date.now())
    let clock: ReturnType<typeof setInterval> | null = null

    const lines = computed(() => summary.value.lines)
    const count = computed(() => summary.value.count)
    const total = computed(() => summary.value.total)
    const submitBy = computed(() => summary.value.submit_by)
    const submitIsMine = computed(() => summary.value.submit_is_mine)

    const submitAt = computed(() => {
        if (!summary.value.submit_at) return null

        const at = new Date(summary.value.submit_at).getTime()

        return Number.isFinite(at) ? at : null
    })

    const isCounting = computed(() => submitAt.value !== null)

    /** วินาทีที่เหลือ ปัดขึ้นเพื่อให้เลขบนจอเริ่มที่ 5 ไม่ใช่ 4 */
    const secondsLeft = computed(() => {
        if (submitAt.value === null) return 0

        return Math.max(0, Math.ceil((submitAt.value - now.value) / 1000))
    })

    /** นับครบแล้ว — เครื่องที่กดส่งเป็นคนยิงคำสั่งส่งจริง */
    const isDue = computed(() => submitAt.value !== null && now.value >= submitAt.value)

    watch(
        isCounting,
        (counting) => {
            if (counting && !clock) {
                now.value = Date.now()
                clock = setInterval(() => (now.value = Date.now()), 200)
                return
            }

            if (!counting && clock) {
                clearInterval(clock)
                clock = null
            }
        },
        { immediate: true },
    )

    onBeforeUnmount(() => {
        if (clock) clearInterval(clock)
    })

    /*
    |--------------------------------------------------------------------------
    | รับข้อมูลเข้า
    |--------------------------------------------------------------------------
    */

    function apply(next: SharedCartSummary | null | undefined) {
        if (!next) return

        summary.value = next
        seated.value = true
    }

    /**
     * ข้อมูลจากรอบ poll — เชื่อได้เกือบตลอด ยกเว้นช่วงที่เครื่องนี้เพิ่งสั่งอะไรไป
     *
     * รอบ poll ออกเดินทางก่อนที่ลูกค้าจะกดปุ่ม แต่กลับมาถึงทีหลังได้ง่าย ๆ บนเน็ตมือถือ
     * ถ้าเอามาทับตรง ๆ ของที่เพิ่งกดเพิ่มจะกระพริบหายไปแล้วค่อยโผล่กลับมารอบหน้า
     * ซึ่งอ่านว่า "ระบบพัง" ในสายตาลูกค้า ทั้งที่ของเข้าตะกร้าไปเรียบร้อยแล้ว
     *
     * ข้ามรอบนั้นไปเฉย ๆ ไม่เสียอะไร เพราะรอบถัดไปมาถึงในไม่กี่วินาที
     * และคำตอบของคำสั่งที่เพิ่งยิงไปก็เป็นของสดกว่าอยู่แล้ว
     */
    function applyFromPoll(next: SharedCartSummary | null | undefined) {
        if (!next) return
        if (inFlight.value > 0) return
        if (Date.now() - lastCommandAt < POLL_TRUST_DELAY_MS) return

        apply(next)
    }

    /*
    |--------------------------------------------------------------------------
    | คำสั่ง
    |--------------------------------------------------------------------------
    */

    interface CartResponse {
        ok?: boolean
        seated?: boolean
        message?: string
        cart?: SharedCartSummary
        order_no?: string
    }

    async function send(method: string, url: string, body?: Record<string, unknown>): Promise<CartResponse | null> {
        inFlight.value += 1

        try {
            const response = await fetch(url, {
                method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: body === undefined ? undefined : JSON.stringify(body),
            })

            const payload = (await response.json().catch(() => null)) as CartResponse | null

            if (response.status === 409) {
                // รอบการนั่งโต๊ะจบไปแล้ว — ตะกร้าร่วมไม่มีอยู่แล้ว ไม่ใช่แค่คำสั่งนี้ล้ม
                seated.value = false
                summary.value = { ...EMPTY }
                error.value = payload?.message ?? 'รอบการนั่งโต๊ะนี้จบแล้ว'

                return null
            }

            if (response.status === 419) {
                error.value = 'หน้านี้เปิดค้างไว้นานเกินไป กรุณารีเฟรชหน้าอีกครั้ง'

                return null
            }

            if (response.status === 429) {
                error.value = 'โต๊ะนี้กดถี่เกินไป รอสักครู่แล้วลองใหม่'

                return null
            }

            // 422 มาพร้อมตะกร้าล่าสุดเสมอ เพราะสาเหตุที่พบบ่อยที่สุดคือมีคนที่โต๊ะลบไปก่อนแล้ว
            apply(payload?.cart)
            lastCommandAt = Date.now()

            if (!response.ok || payload?.ok === false) {
                error.value = payload?.message ?? 'ทำรายการไม่สำเร็จ ลองใหม่อีกครั้ง'

                return null
            }

            error.value = null

            return payload
        } catch {
            error.value = 'เชื่อมต่อไม่ได้ ตรวจสัญญาณแล้วลองใหม่'

            return null
        } finally {
            inFlight.value -= 1
            lastCommandAt = Date.now()
        }
    }

    function add(productId: number, qty: number, modifierIds: number[], note: string | null) {
        return send('POST', '/order/cart', {
            product_id: productId,
            qty,
            modifier_ids: modifierIds,
            note,
        })
    }

    /** 0 = ลบบรรทัดนั้น เซิร์ฟเวอร์รับ 0 อยู่แล้ว ปุ่มลบกับปุ่มลดจำนวนจึงใช้เส้นทางเดียวกัน */
    function setQty(itemId: number, qty: number) {
        return send('PATCH', `/order/cart/${itemId}`, { qty })
    }

    function remove(itemId: number) {
        return send('DELETE', `/order/cart/${itemId}`)
    }

    function clear() {
        return send('DELETE', '/order/cart')
    }

    function requestSubmit() {
        return send('POST', '/order/cart/submit')
    }

    function cancelSubmit() {
        return send('POST', '/order/cart/submit/cancel')
    }

    async function confirmSubmit(contact: SubmitContact): Promise<string | null> {
        const payload = await send('POST', '/order/cart/submit/confirm', {
            name: contact.name,
            phone: contact.phone,
            payment_intent: contact.payment_intent,
            note: contact.note,
        })

        return payload?.order_no ?? null
    }

    function clearError() {
        error.value = null
    }

    return {
        lines,
        count,
        total,
        submitAt,
        submitBy,
        submitIsMine,
        secondsLeft,
        isCounting,
        isDue,
        seated,
        error,
        busy,
        apply,
        applyFromPoll,
        add,
        setQty,
        remove,
        clear,
        requestSubmit,
        cancelSubmit,
        confirmSubmit,
        clearError,
    }
}
