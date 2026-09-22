<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { CircleAlert, Phone, Star, Store, Utensils } from 'lucide-vue-next'
import OrderStepper from '@/components/storefront/OrderStepper.vue'
import ReviewSheet from '@/components/storefront/ReviewSheet.vue'
import StarRating from '@/components/storefront/StarRating.vue'
import PromptPayQr from '@/components/storefront/PromptPayQr.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import { usePolling } from '@/composables/usePolling'
import { dateTime, money, number, time } from '@/lib/format'
import type { PageProps } from '@/types'

interface TrackPayload {
    order_no: string
    queue_number: number | null
    queue_ahead: number | null
    queue_wait_minutes: number | null
    has_review: boolean
    my_rating: number | null
    status: string
    status_label: string
    status_hint: string
    step_index: number
    is_finished: boolean
    can_cancel: boolean
    reject_reason: string | null
    type: string
    type_label: string
    table: string | null
    pickup_at: string | null
    payment_intent: string | null
    payment_intent_label: string | null
    is_paid: boolean
    items: Array<{
        id: number
        name: string
        qty: number
        line_total: number
        modifiers: string[]
        note: string | null
    }>
    totals: { subtotal: number; service_charge: number; tax_amount: number; grand_total: number }
    timeline: Array<{ status: string; label: string; note: string | null; at: string | null }>
}

const props = defineProps<{
    token: string
    branch: { name: string; phone: string | null; address: string | null; code: string }
    order: TrackPayload
    promptPayPayload: string | null
}>()

const page = usePage<PageProps>()
const showCancel = ref(false)
const showReview = ref(false)

// สถานะขยับเองทุก 10 วินาที ลูกค้าไม่ต้องกดรีเฟรชรอ
const { data: live } = usePolling<TrackPayload>(`/order/track/${props.token}/status`, 10000)

const order = computed<TrackPayload>(() => live.value ?? props.order)

const failed = computed(() => ['rejected', 'cancelled'].includes(order.value.status))

/**
 * บอกเวลารอเป็นช่วง ไม่ใช่ตัวเลขเป๊ะ ๆ
 *
 * ตัวเลขเดี่ยวทำให้ลูกค้าจับผิดได้ทันทีที่คลาดไป 1 นาที
 * ส่วนช่วงเวลาสื่อตรง ๆ ว่าเป็นการประมาณ และร้านยังมีที่ให้หายใจ
 */
const waitRange = computed(() => {
    const minutes = order.value.queue_wait_minutes

    if (!minutes || minutes <= 0) return null

    return `${minutes}-${minutes + 5} นาที`
})

const isGovernmentScheme = computed(() =>
    ['khon_la_khrueng', 'thai_chuay_thai'].includes(order.value.payment_intent ?? ''),
)

function cancelOrder() {
    router.post(`/order/track/${props.token}/cancel`, {}, {
        preserveScroll: true,
        onSuccess: () => (showCancel.value = false),
    })
}
</script>

<template>
    <Head :title="`ติดตามออเดอร์ ${order.order_no}`" />

    <div class="min-h-dvh bg-muted/40 pb-10">
        <header class="border-b bg-card">
            <div class="mx-auto max-w-2xl px-4 py-3">
                <p class="text-sm font-semibold">{{ branch.name }}</p>
                <p class="text-xs text-muted-foreground">ออเดอร์ {{ order.order_no }}</p>
            </div>
        </header>

        <div
            v-if="page.props.flash.error"
            class="border-b border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 px-4 py-2 text-center text-sm text-[var(--status-critical)]"
        >
            {{ page.props.flash.error }}
        </div>

        <div class="mx-auto max-w-2xl space-y-4 p-4">
            <!-- สถานะ -->
            <section class="rounded-xl border bg-card p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p
                            class="text-lg font-bold"
                            :class="failed ? 'text-[var(--status-critical)]' : ''"
                        >
                            {{ order.status_label }}
                        </p>
                        <p class="text-sm text-muted-foreground">{{ order.status_hint }}</p>
                    </div>

                    <Badge :variant="order.type === 'dine_in' ? 'secondary' : 'outline'" class="shrink-0">
                        <component :is="order.type === 'dine_in' ? Utensils : Store" class="mr-1 size-3" />
                        {{ order.type_label }}
                    </Badge>
                </div>

                <!-- เลขคิว — สิ่งที่ลูกค้าต้องจำเวลามารับ -->
                <div
                    v-if="order.queue_number && !failed"
                    class="mt-3 flex items-center gap-4 rounded-xl border-2 border-[var(--series-1)] bg-[var(--series-1)]/8 px-4 py-3"
                >
                    <div class="text-center">
                        <p class="text-xs text-muted-foreground">คิวของคุณ</p>
                        <p class="tabular text-4xl font-bold text-[var(--series-1)]">{{ order.queue_number }}</p>
                    </div>
                    <div class="min-w-0 text-sm">
                        <p v-if="order.status === 'ready'" class="font-medium text-[var(--status-good)]">
                            พร้อมรับแล้ว แจ้งเลขคิวที่เคาน์เตอร์ได้เลย
                        </p>
                        <template v-else>
                            <!-- เวลารอ ประเมินจากจังหวะที่ร้านทำเสร็จจริงวันนี้ บอกเป็นช่วงเพราะเดาเป๊ะไม่ได้ -->
                            <p v-if="waitRange" class="font-medium">รออีกประมาณ {{ waitRange }}</p>
                            <p v-if="order.queue_ahead && order.queue_ahead > 0" class="text-muted-foreground">
                                มีอีก {{ order.queue_ahead }} คิวก่อนหน้าคุณ
                            </p>
                            <p v-else-if="!waitRange" class="text-muted-foreground">คิวของคุณกำลังถูกเตรียม</p>
                        </template>
                    </div>
                </div>

                <div class="mt-4">
                    <OrderStepper
                        :step-index="order.step_index"
                        :is-finished="order.is_finished"
                        :failed="failed"
                    />
                </div>

                <p
                    v-if="order.reject_reason"
                    class="mt-3 flex items-start gap-2 rounded-lg border border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 px-3 py-2 text-xs text-[var(--status-critical)]"
                >
                    <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                    เหตุผลจากร้าน: {{ order.reject_reason }}
                </p>

                <dl class="mt-3 space-y-1 border-t pt-3 text-sm">
                    <div v-if="order.pickup_at" class="flex justify-between gap-2">
                        <dt class="text-muted-foreground">
                            {{ order.type === 'dine_in' ? 'เวลาที่นัดมาถึง' : 'เวลาที่นัดรับ' }}
                        </dt>
                        <dd>{{ dateTime(order.pickup_at) }}</dd>
                    </div>
                    <div v-if="order.table" class="flex justify-between gap-2">
                        <dt class="text-muted-foreground">โต๊ะ</dt>
                        <dd>{{ order.table }}</dd>
                    </div>
                    <div v-if="order.payment_intent_label" class="flex justify-between gap-2">
                        <dt class="text-muted-foreground">วิธีชำระเงิน</dt>
                        <dd>{{ order.payment_intent_label }}</dd>
                    </div>
                </dl>
            </section>

            <!-- QR พร้อมเพย์ -->
            <PromptPayQr
                v-if="promptPayPayload && !order.is_paid && !failed"
                :payload="promptPayPayload"
                :amount="order.totals.grand_total"
                :merchant-name="branch.name"
            />

            <!-- โครงการรัฐ: พนักงานเป็นคนออก QR ที่เคาน์เตอร์ -->
            <section
                v-if="isGovernmentScheme && !order.is_paid && !failed"
                class="rounded-xl border border-dashed bg-card p-4 text-sm"
            >
                <p class="font-medium">แสดงหน้าจอนี้ที่เคาน์เตอร์</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    ร้านทราบแล้วว่าคุณจะจ่ายผ่าน{{ order.payment_intent_label }} —
                    พนักงานจะออก QR ของโครงการให้ตอนคุณมารับของ
                    เงื่อนไขและสิทธิ์ของโครงการเป็นไปตามที่แอปของโครงการกำหนด
                </p>
            </section>

            <!-- รายการอาหาร -->
            <section class="rounded-xl border bg-card">
                <h2 class="border-b px-4 py-3 text-sm font-semibold">รายการอาหาร</h2>

                <ul class="divide-y">
                    <li v-for="item in order.items" :key="item.id" class="flex items-start justify-between gap-3 px-4 py-2.5">
                        <div class="min-w-0">
                            <p class="text-sm">
                                <span class="tabular font-medium">{{ number(item.qty) }}×</span>
                                {{ item.name }}
                            </p>
                            <p v-if="item.modifiers.length" class="text-xs text-muted-foreground">
                                {{ item.modifiers.join(', ') }}
                            </p>
                            <p v-if="item.note" class="text-xs text-muted-foreground">หมายเหตุ: {{ item.note }}</p>
                        </div>
                        <span class="tabular shrink-0 text-sm">{{ money(item.line_total) }}</span>
                    </li>
                </ul>

                <dl class="space-y-1 border-t px-4 py-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">ยอดรวม</dt>
                        <dd class="tabular">{{ money(order.totals.subtotal) }}</dd>
                    </div>
                    <div v-if="order.totals.service_charge > 0" class="flex justify-between">
                        <dt class="text-muted-foreground">ค่าบริการ</dt>
                        <dd class="tabular">{{ money(order.totals.service_charge) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-t pt-1.5">
                        <dt class="font-medium">ยอดที่ต้องชำระ</dt>
                        <dd class="tabular text-xl font-semibold">{{ money(order.totals.grand_total) }} ฿</dd>
                    </div>
                </dl>
            </section>

            <!-- ไทม์ไลน์ -->
            <section v-if="order.timeline.length" class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-semibold">ความเคลื่อนไหว</h2>
                <ol class="mt-2 space-y-2">
                    <li v-for="(event, i) in order.timeline" :key="i" class="flex items-start gap-2 text-sm">
                        <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-[var(--series-1)]" />
                        <span class="min-w-0">
                            <span class="block">{{ event.label }}</span>
                            <span v-if="event.note" class="block text-xs text-muted-foreground">{{ event.note }}</span>
                        </span>
                        <span class="tabular ml-auto shrink-0 text-xs text-muted-foreground">
                            {{ time(event.at) }}
                        </span>
                    </li>
                </ol>
            </section>

            <!-- ให้คะแนน -->
            <section
                v-if="order.is_paid && !failed"
                class="rounded-xl border bg-card p-4 text-center"
            >
                <template v-if="order.has_review">
                    <p class="text-sm font-medium">ขอบคุณสำหรับคะแนนครับ</p>
                    <StarRating :model-value="order.my_rating ?? 0" readonly size="sm" class="mt-1" />
                </template>
                <template v-else>
                    <p class="text-sm font-medium">มื้อนี้เป็นยังไงบ้าง</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">ให้คะแนนสั้น ๆ ช่วยร้านปรับปรุงได้จริง</p>
                    <Button variant="brand" size="lg" class="mt-3 w-full" @click="showReview = true">
                        <Star />
                        ให้คะแนนมื้อนี้
                    </Button>
                </template>
            </section>

            <!-- ปุ่มท้ายหน้า -->
            <div class="space-y-2">
                <Button
                    v-if="order.can_cancel"
                    variant="outline"
                    size="lg"
                    class="w-full text-[var(--status-critical)]"
                    @click="showCancel = true"
                >
                    ยกเลิกออเดอร์
                </Button>

                <Button v-if="branch.phone" variant="outline" size="lg" class="w-full" as="a" :href="`tel:${branch.phone}`">
                    <Phone />
                    โทรหาร้าน
                </Button>

                <Link
                    href="/order"
                    class="grid h-11 w-full place-items-center rounded-md text-sm text-muted-foreground hover:text-foreground"
                >
                    กลับไปหน้าเมนู
                </Link>
            </div>
        </div>

        <ReviewSheet v-model:open="showReview" :token="token" />

        <Modal
            v-model:open="showCancel"
            title="ยกเลิกออเดอร์นี้?"
            description="ยกเลิกได้เฉพาะตอนที่ร้านยังไม่กดรับออเดอร์"
        >
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="showCancel = false">ไม่ยกเลิก</Button>
                <Button variant="destructive" @click="cancelOrder">ยืนยันยกเลิก</Button>
            </div>
        </Modal>
    </div>
</template>
