<script setup lang="ts">
/**
 * หน้าจัดการคิวของพนักงาน
 *
 * ต่างจากหน้าออเดอร์ล่วงหน้า: หน้านั้นคือ "รับออเดอร์นี้ไหม"
 * หน้านี้คือ "ของเสร็จแล้ว เรียกใครมารับ" — รวมคิวทุกช่องทางไว้แถวเดียว
 *
 * ปุ่มใหญ่ทั้งหมด เพราะพนักงานกดด้วยนิ้วเปียกขณะยืนอยู่หน้าเตา
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { BellRing, Check, Megaphone, SkipForward } from 'lucide-vue-next'
import PosLayout from '@/layouts/PosLayout.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { usePolling } from '@/composables/usePolling'
import { money } from '@/lib/format'

interface QueueRow {
    id: number
    queue_number: number
    order_no: string
    name: string | null
    phone: string | null
    source: string
    source_label: string
    status: string
    status_label: string
    item_count: number
    grand_total: number
    is_paid: boolean
    pickup_at: string | null
    called_at: string | null
    called_count: number
    skipped_at: string | null
}

interface BoardData {
    business_date: string
    waiting: QueueRow[]
    ready: QueueRow[]
    missed: QueueRow[]
    next_id: number | null
    fetched_at?: string
}

const props = defineProps<{
    branch: { code: string; name: string }
    board: BoardData
    recallAfterMinutes: number
}>()

const { data: live, fetchNow } = usePolling<BoardData>('/pos/queue/feed', 8000)

const board = computed<BoardData>(() => live.value ?? props.board)

type QueueAction = 'call' | 'skip' | 'complete'

/*
| คิวไหนกำลังทำอะไรอยู่ — เก็บเป็น "เลขบิล:การกระทำ"
|
| ที่ต้องแยกถึงระดับการกระทำ เพราะพนักงานต้องเห็นว่าตัวหมุนอยู่บนปุ่มไหน
| ถ้าเก็บแค่เลขบิล ปุ่มทั้งสามของแถวนั้นจะหมุนพร้อมกัน แล้วคนกดจะไม่รู้ว่า
| ตกลงตัวเองกดปุ่มไหนไป
|
| และที่เป็น Set ไม่ใช่ค่าเดียว เพราะคิวคนละแถวกดพร้อมกันได้
| เคาน์เตอร์จริงมีพนักงานสองคนยืนหน้าจอเดียวกันอยู่บ่อย ๆ
*/
const pending = ref(new Set<string>())
const callingNext = ref(false)

const taskKey = (id: number, action: QueueAction) => `${id}:${action}`

/** ปุ่มนี้กำลังทำงานอยู่ไหม — ใช้ตัดสินว่าจะโชว์ตัวหมุนแทนไอคอน */
const isRunning = (id: number, action: QueueAction) => pending.value.has(taskKey(id, action))

/** แถวนี้มีงานค้างอยู่ไหม — ปิดทุกปุ่มในแถวเดียวกัน กันสั่งชนกันเองบนบิลใบเดียว */
const rowBusy = (id: number) =>
    isRunning(id, 'call') || isRunning(id, 'skip') || isRunning(id, 'complete')

/** มีอะไรค้างอยู่บ้างไหม — ใช้ขึ้นแถบบอกสถานะด้านบนสุด */
const anyBusy = computed(() => callingNext.value || pending.value.size > 0)

/** นาฬิกาเดินเอง เพื่อให้ป้าย "เรียกไป X นาที" ขยับโดยไม่ต้องรอ polling */
const now = ref(Date.now())
const clock = setInterval(() => (now.value = Date.now()), 15000)

onBeforeUnmount(() => clearInterval(clock))

function minutesSince(iso: string | null): number | null {
    if (!iso) return null

    return Math.max(0, Math.floor((now.value - new Date(iso).getTime()) / 60000))
}

/** เรียกไปนานแล้วยังไม่มา — ขึ้นสีเตือนให้พนักงานเรียกซ้ำ */
function isStale(row: QueueRow): boolean {
    const mins = minutesSince(row.called_at)

    return mins !== null && mins >= props.recallAfterMinutes
}

function act(row: QueueRow, action: QueueAction) {
    const key = taskKey(row.id, action)

    // ด่านแรกของการกันกดซ้ำ — กดรัวสิบครั้งก็ยิงไปครั้งเดียว
    if (pending.value.has(key)) return

    pending.value.add(key)

    router.post(
        `/pos/queue/${row.id}/${action}`,
        {},
        {
            preserveScroll: true,
            /*
            | ต้องคงสถานะไว้ ไม่งั้น Inertia จะสร้างหน้านี้ใหม่ทั้งหน้า
            | ตัวหมุนจะหายตั้งแต่ก่อนงานเสร็จ และตัวนับ polling จะถูกรีเซ็ตทุกครั้งที่กดปุ่ม
            */
            preserveState: true,
            onFinish: () => {
                /*
                | ดึงกระดานใหม่ก่อน แล้วค่อยปลดตัวหมุน
                |
                | ถ้าปลดก่อน พนักงานจะเห็นปุ่มกลับมากดได้ทั้งที่รายการยังเป็นของเก่า
                | แล้วจะกดซ้ำเพราะคิดว่าครั้งแรกไม่ติด
                */
                void fetchNow().finally(() => pending.value.delete(key))
            },
        },
    )
}

function callNext() {
    // ปุ่มนี้อันตรายที่สุดเวลากดซ้ำ เพราะกดสองทีคือเรียกลูกค้าคนละคนสองคิวรวด
    if (callingNext.value) return

    callingNext.value = true

    router.post(
        '/pos/queue/next',
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                void fetchNow().finally(() => (callingNext.value = false))
            },
        },
    )
}

const hasNext = computed(() => board.value.next_id !== null)

/** เปิดจอแสดงคิวในแท็บใหม่ ให้พนักงานลากไปจอที่สองได้ */
const boardUrl = computed(() => `/queue/${props.branch.code}`)

/*
| เมื่อก่อนมี watch คอยล้างสถานะ busy ตอนจำนวนคิวเปลี่ยน — เอาออกแล้ว
| เพราะ polling ทุก 8 วินาทีทำให้จำนวนเปลี่ยนได้เองระหว่างที่คำสั่งยังไม่เสร็จ
| ปุ่มจึงกลับมากดได้ทั้งที่งานยังค้างอยู่ ซึ่งตรงข้ามกับที่ต้องการ
| ตอนนี้แต่ละคำสั่งปลดตัวเองใน onFinish จึงไม่ต้องมีคนมาล้างให้
*/
</script>

<template>
    <Head title="จัดการคิว" />

    <PosLayout title="จัดการคิว">
        <div class="flex h-full flex-col">
            <!-- แถบบนสุด: ปุ่มหลักที่ใช้บ่อยที่สุด -->
            <div class="relative flex shrink-0 flex-wrap items-center gap-2 border-b bg-card px-3 py-3 sm:gap-3 sm:px-4">
                <!--
                    เส้นวิ่งบอกว่าระบบกำลังคุยกับเซิร์ฟเวอร์อยู่

                    อยู่ขอบบนสุดเพราะเห็นได้โดยไม่ต้องมองหา ต่อให้พนักงานกำลังจ้องปุ่มอื่นอยู่
                    และไม่กินพื้นที่ เลย์เอาต์จึงไม่ขยับตอนมันโผล่มา ซึ่งสำคัญมากกับจอสัมผัส
                    เพราะถ้าปุ่มขยับหนีตอนกำลังจะกด นิ้วจะไปโดนปุ่มอื่นแทน
                -->
                <div
                    v-if="anyBusy"
                    class="pointer-events-none absolute inset-x-0 top-0 h-0.5 overflow-hidden"
                    role="status"
                    aria-label="กำลังบันทึก"
                >
                    <span class="queue-progress block h-full w-1/4 bg-[var(--series-1)]" />
                </div>

                <Button
                    variant="brand"
                    size="xl"
                    class="w-full sm:w-auto sm:flex-none"
                    :disabled="!hasNext || callingNext"
                    :aria-busy="callingNext"
                    @click="callNext"
                >
                    <Spinner v-if="callingNext" class="size-5" />
                    <Megaphone v-else class="size-5" />
                    {{ callingNext ? 'กำลังเรียก…' : 'เรียกคิวถัดไป' }}
                </Button>

                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <span><span class="tabular font-semibold">{{ board.waiting.length }}</span> กำลังทำ</span>
                    <span class="text-[var(--status-good)]">
                        <span class="tabular font-semibold">{{ board.ready.length }}</span> รอมารับ
                    </span>
                    <span v-if="board.missed.length" class="text-[var(--status-warning)]">
                        <span class="tabular font-semibold">{{ board.missed.length }}</span> ไม่มา
                    </span>
                </div>

                <a
                    :href="boardUrl"
                    target="_blank"
                    rel="noopener"
                    class="ml-auto rounded-md border px-3 py-2 text-sm text-muted-foreground hover:bg-accent"
                >
                    เปิดจอแสดงคิว
                </a>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-3 sm:p-4">
                <div class="mx-auto grid max-w-3xl gap-4 lg:max-w-6xl lg:grid-cols-2 2xl:max-w-none">
                    <!-- พร้อมรับ -->
                    <section class="space-y-2">
                        <h2 class="text-sm font-semibold text-[var(--status-good)]">
                            ของพร้อมแล้ว — รอลูกค้ามารับ
                        </h2>

                        <p v-if="!board.ready.length && !board.missed.length" class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            ยังไม่มีคิวที่ของพร้อม
                        </p>

                        <article
                            v-for="row in board.ready"
                            :key="row.id"
                            class="rounded-xl border-2 bg-card p-3"
                            :class="isStale(row) ? 'border-[var(--status-warning)]' : 'border-[var(--status-good)]'"
                        >
                            <div class="flex items-start gap-3">
                                <p class="tabular w-14 shrink-0 text-center text-4xl font-bold leading-none text-[var(--status-good)] sm:w-16 xl:text-5xl">
                                    {{ row.queue_number }}
                                </p>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium">{{ row.name ?? 'ไม่ระบุชื่อ' }}</p>
                                    <p class="truncate text-xs text-muted-foreground">
                                        {{ row.order_no }} · {{ row.source_label }} · {{ row.item_count }} รายการ
                                        · {{ money(row.grand_total) }} ฿
                                        <span v-if="!row.is_paid" class="text-[var(--status-warning)]">· ยังไม่จ่าย</span>
                                    </p>
                                    <p v-if="row.called_at" class="text-xs" :class="isStale(row) ? 'text-[var(--status-warning)]' : 'text-muted-foreground'">
                                        เรียกไปแล้ว {{ minutesSince(row.called_at) }} นาที
                                        <span v-if="row.called_count > 1">(ครั้งที่ {{ row.called_count }})</span>
                                    </p>
                                    <p v-else class="text-xs text-muted-foreground">ยังไม่ได้เรียก</p>
                                </div>
                            </div>

                            <div class="mt-3 flex gap-2">
                                <Button
                                    :variant="row.called_at ? 'outline' : 'brand'"
                                    size="lg"
                                    class="flex-1"
                                    :disabled="rowBusy(row.id)"
                                    :aria-busy="isRunning(row.id, 'call')"
                                    @click="act(row, 'call')"
                                >
                                    <Spinner v-if="isRunning(row.id, 'call')" class="size-4" />
                                    <BellRing v-else class="size-4" />
                                    <template v-if="isRunning(row.id, 'call')">กำลังเรียก…</template>
                                    <template v-else>{{ row.called_at ? 'เรียกซ้ำ' : 'เรียกคิว' }}</template>
                                </Button>
                                <Button
                                    variant="outline"
                                    size="lg"
                                    :disabled="rowBusy(row.id)"
                                    :aria-busy="isRunning(row.id, 'skip')"
                                    aria-label="เรียกแล้วไม่มา"
                                    @click="act(row, 'skip')"
                                >
                                    <Spinner v-if="isRunning(row.id, 'skip')" class="size-4" />
                                    <SkipForward v-else class="size-4" />
                                </Button>
                                <Button
                                    variant="outline"
                                    size="lg"
                                    class="flex-1"
                                    :disabled="rowBusy(row.id)"
                                    :aria-busy="isRunning(row.id, 'complete')"
                                    @click="act(row, 'complete')"
                                >
                                    <Spinner v-if="isRunning(row.id, 'complete')" class="size-4" />
                                    <Check v-else class="size-4" />
                                    {{ isRunning(row.id, 'complete') ? 'กำลังบันทึก…' : 'ส่งของแล้ว' }}
                                </Button>
                            </div>
                        </article>

                        <!-- เรียกแล้วไม่มา -->
                        <template v-if="board.missed.length">
                            <h3 class="pt-2 text-sm font-semibold text-[var(--status-warning)]">
                                เรียกแล้วยังไม่มารับ
                            </h3>

                            <article
                                v-for="row in board.missed"
                                :key="row.id"
                                class="flex items-center gap-3 rounded-xl border border-dashed bg-card p-3"
                            >
                                <p class="tabular w-16 shrink-0 text-center text-2xl font-bold text-muted-foreground">
                                    {{ row.queue_number }}
                                </p>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm">{{ row.name ?? 'ไม่ระบุชื่อ' }}</p>
                                    <p class="truncate text-xs text-muted-foreground">
                                        {{ row.order_no }}
                                        <span v-if="row.phone"> · {{ row.phone }}</span>
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="rowBusy(row.id)"
                                    :aria-busy="isRunning(row.id, 'call')"
                                    @click="act(row, 'call')"
                                >
                                    <Spinner v-if="isRunning(row.id, 'call')" class="size-3.5" />
                                    เรียกซ้ำ
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="rowBusy(row.id)"
                                    :aria-busy="isRunning(row.id, 'complete')"
                                    @click="act(row, 'complete')"
                                >
                                    <Spinner v-if="isRunning(row.id, 'complete')" class="size-3.5" />
                                    ส่งของแล้ว
                                </Button>
                            </article>
                        </template>
                    </section>

                    <!-- กำลังทำ -->
                    <section class="space-y-2">
                        <h2 class="text-sm font-semibold text-muted-foreground">กำลังทำอยู่</h2>

                        <p v-if="!board.waiting.length" class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            ไม่มีคิวค้างในครัว
                        </p>

                        <article
                            v-for="row in board.waiting"
                            :key="row.id"
                            class="flex items-center gap-3 rounded-xl border bg-card p-3"
                        >
                            <p class="tabular w-16 shrink-0 text-center text-2xl font-bold text-muted-foreground">
                                {{ row.queue_number }}
                            </p>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ row.name ?? 'ไม่ระบุชื่อ' }}</p>
                                <p class="truncate text-xs text-muted-foreground">
                                    {{ row.order_no }} · {{ row.source_label }} · {{ row.item_count }} รายการ
                                </p>
                                <p class="text-xs text-muted-foreground">{{ row.status_label }}</p>
                            </div>

                            <Button
                                variant="outline"
                                size="lg"
                                :disabled="rowBusy(row.id)"
                                :aria-busy="isRunning(row.id, 'call')"
                                @click="act(row, 'call')"
                            >
                                <Spinner v-if="isRunning(row.id, 'call')" class="size-4" />
                                <BellRing v-else class="size-4" />
                                {{ isRunning(row.id, 'call') ? 'กำลังบันทึก…' : 'พร้อมแล้ว' }}
                            </Button>
                        </article>
                    </section>
                </div>
            </div>
        </div>
    </PosLayout>
</template>

<style scoped>
/*
 * เส้นวิ่งแบบไม่รู้เปอร์เซ็นต์ — เรารู้แค่ว่า "ยังไม่เสร็จ" ไม่รู้ว่าเหลืออีกเท่าไหร่
 * จึงวิ่งไปเรื่อย ๆ แทนที่จะโกหกด้วยแถบที่เติมจาก 0 ถึง 100
 */
@keyframes queue-progress {
    from { transform: translateX(-100%); }
    to   { transform: translateX(400%); }
}

.queue-progress {
    animation: queue-progress 1.1s ease-in-out infinite;
}

/* เครื่องที่ตั้งลดการเคลื่อนไหวไว้ ใช้จางเข้าจางออกแทนการวิ่ง */
@media (prefers-reduced-motion: reduce) {
    @keyframes queue-progress-fade {
        0%, 100% { opacity: 0.35; }
        50%      { opacity: 1; }
    }

    .queue-progress {
        width: 100%;
        animation: queue-progress-fade 1.6s ease-in-out infinite;
    }
}
</style>
