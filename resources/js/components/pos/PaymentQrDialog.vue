<script setup lang="ts">
/**
 * QR รับเงินที่เคาน์เตอร์
 *
 * ── สองโหมดที่ต่างกันจริง ๆ ไม่ใช่แค่ข้อความ ──────────────────────────
 * ผ่านเกตเวย์  ระบบถามเองว่าเงินเข้าหรือยัง หน้าจอขึ้นเองเมื่อเข้าแล้ว
 * QR ของร้าน   ระบบตรวจไม่ได้เลย พนักงานต้องดูสลิปในแอปธนาคารเอง
 *
 * โหมดที่สองต้อง **บอกตรง ๆ** ไม่ใช่โชว์วงกลมหมุนค้างไว้ให้เข้าใจว่ากำลังตรวจอยู่
 * การทำให้คนเชื่อว่าระบบกำลังตรวจเงินให้ ทั้งที่ไม่ได้ตรวจ คือการโกหกเรื่องเงิน
 *
 * ── เงินเข้าแล้วยังไม่ปิดบิลให้เอง ───────────────────────────────────
 * บิลหน้าเคาน์เตอร์ต้องให้พนักงานกดปิดเอง เพราะโต๊ะข้างกันอาจถูกปิดผิดใบ
 * และการแก้บิลที่ปิดไปแล้วยากกว่าการกดปุ่มเพิ่มหนึ่งครั้งมาก
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import QRCode from 'qrcode'
import { BadgeCheck, Ban, CircleAlert, Eye, Loader2, RefreshCw, WifiOff } from 'lucide-vue-next'
import Modal from '@/components/ui/Modal.vue'
import Button from '@/components/ui/Button.vue'
import { money } from '@/lib/format'
import type { Order, PaymentCharge } from '@/types'

const props = defineProps<{ order: Order; charge: PaymentCharge | null }>()
const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
    /** สถานะเปลี่ยน — หน้าแม่เก็บไว้เพื่อให้ปุ่มบนหน้าหลักตรงกับความจริง */
    (e: 'update:charge', charge: PaymentCharge | null): void
    /** พนักงานกดปิดบิล — หน้าแม่เปิดหน้าต่างชำระเงินพร้อมเติมยอดให้ */
    (e: 'settle', charge: PaymentCharge): void
}>()

const charge = computed(() => props.charge)
const busy = ref(false)
const error = ref<string | null>(null)

/** ถามเกตเวย์ไม่ได้ชั่วคราว — ต่างจาก "เงินยังไม่เข้า" คนละเรื่องกัน */
const stale = ref(false)

/* ---------- วาด QR ---------- */

const qrDataUrl = ref('')
const qrFailed = ref(false)

async function renderQr(payload: string | null) {
    if (!payload) {
        qrDataUrl.value = ''
        return
    }

    try {
        qrDataUrl.value = await QRCode.toDataURL(payload, {
            width: 560,
            margin: 1,
            errorCorrectionLevel: 'M',
            color: { dark: '#000000', light: '#ffffff' },
        })
        qrFailed.value = false
    } catch {
        qrFailed.value = true
    }
}

watch(() => charge.value?.qr_payload ?? null, renderQr, { immediate: true })

/* ---------- นับถอยหลัง ---------- */

const now = ref(Date.now())
let clock: ReturnType<typeof setInterval> | null = null

const expiresAt = computed(() => {
    const iso = charge.value?.expires_at
    return iso ? new Date(iso).getTime() : null
})

const secondsLeft = computed(() => {
    if (!expiresAt.value) return null
    return Math.max(0, Math.floor((expiresAt.value - now.value) / 1000))
})

const countdown = computed(() => {
    const left = secondsLeft.value
    if (left === null) return null
    return `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`
})

/* ---------- สถานะ ---------- */

const isPending = computed(() => charge.value?.status === 'pending')
const isPaid = computed(() => charge.value?.status === 'paid')
const needsDecision = computed(
    () => charge.value?.status === 'mismatch' || charge.value?.status === 'unmatched',
)
const isDead = computed(
    () => charge.value?.status === 'expired'
        || charge.value?.status === 'failed'
        || charge.value?.status === 'cancelled',
)

/** ระบบตรวจเงินให้ได้ไหม — QR ของร้านเองตรวจไม่ได้ ต้องบอกให้ชัด */
const verifies = computed(() => charge.value?.verifies_automatically === true)

/* ---------- คุยกับเซิร์ฟเวอร์ ---------- */

/**
 * โทเคนกัน CSRF ที่ Laravel ฝังมาให้ในคุกกี้
 *
 * แอปนี้ไม่มี meta[name="csrf-token"] ในหน้า — ตัวที่ใช้จริงคือคุกกี้ XSRF-TOKEN
 * กับเฮดเดอร์ X-XSRF-TOKEN (แบบเดียวกับ useTableCart / useOfflineQueue)
 * อ่านผิดที่จะได้ 419 ทุกครั้งที่กดออก QR โดยไม่มีอะไรบอกว่าเพราะอะไร
 */
function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

    return match ? decodeURIComponent(match[1]) : ''
}

async function call(url: string, method: 'POST' | 'GET' | 'DELETE'): Promise<PaymentCharge | null> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
    })

    const body = await response.json().catch(() => ({}))

    if (!response.ok) {
        throw new Error(body.message ?? `ติดต่อเซิร์ฟเวอร์ไม่สำเร็จ (${response.status})`)
    }

    stale.value = body.stale === true

    return (body.charge ?? null) as PaymentCharge | null
}

async function openCharge() {
    if (busy.value) return

    busy.value = true
    error.value = null

    try {
        emit('update:charge', await call(`/pos/orders/${props.order.id}/charge`, 'POST'))
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'ออก QR ไม่สำเร็จ'
    } finally {
        busy.value = false
    }
}

async function refresh() {
    const current = charge.value
    if (!current || busy.value) return

    try {
        emit('update:charge', await call(`/pos/charges/${current.id}`, 'GET'))
        error.value = null
    } catch {
        // ถามไม่ได้รอบเดียวไม่ใช่เรื่องใหญ่ รอบถัดไปจะถามใหม่เอง
        // ไม่ตั้ง error เพราะข้อความแดงที่กะพริบทุกไม่กี่วินาทีทำให้คนเลิกอ่าน
        stale.value = true
    }
}

async function cancel() {
    const current = charge.value
    if (!current || busy.value) return

    busy.value = true
    error.value = null

    try {
        emit('update:charge', await call(`/pos/charges/${current.id}`, 'DELETE'))
        open.value = false
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'ยกเลิกไม่สำเร็จ'
    } finally {
        busy.value = false
    }
}

/* ---------- จังหวะถาม ---------- */

/*
| ถามทุก 3 วินาทีขณะหน้าต่างเปิดอยู่
|
| เพดานที่ป้องกันเกตเวย์ไม่ได้อยู่ตรงนี้ แต่อยู่ฝั่งเซิร์ฟเวอร์
| (PollSchedule::SCREEN_FLOOR_SECONDS) ซึ่งถามเกตเวย์ไม่เกิน 5 วินาทีต่อครั้ง
| คำขอที่มาเร็วกว่านั้นได้สถานะล่าสุดจากฐานข้อมูลไป — ถูกและถูกกว่า
| ตัวเลขตรงนี้จึงเลือกจาก "ลูกค้ายืนรออยู่" ไม่ใช่จากข้อจำกัดของเกตเวย์
*/
const POLL_MS = 3000

let timer: ReturnType<typeof setTimeout> | null = null

function stopPolling() {
    if (timer) {
        clearTimeout(timer)
        timer = null
    }
}

function schedulePoll() {
    stopPolling()

    // ถามต่อเฉพาะตอนที่คำตอบยังเปลี่ยนได้ และเฉพาะเจ้าที่ตอบได้จริง
    if (!open.value || !isPending.value || !verifies.value) return

    timer = setTimeout(async () => {
        timer = null
        await refresh()
        schedulePoll()
    }, POLL_MS)
}

watch([open, isPending, verifies], () => {
    if (open.value) {
        schedulePoll()
    } else {
        stopPolling()
    }
})

function stopClock() {
    if (clock) {
        clearInterval(clock)
        clock = null
    }
}

watch(open, (value) => {
    if (!value) {
        stopClock()
        error.value = null
        stale.value = false

        return
    }

    now.value = Date.now()
    stopClock()
    clock = setInterval(() => (now.value = Date.now()), 1000)

    // ยังไม่มีใบ = เพิ่งกดปุ่มโชว์ QR ครั้งแรกของบิลนี้
    if (!charge.value) void openCharge()
})

onBeforeUnmount(() => {
    stopPolling()
    stopClock()
})
</script>

<template>
    <Modal v-model:open="open" title="รับเงินโอน (QR)" class="max-w-md">
        <div class="space-y-4">
            <div class="rounded-lg border bg-muted/40 p-3 text-center">
                <p class="text-xs text-muted-foreground">ยอดที่ต้องชำระ</p>
                <p class="tabular text-3xl font-semibold">{{ money(order.grand_total) }} บาท</p>
            </div>

            <p
                v-if="error"
                class="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0 text-destructive" />
                <span>{{ error }}</span>
            </p>

            <!-- กำลังออก QR -->
            <div v-if="busy && !charge" class="grid place-items-center gap-2 py-10 text-sm text-muted-foreground">
                <Loader2 class="size-6 animate-spin" />
                กำลังออก QR…
            </div>

            <!-- เงินเข้าครบแล้ว รอพนักงานกดปิดบิล -->
            <div
                v-else-if="isPaid"
                class="space-y-3 rounded-xl border border-[var(--series-2)]/40 bg-[var(--series-2)]/10 p-5 text-center"
            >
                <BadgeCheck class="mx-auto size-10 text-[var(--series-2)]" />
                <div>
                    <p class="text-lg font-semibold">เงินเข้าแล้ว {{ money(charge!.paid_amount ?? charge!.amount) }} บาท</p>
                    <p class="text-sm text-muted-foreground">ผ่าน {{ charge!.provider_label }}</p>
                </div>
                <p class="text-xs text-muted-foreground">
                    บิลยังไม่ถูกปิด — กดปิดบิลเพื่อออกใบเสร็จ
                </p>
                <Button size="lg" class="w-full" @click="emit('settle', charge!)">
                    ปิดบิลและออกใบเสร็จ
                </Button>
            </div>

            <!-- เงินเข้าแล้วแต่ลงบิลอัตโนมัติไม่ได้ -->
            <div
                v-else-if="needsDecision"
                class="space-y-2 rounded-xl border border-amber-500/40 bg-amber-500/10 p-5 text-center"
            >
                <CircleAlert class="mx-auto size-9 text-amber-600" />
                <p class="font-semibold">{{ charge!.status_label }}</p>
                <p class="text-sm">
                    เงินเข้ามาแล้ว {{ money(charge!.paid_amount ?? 0) }} บาท
                    แต่ยอดไม่ตรงกับบิลนี้ ({{ money(charge!.amount) }} บาท)
                </p>
                <p class="text-xs text-muted-foreground">
                    เงินก้อนนี้ไม่ได้หายไปไหน — ผู้จัดการจะเห็นในรายการที่รอตัดสิน
                    กรุณาแจ้งผู้จัดการก่อนปิดบิลด้วยวิธีอื่น
                </p>
            </div>

            <!-- ใบนี้จบแล้วโดยไม่มีเงินเข้า -->
            <div v-else-if="isDead" class="space-y-3 rounded-xl border p-5 text-center">
                <Ban class="mx-auto size-9 text-muted-foreground" />
                <p class="font-semibold">{{ charge!.status_label }}</p>
                <p v-if="charge!.note" class="text-sm text-muted-foreground">
                    {{ charge!.note }}
                </p>
                <Button variant="outline" class="w-full" :disabled="busy" @click="openCharge">
                    <RefreshCw class="size-4" />
                    ออก QR ใบใหม่
                </Button>
            </div>

            <!-- รอลูกค้าสแกน -->
            <div v-else-if="charge?.qr_payload" class="space-y-3">
                <div class="flex flex-col items-center gap-2 rounded-xl border bg-white p-4 text-black">
                    <img
                        v-if="qrDataUrl"
                        :src="qrDataUrl"
                        alt="QR สำหรับให้ลูกค้าสแกนจ่าย"
                        class="size-60"
                    />
                    <div
                        v-else-if="qrFailed"
                        class="grid size-60 place-items-center border border-dashed text-center text-xs text-neutral-500"
                    >
                        วาด QR ไม่สำเร็จ<br />กรุณาเก็บเงินด้วยวิธีอื่น
                    </div>
                    <div v-else class="size-60 animate-pulse bg-neutral-100" />

                    <p class="tabular text-xl font-bold">{{ money(charge.amount) }} ฿</p>
                </div>

                <!-- ระบบตรวจเงินให้ได้ -->
                <div
                    v-if="verifies"
                    class="flex items-center justify-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm"
                >
                    <Loader2 class="size-4 animate-spin text-muted-foreground" />
                    <span>รอเงินเข้า… ระบบจะขึ้นเองเมื่อได้รับ</span>
                </div>

                <!-- ระบบตรวจให้ไม่ได้ — ต้องบอกตรง ๆ ไม่ใช่โชว์วงหมุนหลอก -->
                <div
                    v-else
                    class="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm"
                >
                    <Eye class="mt-0.5 size-4 shrink-0 text-amber-600" />
                    <span>
                        <strong>ระบบตรวจยอดให้ไม่ได้</strong> — สาขานี้ใช้ QR พร้อมเพย์ของร้านเอง
                        <span class="block text-xs text-muted-foreground">
                            กรุณาดูสลิปหรือแอปธนาคารเอง แล้วกดปิดบิลตามปกติ
                        </span>
                    </span>
                </div>

                <p
                    v-if="stale"
                    class="flex items-center justify-center gap-2 text-xs text-muted-foreground"
                >
                    <WifiOff class="size-3.5" />
                    ถามเกตเวย์ไม่ได้ชั่วคราว — กำลังลองใหม่
                </p>

                <p v-if="countdown" class="text-center text-xs text-muted-foreground">
                    QR หมดอายุใน <span class="tabular font-medium">{{ countdown }}</span>
                </p>

                <Button variant="outline" class="w-full" :disabled="busy" @click="cancel">
                    ยกเลิก QR (ลูกค้าเปลี่ยนไปจ่ายวิธีอื่น)
                </Button>
            </div>
        </div>
    </Modal>
</template>
