<script setup lang="ts">
/**
 * คิวออเดอร์ล่วงหน้าฝั่งร้าน
 *
 * ออกแบบสำหรับมือถือขณะยืนหน้าเคาน์เตอร์: การ์ดใหญ่ ปุ่มหลักปุ่มเดียว
 * ใบที่ยังไม่กดรับจะลอยขึ้นบนสุดเสมอ เพราะลูกค้ากำลังรอคำตอบอยู่
 */
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { AlarmClock, BadgeCheck, Landmark, Phone, RefreshCw, ShoppingBag, Utensils, Wallet, X } from 'lucide-vue-next'
import PosLayout from '@/layouts/PosLayout.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Input from '@/components/ui/Input.vue'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { usePolling } from '@/composables/usePolling'
import { money, number, time } from '@/lib/format'

interface OnlineOrderRow {
    id: number
    order_no: string
    status: string
    status_label: string
    type: string
    type_label: string
    table: string | null
    contact_name: string | null
    contact_phone: string | null
    pickup_at: string | null
    minutes_to_pickup: number | null
    payment_intent: string | null
    payment_intent_label: string | null
    is_government_scheme: boolean
    grand_total: number
    note: string | null
    pending_count: number
    items: Array<{ id: number; name: string; qty: number; note: string | null }>
}

const props = defineProps<{
    orders: OnlineOrderRow[]
    statuses: Array<{ value: string; label: string }>
}>()

const { data: live, fetchNow, loading } = usePolling<{ orders: OnlineOrderRow[] }>(
    '/pos/online-orders/feed',
    10000,
)

const orders = computed(() => live.value?.orders ?? props.orders)

const rejecting = ref<OnlineOrderRow | null>(null)
const rejectReason = ref('')

function accept(order: OnlineOrderRow) {
    router.post(`/pos/online-orders/${order.id}/accept`, {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: fetchNow,
    })
}

function moveTo(order: OnlineOrderRow, status: string) {
    router.post(`/pos/online-orders/${order.id}/status`, { status }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: fetchNow,
    })
}

function reject() {
    if (!rejecting.value || !rejectReason.value.trim()) return

    router.post(
        `/pos/online-orders/${rejecting.value.id}/reject`,
        { reason: rejectReason.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                rejecting.value = null
                rejectReason.value = ''
                fetchNow()
            },
        },
    )
}

/** ปุ่มหลักของแต่ละสถานะ — ปุ่มเดียวจบ ไม่ต้องคิดว่ากดอะไรต่อ */
const nextAction: Record<string, { label: string; status: string } | null> = {
    accepted: { label: 'เริ่มทำ', status: 'preparing' },
    preparing: { label: 'ทำเสร็จ พร้อมให้รับ', status: 'ready' },
}

/** ติดลบ = เลยเวลานัดแล้ว ลูกค้ากำลังยืนรออยู่ */
function urgency(minutes: number | null): 'ok' | 'soon' | 'late' {
    if (minutes === null) return 'ok'
    if (minutes < 0) return 'late'
    if (minutes <= 10) return 'soon'
    return 'ok'
}
</script>

<template>
    <Head title="ออเดอร์ล่วงหน้า" />

    <PosLayout title="ออเดอร์ล่วงหน้า">
        <div class="flex h-full flex-col">
            <div class="flex items-center justify-between gap-2 border-b bg-card px-4 py-2.5">
                <p class="text-sm">
                    ในคิว <span class="font-semibold">{{ orders.length }}</span> ออเดอร์
                    <span v-if="orders.some((o) => o.status === 'placed')" class="text-[var(--status-warning)]">
                        · มีใบรอคุณกดรับ
                    </span>
                </p>

                <div class="flex items-center gap-2">
                    <span class="hidden text-xs text-muted-foreground sm:inline">อัพเดตทุก 10 วินาที</span>
                    <Button variant="outline" size="icon" aria-label="ดึงข้อมูลใหม่" @click="fetchNow">
                        <RefreshCw :class="loading ? 'animate-spin' : ''" />
                    </Button>
                </div>
            </div>

            <div v-if="orders.length" class="min-h-0 flex-1 overflow-y-auto p-3">
                <ul class="mx-auto grid max-w-3xl gap-3 lg:max-w-6xl lg:grid-cols-2 2xl:max-w-none 2xl:grid-cols-3">
                    <li
                        v-for="order in orders"
                        :key="order.id"
                        class="rounded-xl border-2 bg-card p-4"
                        :class="
                            order.status === 'placed'
                                ? 'border-[var(--status-warning)]'
                                : urgency(order.minutes_to_pickup) === 'late'
                                  ? 'border-[var(--status-critical)]'
                                  : 'border-border'
                        "
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="flex items-center gap-1.5 text-base font-bold">
                                    <component
                                        :is="order.type === 'dine_in' ? Utensils : ShoppingBag"
                                        class="size-4 shrink-0 text-muted-foreground"
                                    />
                                    {{ order.contact_name ?? 'ลูกค้า' }}
                                    <span v-if="order.table" class="text-sm font-normal text-muted-foreground">
                                        · โต๊ะ {{ order.table }}
                                    </span>
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ order.order_no }} · {{ order.type_label }}
                                </p>
                            </div>

                            <Badge :variant="order.status === 'placed' ? 'warning' : 'secondary'" class="shrink-0">
                                {{ order.status_label }}
                            </Badge>
                        </div>

                        <p
                            v-if="order.pickup_at"
                            class="mt-2 flex items-center gap-1.5 text-sm"
                            :class="{
                                'text-[var(--status-critical)]': urgency(order.minutes_to_pickup) === 'late',
                                'text-[var(--status-warning)]': urgency(order.minutes_to_pickup) === 'soon',
                            }"
                        >
                            <AlarmClock class="size-4 shrink-0" />
                            นัด {{ time(order.pickup_at) }}
                            <span v-if="order.minutes_to_pickup !== null" class="text-xs">
                                ({{
                                    order.minutes_to_pickup < 0
                                        ? `เลยมา ${Math.abs(order.minutes_to_pickup)} นาที`
                                        : `อีก ${order.minutes_to_pickup} นาที`
                                }})
                            </span>
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <Badge v-if="order.is_government_scheme" variant="outline">
                                <Landmark class="mr-1 size-3" />
                                {{ order.payment_intent_label }} — ออก QR ที่เคาน์เตอร์
                            </Badge>
                            <Badge v-else-if="order.payment_intent_label" variant="outline">
                                <BadgeCheck class="mr-1 size-3" />
                                {{ order.payment_intent_label }}
                            </Badge>

                            <a
                                v-if="order.contact_phone"
                                :href="`tel:${order.contact_phone}`"
                                class="inline-flex items-center gap-1 text-xs text-[var(--series-1)] hover:underline"
                            >
                                <Phone class="size-3" />
                                {{ order.contact_phone }}
                            </a>
                        </div>

                        <ul class="mt-3 space-y-1 border-t pt-2 text-sm">
                            <li v-for="item in order.items" :key="item.id">
                                <span class="tabular font-semibold">{{ number(item.qty) }}×</span>
                                {{ item.name }}
                                <span v-if="item.note" class="block pl-5 text-xs text-[var(--status-warning)]">
                                    ★ {{ item.note }}
                                </span>
                            </li>
                        </ul>

                        <p v-if="order.note" class="mt-2 text-xs text-muted-foreground">
                            หมายเหตุถึงร้าน: {{ order.note }}
                        </p>

                        <p class="tabular mt-2 text-right text-lg font-semibold">
                            {{ money(order.grand_total) }} ฿
                        </p>

                        <div class="mt-3 flex gap-2">
                            <template v-if="order.status === 'placed'">
                                <Button
                                    variant="outline"
                                    size="lg"
                                    class="text-[var(--status-critical)]"
                                    @click="rejecting = order"
                                >
                                    <X />
                                    ปฏิเสธ
                                </Button>
                                <Button variant="brand" size="lg" class="flex-1" @click="accept(order)">
                                    รับออเดอร์ · ส่งเข้าครัว
                                </Button>
                            </template>

                            <template v-else-if="order.status === 'ready'">
                                <!-- ลูกค้ามาถึงแล้ว: ไปหน้าจอรับเงินของบิลนี้ได้เลย ไม่ต้องไปไล่หาบิลเอง -->
                                <Link
                                    :href="`/pos/terminal/${order.id}`"
                                    class="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-md bg-[var(--series-1)] text-sm font-medium text-white hover:brightness-110"
                                >
                                    <Wallet class="size-4" />
                                    รับเงิน
                                </Link>
                                <Button
                                    variant="outline"
                                    size="lg"
                                    @click="moveTo(order, 'completed')"
                                >
                                    ส่งของแล้ว
                                </Button>
                            </template>

                            <Button
                                v-else-if="nextAction[order.status]"
                                variant="brand"
                                size="lg"
                                class="w-full"
                                @click="moveTo(order, nextAction[order.status]!.status)"
                            >
                                {{ nextAction[order.status]!.label }}
                            </Button>
                        </div>
                    </li>
                </ul>
            </div>

            <EmptyState
                v-else
                title="ยังไม่มีออเดอร์ล่วงหน้า"
                description="ออเดอร์จากหน้าร้านออนไลน์จะขึ้นที่นี่เอง"
            />
        </div>

        <Modal
            :open="rejecting !== null"
            title="ปฏิเสธออเดอร์"
            description="ลูกค้าจะเห็นเหตุผลนี้บนหน้าติดตามทันที"
            @update:open="(v) => !v && (rejecting = null)"
        >
            <div class="space-y-3">
                <Input v-model="rejectReason" placeholder="เช่น ของหมด / คิวยาวเกินเวลาที่นัด" class="h-11" />

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="preset in ['ของหมด', 'คิวยาว ทำไม่ทันเวลานัด', 'ร้านใกล้ปิดแล้ว']"
                        :key="preset"
                        variant="outline"
                        size="sm"
                        @click="rejectReason = preset"
                    >
                        {{ preset }}
                    </Button>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <Button variant="outline" @click="rejecting = null">ยกเลิก</Button>
                    <Button variant="destructive" :disabled="!rejectReason.trim()" @click="reject">
                        ยืนยันปฏิเสธ
                    </Button>
                </div>
            </div>
        </Modal>
    </PosLayout>
</template>
