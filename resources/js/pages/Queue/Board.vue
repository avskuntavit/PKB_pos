<script setup lang="ts">
/**
 * จอแสดงคิวหน้าร้าน
 *
 * ออกแบบให้อ่านจากระยะ 3-5 เมตร: ตัวเลขใหญ่มาก คอนทราสต์สูง ไม่มีอะไรให้กด
 * ตั้งใจให้เปิดค้างบนทีวีหรือแท็บเล็ตที่เคาน์เตอร์
 *
 * ขนาดตัวอักษรใช้ clamp() อิง vw แทน breakpoint เป็นขั้น ๆ
 * เพราะร้านแขวนจออะไรก็ไม่รู้ — ทีวี 43" 1080p, ทีวี 65" 4K, หรือแท็บเล็ตวางเคาน์เตอร์
 * ถ้าล็อกขนาดเป็นขั้น จอที่อยู่ระหว่างขั้นจะเหลือที่ว่างหรือตัวล้นเสมอ
 * ส่วน min ของ clamp คุมไว้ไม่ให้เล็กเกินอ่านตอนเปิดบนแท็บเล็ตแนวตั้ง
 *
 * เสียงเตือน: เบราว์เซอร์ห้ามเล่นเสียงจนกว่าคนจะแตะหน้าจอก่อน (autoplay policy)
 * จึงมีปุ่ม "เปิดเสียง" ให้กดครั้งเดียวตอนติดตั้งจอ แล้วจำไว้ใน localStorage
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import { Volume2, VolumeX } from 'lucide-vue-next'
import { usePolling } from '@/composables/usePolling'

interface QueueRow {
    queue_number: number
    name: string | null
    ready_at: string | null
    called_at: string | null
    called_count: number
}

interface BoardData {
    business_date: string
    preparing: QueueRow[]
    ready: QueueRow[]
    missed: QueueRow[]
    last_called: { queue_number: number; at: string; count: number } | null
    fetched_at?: string
}

const props = defineProps<{
    branch: { code: string; name: string }
    board: BoardData
}>()

const { data: live } = usePolling<BoardData>(`/queue/${props.branch.code}/feed`, 5000)

const board = computed<BoardData>(() => live.value ?? props.board)

/* ---------- เสียงเตือน ---------- */

const STORAGE_KEY = 'foodpos.queue-board.sound'

const soundOn = ref(readStoredSound())

function readStoredSound(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1'
    } catch {
        return false
    }
}

let audio: AudioContext | null = null

function ensureAudio(): AudioContext | null {
    if (audio) return audio

    const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
    if (!Ctor) return null

    audio = new Ctor()
    return audio
}

/** ตี๊ด-ตี๊ด 2 จังหวะ — สั้นพอไม่กวน แต่ได้ยินข้ามเสียงร้าน */
function chime() {
    const ctx = ensureAudio()
    if (!ctx) return

    void ctx.resume()

    const now = ctx.currentTime
    const tones = [880, 1320]

    for (let index = 0; index < tones.length; index++) {
        const freq = tones[index]
        const at = now + index * 0.22
        const osc = ctx.createOscillator()
        const gain = ctx.createGain()

        osc.type = 'sine'
        osc.frequency.value = freq

        // ไต่ขึ้น-ลงนุ่ม ๆ กัน "ป๊อก" ตอนตัดสัญญาณ
        gain.gain.setValueAtTime(0.0001, at)
        gain.gain.exponentialRampToValueAtTime(0.3, at + 0.03)
        gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.2)

        osc.connect(gain).connect(ctx.destination)
        osc.start(at)
        osc.stop(at + 0.22)
    }
}

function toggleSound() {
    soundOn.value = !soundOn.value

    try {
        localStorage.setItem(STORAGE_KEY, soundOn.value ? '1' : '0')
    } catch {
        // โหมดส่วนตัวของเบราว์เซอร์เขียนไม่ได้ ก็ปล่อยไป ใช้ได้ถึงแค่ปิดหน้าจอ
    }

    // กดปุ่มนี้คือ user gesture — ปลดล็อกเสียงและให้ได้ยินว่าดังจริง
    if (soundOn.value) chime()
}

/* ---------- ไฮไลต์เลขที่เพิ่งถูกเรียก ---------- */

/** queue_number -> called_count ของรอบก่อน ใช้จับว่ามีการเรียกใหม่ */
const seen = new Map<number, number>()
let primed = false

const flashing = ref<Set<number>>(new Set())
const timers = new Map<number, ReturnType<typeof setTimeout>>()

function flash(queueNumber: number) {
    const next = new Set(flashing.value)
    next.add(queueNumber)
    flashing.value = next

    clearTimeout(timers.get(queueNumber))
    timers.set(
        queueNumber,
        setTimeout(() => {
            const after = new Set(flashing.value)
            after.delete(queueNumber)
            flashing.value = after
            timers.delete(queueNumber)
        }, 12000),
    )
}

watch(
    () => board.value.ready,
    (rows) => {
        let rang = false

        for (const row of rows) {
            if (row.called_count <= 0) continue

            const before = seen.get(row.queue_number)
            seen.set(row.queue_number, row.called_count)

            // รอบแรกหลังเปิดจอ แค่จำสถานะไว้ ไม่ต้องกรี๊ดย้อนหลังทั้งกระดาน
            if (!primed) continue

            if (before === undefined || row.called_count > before) {
                flash(row.queue_number)
                rang = true
            }
        }

        // ล้างเลขที่ออกจากแถวไปแล้ว ไม่งั้น Map โตไปเรื่อย ๆ ทั้งวัน
        const current = new Set(rows.map((r) => r.queue_number))
        for (const key of seen.keys()) {
            if (!current.has(key)) seen.delete(key)
        }

        if (rang && soundOn.value) chime()

        primed = true
    },
    { immediate: true, deep: true },
)

onBeforeUnmount(() => {
    for (const timer of timers.values()) clearTimeout(timer)
    void audio?.close()
})
</script>

<template>
    <Head :title="`คิว · ${branch.name}`" />

    <div class="flex h-dvh flex-col overflow-hidden bg-background">
        <header class="relative shrink-0 border-b px-4 py-3 text-center sm:px-6 xl:py-5">
            <h1 class="text-[clamp(1.25rem,2.4vw,3.5rem)] font-bold leading-tight">{{ branch.name }}</h1>
            <p class="text-[clamp(0.75rem,1.1vw,1.5rem)] text-muted-foreground">หมายเลขคิวรับอาหาร</p>

            <button
                type="button"
                class="absolute right-3 top-1/2 flex min-h-11 -translate-y-1/2 items-center gap-2 rounded-full border px-3 py-2 text-sm transition-colors sm:right-4 xl:px-5 xl:text-base"
                :class="
                    soundOn
                        ? 'border-[var(--status-good)] text-[var(--status-good)]'
                        : 'border-border text-muted-foreground hover:bg-accent'
                "
                :aria-pressed="soundOn"
                @click="toggleSound"
            >
                <component :is="soundOn ? Volume2 : VolumeX" class="size-4 xl:size-6" />
                <span class="hidden sm:inline">{{ soundOn ? 'เสียงเปิดอยู่' : 'เปิดเสียง' }}</span>
            </button>
        </header>

        <div class="grid min-h-0 flex-1 gap-px bg-border md:grid-cols-[1fr_1.2fr]">
            <!-- กำลังทำ -->
            <section class="flex min-h-0 flex-col overflow-y-auto bg-background p-4 sm:p-6 xl:p-10">
                <h2 class="mb-3 shrink-0 text-center text-[clamp(1rem,1.8vw,2.5rem)] font-semibold text-muted-foreground xl:mb-6">กำลังทำ</h2>

                <ul v-if="board.preparing.length" class="flex flex-wrap content-start justify-center gap-2 sm:gap-3 xl:gap-5">
                    <li
                        v-for="row in board.preparing"
                        :key="row.queue_number"
                        class="min-w-[clamp(5.5rem,9vw,12rem)] rounded-2xl border-2 px-3 py-2 text-center sm:px-5 sm:py-3"
                    >
                        <p class="tabular text-[clamp(2rem,4.5vw,6rem)] font-bold leading-none text-muted-foreground">{{ row.queue_number }}</p>
                        <p v-if="row.name" class="truncate text-[clamp(0.75rem,1.1vw,1.5rem)] text-muted-foreground">{{ row.name }}</p>
                    </li>
                </ul>
                <p v-else class="mt-8 text-center text-[clamp(0.9rem,1.3vw,1.75rem)] text-muted-foreground">ไม่มีคิวที่กำลังทำ</p>
            </section>

            <!-- พร้อมรับ -->
            <section class="flex min-h-0 flex-col overflow-y-auto bg-[var(--status-good)]/8 p-4 sm:p-6 xl:p-10">
                <h2 class="mb-3 shrink-0 text-center text-[clamp(1.1rem,2vw,3rem)] font-semibold text-[var(--status-good)] xl:mb-6">
                    พร้อมรับได้เลย
                </h2>

                <ul v-if="board.ready.length" class="flex flex-wrap content-start justify-center gap-2 sm:gap-3 xl:gap-6">
                    <li
                        v-for="row in board.ready"
                        :key="row.queue_number"
                        class="min-w-[clamp(7rem,13vw,20rem)] rounded-2xl border-4 px-4 py-3 text-center transition-all duration-300 sm:px-6 sm:py-4"
                        :class="
                            flashing.has(row.queue_number)
                                ? 'animate-queue-call border-[var(--status-good)] bg-[var(--status-good)] text-white shadow-lg'
                                : 'border-[var(--status-good)] bg-card'
                        "
                    >
                        <p
                            class="tabular text-[clamp(3rem,7.5vw,11rem)] font-bold leading-none"
                            :class="flashing.has(row.queue_number) ? 'text-white' : 'text-[var(--status-good)]'"
                        >
                            {{ row.queue_number }}
                        </p>
                        <p v-if="row.name" class="truncate text-[clamp(0.9rem,1.5vw,2.25rem)] font-medium">{{ row.name }}</p>
                        <p v-if="row.called_count > 1" class="text-[clamp(0.7rem,0.9vw,1.25rem)] opacity-80">
                            เรียกครั้งที่ {{ row.called_count }}
                        </p>
                    </li>
                </ul>
                <p v-else class="mt-8 text-center text-[clamp(0.9rem,1.3vw,1.75rem)] text-muted-foreground">ยังไม่มีคิวที่พร้อมรับ</p>

                <!-- เรียกแล้วยังไม่มา — ยังอยู่บนจอ แต่ถอยไปข้างหลัง -->
                <template v-if="board.missed.length">
                    <h3 class="mb-2 mt-6 text-center text-[clamp(0.8rem,1.1vw,1.5rem)] font-medium text-muted-foreground">
                        เรียกแล้ว ยังไม่มารับ
                    </h3>
                    <ul class="flex flex-wrap content-start justify-center gap-2">
                        <li
                            v-for="row in board.missed"
                            :key="row.queue_number"
                            class="tabular min-w-[clamp(3.5rem,5vw,7rem)] rounded-xl border border-dashed px-3 py-1.5 text-center text-[clamp(1.25rem,2.5vw,3.5rem)] font-semibold leading-tight text-muted-foreground"
                        >
                            {{ row.queue_number }}
                        </li>
                    </ul>
                </template>
            </section>
        </div>

        <footer class="shrink-0 border-t px-4 py-2 text-center text-[clamp(0.65rem,0.8vw,1.1rem)] text-muted-foreground sm:px-6">
            หน้าจอนี้อัพเดตเองทุก 5 วินาที
            <span v-if="!soundOn"> · กด "เปิดเสียง" มุมขวาบนเพื่อให้มีเสียงเตือนตอนเรียกคิว</span>
        </footer>
    </div>
</template>

<style scoped>
/* เต้นเบา ๆ 3 จังหวะแรกให้สะดุดตา แล้วนิ่ง เพราะกะพริบค้างจะกวนคนนั่งรอ */
@keyframes queue-call {
    0%,
    100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.08);
    }
}

.animate-queue-call {
    animation: queue-call 0.6s ease-in-out 3;
}

@media (prefers-reduced-motion: reduce) {
    .animate-queue-call {
        animation: none;
    }
}
</style>
