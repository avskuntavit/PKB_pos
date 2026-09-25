/**
 * เฝ้าดูว่าหน้าจอนี้ยังคุยกับเซิร์ฟเวอร์ได้อยู่ไหม
 *
 * ── ทำไมไม่ใช้ navigator.onLine อย่างเดียว ─────────────────
 * navigator.onLine บอกแค่ว่า "เครื่องมีการ์ดเน็ตที่เสียบอยู่" ไม่ได้บอกว่า
 * คุยกับเซิร์ฟเวอร์ของเราได้ แท็บเล็ตที่ยังเกาะ wifi ร้านอยู่แต่เราเตอร์ค้าง
 * จะรายงานว่า online ตลอด ซึ่งเป็นอาการที่เจอบ่อยที่สุดในร้านจริง
 *
 * จึงยิงชีพจรไปที่เซิร์ฟเวอร์เองด้วย และใช้ navigator.onLine เป็นแค่
 * ตัวกระตุ้นให้เช็คทันทีเวลาสถานะเปลี่ยน ไม่ใช่ตัวตัดสิน
 *
 * ── เรื่อง timeout ─────────────────────────────────────────
 * ต้องมี AbortController เสมอ เพราะ fetch ที่ค้างจะไม่ reject เอง
 * ถ้าไม่ตัด หน้าจอจะคิดว่า "กำลังเช็คอยู่" ไปจนกว่าจะปิดแท็บ
 *
 * ── ทำไมเป็น singleton ─────────────────────────────────────
 * ตอนนี้มีสองที่ที่ต้องรู้ว่าหลุดอยู่ไหม — แถบเตือนด้านบน กับหน้าคีย์บิล
 * ที่ต้องสลับไปเก็บของลงคิวแทนการยิงเซิร์ฟเวอร์ ถ้าต่างคนต่างถือ state
 * จะได้ชีพจรสองชุดยิงถี่เป็นสองเท่า และแย่กว่านั้นคือสองที่อาจตอบไม่ตรงกัน
 * ชั่วขณะ — แถบบอกว่าออนไลน์แล้วแต่ของยังลงคิวอยู่ ซึ่งอ่านไม่ออกเลยว่าเกิดอะไร
 *
 * ตัวเลือก (intervalMs / timeoutMs / url) ของผู้เรียก **คนแรก** เท่านั้นที่มีผล
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

export type ConnectionState = 'online' | 'offline'

interface Options {
    /** ถี่แค่ไหนตอนปกติ */
    intervalMs?: number
    /** รอคำตอบนานสุดเท่าไหร่ถึงถือว่าไม่ตอบ */
    timeoutMs?: number
    url?: string
}

/** ถอยห่างขึ้นเรื่อย ๆ เวลาล้ม แต่ไม่เกิน 30 วิ ร้านรอนานกว่านั้นไม่ไหว */
const BACKOFF_MS = [5000, 10000, 20000, 30000]

const state = ref<ConnectionState>('online')
/** เซิร์ฟเวอร์ตอบ แต่บอกว่าไม่ได้ล็อกอินแล้ว — คนละอาการกับเน็ตหลุด */
const sessionExpired = ref(false)
const lastOkAt = ref<number | null>(null)
const failures = ref(0)
const checking = ref(false)
/** เหลืออีกกี่วินาทีจะลองใหม่ — ไว้โชว์ให้พนักงานรู้ว่าระบบยังพยายามอยู่ */
const retryInSeconds = ref(0)

let settings = { intervalMs: 20000, timeoutMs: 5000, url: '/pos/health' }
let configured = false

let timer: ReturnType<typeof setTimeout> | null = null
let ticker: ReturnType<typeof setInterval> | null = null
let stopped = true

/** มีคอมโพเนนต์กี่ตัวที่ยังต้องการชีพจรอยู่ — ถึง 0 เมื่อไหร่จึงหยุดยิง */
let consumers = 0

/** คนที่อยากรู้ว่า "กลับมาออนไลน์แล้ว" — ใช้ปลุกการซิงก์คิวที่ค้างอยู่ */
const reconnectHandlers = new Set<() => void>()

const delayForNextCheck = () =>
    state.value === 'online'
        ? settings.intervalMs
        : BACKOFF_MS[Math.min(failures.value - 1, BACKOFF_MS.length - 1)] ?? 30000

async function check(): Promise<void> {
    if (stopped || checking.value) return

    checking.value = true

    const controller = new AbortController()
    const cut = setTimeout(() => controller.abort(), settings.timeoutMs)

    try {
        const response = await fetch(settings.url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
        })

        /*
        | 401/419 = เซิร์ฟเวอร์ยังอยู่ แต่ session หมดอายุ
        | ต้องแยกจาก "ติดต่อไม่ได้" เพราะวิธีแก้คนละเรื่องกันคนละขั้ว
        | อันหนึ่งรอเน็ตกลับมา อีกอันต้องล็อกอินใหม่
        */
        if (response.status === 401 || response.status === 419) {
            sessionExpired.value = true
            markOnline()

            return
        }

        if (! response.ok) throw new Error(`HTTP ${response.status}`)

        const body = await response.json()

        if (body?.ok !== true) throw new Error('ตอบกลับผิดรูปแบบ')

        sessionExpired.value = false
        markOnline()
    } catch {
        failures.value += 1
        state.value = 'offline'
    } finally {
        clearTimeout(cut)
        checking.value = false
        schedule()
    }
}

function markOnline() {
    const wasOffline = state.value === 'offline'

    state.value = 'online'
    failures.value = 0
    lastOkAt.value = Date.now()

    // ปลุกเฉพาะตอน "เปลี่ยนจากหลุดเป็นต่อได้" ไม่ใช่ทุกรอบที่ชีพจรผ่าน
    if (wasOffline) {
        reconnectHandlers.forEach((fn) => fn())
    }
}

function schedule() {
    if (timer) clearTimeout(timer)
    if (stopped) return

    const delay = delayForNextCheck()
    retryInSeconds.value = Math.ceil(delay / 1000)
    timer = setTimeout(check, delay)
}

/** พนักงานกด "ลองใหม่เดี๋ยวนี้" — ไม่ต้องรอครบรอบ */
function checkNow() {
    if (timer) clearTimeout(timer)
    void check()
}

function onBrowserOnline() {
    checkNow()
}

function onBrowserOffline() {
    // เชื่อได้ทางเดียว: ถ้าเบราว์เซอร์บอกว่าไม่มีเน็ต ก็ไม่มีจริง ๆ
    failures.value += 1
    state.value = 'offline'
    schedule()
}

export function useConnection(options: Options = {}) {
    // ผู้เรียกคนแรกเป็นคนตั้งค่า คนถัดมาเกาะชีพจรเส้นเดียวกัน
    if (! configured) {
        settings = {
            intervalMs: options.intervalMs ?? settings.intervalMs,
            timeoutMs: options.timeoutMs ?? settings.timeoutMs,
            url: options.url ?? settings.url,
        }
        configured = true
    }

    const isOffline = computed(() => state.value === 'offline')

    /** ให้คอมโพเนนต์ฝากงานไว้ทำตอนกลับมาออนไลน์ แล้วถอนออกเองตอนถูกถอด */
    function onReconnect(handler: () => void) {
        reconnectHandlers.add(handler)

        onBeforeUnmount(() => reconnectHandlers.delete(handler))
    }

    onMounted(() => {
        consumers += 1

        // ตัวแรกเท่านั้นที่จุดชีพจร ตัวถัดมาแค่มาอ่านค่าเดียวกัน
        if (consumers > 1) return

        stopped = false
        void check()

        ticker = setInterval(() => {
            if (retryInSeconds.value > 0) retryInSeconds.value -= 1
        }, 1000)

        window.addEventListener('online', onBrowserOnline)
        window.addEventListener('offline', onBrowserOffline)
    })

    onBeforeUnmount(() => {
        consumers -= 1

        if (consumers > 0) return

        stopped = true
        if (timer) clearTimeout(timer)
        if (ticker) clearInterval(ticker)
        timer = null
        ticker = null
        window.removeEventListener('online', onBrowserOnline)
        window.removeEventListener('offline', onBrowserOffline)
    })

    return {
        state,
        isOffline,
        sessionExpired,
        lastOkAt,
        failures,
        checking,
        retryInSeconds,
        checkNow,
        onReconnect,
    }
}
