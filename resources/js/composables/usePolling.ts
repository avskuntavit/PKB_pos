import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * เรียก endpoint ซ้ำตามรอบเวลา
 *
 * หยุดเองเมื่อผู้ใช้สลับแท็บออกไป แล้วดึงข้อมูลทันทีเมื่อกลับมา
 * ช่วยประหยัดแบตมือถือลูกค้า และลดโหลดที่ไม่จำเป็นบนเซิร์ฟเวอร์
 *
 * ── ทำไมเป็น setTimeout ต่อกันเป็นทอด ๆ ไม่ใช่ setInterval ─────────────────
 * 1) รอบเวลาเปลี่ยนระหว่างทางได้ หน้าเดียวกันบางจังหวะต้องถามถี่ขึ้นชั่วคราว
 *    (เช่น ระหว่างนับถอยหลังส่งครัว) แล้วกลับไปช้าเหมือนเดิมเมื่อจบ
 *    setInterval ล็อกรอบไว้ตั้งแต่ตอนตั้ง ถ้าจะเปลี่ยนต้องรื้อ timer ใหม่ทุกครั้ง
 * 2) นับรอบถัดไปหลังคำขอก่อนหน้าจบแล้ว เน็ตมือถือช้ากว่ารอบเวลาจะได้ไม่มี
 *    คำขอกองซ้อนกันเป็นตับ ซึ่ง setInterval ทำแบบนั้นจริง ๆ เมื่อเจอเน็ตช้า
 */
export function usePolling<T>(
    url: string | (() => string),
    /** ตัวเลขคงที่ หรือฟังก์ชันที่อ่านใหม่ทุกครั้งก่อนตั้งรอบถัดไป */
    intervalMs: number | (() => number) = 8000,
    /** ปิดการดึงชั่วคราวได้ เช่น หน้าเดียวกันแต่ลูกค้าไม่ได้นั่งโต๊ะ จึงไม่มีบิลให้ดู */
    enabled: () => boolean = () => true,
) {
    const data = ref<T | null>(null)
    const error = ref<string | null>(null)
    const loading = ref(false)

    let timer: ReturnType<typeof setTimeout> | null = null

    /** ยังอยากให้ยิงต่อไหม — แยกจาก timer เพราะช่วงรอ response ไม่มี timer ค้างอยู่ */
    let running = false

    /** อ่าน url ทุกครั้งที่ยิง เพื่อให้ตัวกรองที่เปลี่ยนระหว่างทางมีผลทันที */
    function resolveUrl(): string {
        return typeof url === 'function' ? url() : url
    }

    /**
     * รอบถัดไปกี่มิลลิวินาที
     *
     * มีพื้นล่างกันไว้ที่ 500 เพราะถ้าผู้เรียกคำนวณพลาดจนได้ 0 หรือติดลบ
     * มือถือจะยิงรัวไม่หยุดจนเครื่องร้อนและโดน rate limit ของทั้งโต๊ะไปด้วย
     */
    function resolveInterval(): number {
        const ms = typeof intervalMs === 'function' ? intervalMs() : intervalMs

        return Number.isFinite(ms) && ms >= 500 ? ms : 500
    }

    async function fetchNow() {
        // เช็คทุกครั้งที่ยิง ไม่ใช่เช็คตอน start ครั้งเดียว
        // เงื่อนไขอาจเปลี่ยนระหว่างที่หน้ายังเปิดอยู่ แล้ว timer จะได้ไม่ต้องรื้อใหม่
        if (!enabled()) return

        loading.value = true

        try {
            const response = await fetch(resolveUrl(), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })

            if (!response.ok) throw new Error(`HTTP ${response.status}`)

            data.value = (await response.json()) as T
            error.value = null
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'เชื่อมต่อไม่สำเร็จ'
        } finally {
            loading.value = false
        }
    }

    function schedule() {
        clearTimer()

        if (!running) return

        timer = setTimeout(tick, resolveInterval())
    }

    async function tick() {
        timer = null

        await fetchNow()

        // เช็คซ้ำหลัง await — ระหว่างรอ response ผู้ใช้อาจสลับแท็บออกไปหรือปิดหน้าแล้ว
        schedule()
    }

    function start() {
        running = true
        schedule()
    }

    function stop() {
        running = false
        clearTimer()
    }

    function clearTimer() {
        if (timer) {
            clearTimeout(timer)
            timer = null
        }
    }

    function onVisibilityChange() {
        if (document.hidden) {
            stop()
            return
        }

        fetchNow()
        start()
    }

    onMounted(() => {
        fetchNow()
        start()
        document.addEventListener('visibilitychange', onVisibilityChange)
    })

    onBeforeUnmount(() => {
        stop()
        document.removeEventListener('visibilitychange', onVisibilityChange)
    })

    return { data, error, loading, fetchNow, start, stop }
}
