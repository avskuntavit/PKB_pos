import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * เรียก endpoint ซ้ำตามรอบเวลา
 *
 * หยุดเองเมื่อผู้ใช้สลับแท็บออกไป แล้วดึงข้อมูลทันทีเมื่อกลับมา
 * ช่วยประหยัดแบตมือถือลูกค้า และลดโหลดที่ไม่จำเป็นบนเซิร์ฟเวอร์
 */
export function usePolling<T>(
    url: string | (() => string),
    intervalMs = 8000,
    /** ปิดการดึงชั่วคราวได้ เช่น หน้าเดียวกันแต่ลูกค้าไม่ได้นั่งโต๊ะ จึงไม่มีบิลให้ดู */
    enabled: () => boolean = () => true,
) {
    const data = ref<T | null>(null)
    const error = ref<string | null>(null)
    const loading = ref(false)

    let timer: ReturnType<typeof setInterval> | null = null

    /** อ่าน url ทุกครั้งที่ยิง เพื่อให้ตัวกรองที่เปลี่ยนระหว่างทางมีผลทันที */
    function resolveUrl(): string {
        return typeof url === 'function' ? url() : url
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

    function start() {
        stop()
        timer = setInterval(fetchNow, intervalMs)
    }

    function stop() {
        if (timer) {
            clearInterval(timer)
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
