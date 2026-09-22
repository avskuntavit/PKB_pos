<script setup lang="ts">
/**
 * หน้าจอครัว (KDS)
 *
 * ออกแบบให้อ่านจากระยะ 1-2 เมตรบนแท็บเล็ตในครัว:
 * ตัวอักษรใหญ่ คอนทราสต์สูง ปุ่มเดียวต่อการ์ด และไฮไลต์ใบที่รอนาน
 */
import { computed, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { AlarmClock, Printer, QrCode, RefreshCw, X } from 'lucide-vue-next'
import PosLayout from '@/layouts/PosLayout.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { usePolling } from '@/composables/usePolling'
import { number, time } from '@/lib/format'
import type { KitchenTicket, Option } from '@/types'

const props = defineProps<{
    tickets: KitchenTicket[]
    printGroups: Array<{ value: number; label: string }>
    filters: { print_group: number | null }
}>()

const printGroup = ref<number | ''>(props.filters.print_group ?? '')

const feedUrl = computed(
    () => `/kitchen/feed${printGroup.value ? `?print_group=${printGroup.value}` : ''}`,
)

// ครัวไม่ควรต้องกดรีเฟรชเอง — ดึงใหม่ทุก 8 วินาที
const { data: live, fetchNow, loading } = usePolling<{ tickets: KitchenTicket[] }>(() => feedUrl.value, 8000)

const tickets = computed(() => live.value?.tickets ?? props.tickets)

// ใบเก่าสุดอยู่ซ้าย — ครัวทำเรียงตามคิวที่เข้ามา
const columns = computed(() => ({
    queued: tickets.value.filter((t) => t.status === 'queued'),
    preparing: tickets.value.filter((t) => t.status === 'preparing'),
    ready: tickets.value.filter((t) => t.status === 'ready'),
}))

watch(printGroup, (value) => {
    router.get(
        '/kitchen',
        value ? { print_group: value } : {},
        { preserveState: true, preserveScroll: true, replace: true, onSuccess: fetchNow },
    )
})

function advance(ticket: KitchenTicket) {
    router.post(`/kitchen/tickets/${ticket.id}/advance`, {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: fetchNow,
    })
}

function moveBack(ticket: KitchenTicket) {
    const previous = ticket.status === 'ready' ? 'preparing' : 'queued'

    router.post(`/kitchen/tickets/${ticket.id}/status`, { status: previous }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: fetchNow,
    })
}

const nextLabel: Record<string, string> = {
    queued: 'เริ่มทำ',
    preparing: 'ทำเสร็จแล้ว',
    ready: 'เสิร์ฟแล้ว',
}

/** เกิน 15 นาทีถือว่าช้า เกิน 25 นาทีถือว่าวิกฤต */
function urgency(minutes: number): 'ok' | 'late' | 'critical' {
    if (minutes >= 25) return 'critical'
    if (minutes >= 15) return 'late'
    return 'ok'
}

const urgencyClass: Record<string, string> = {
    ok: 'border-border',
    late: 'border-[var(--status-warning)]',
    critical: 'border-[var(--status-critical)]',
}

const columnMeta = [
    { key: 'queued' as const, label: 'รอทำ' },
    { key: 'preparing' as const, label: 'กำลังทำ' },
    { key: 'ready' as const, label: 'รอเสิร์ฟ' },
]
</script>

<template>
    <Head title="หน้าจอครัว" />

    <PosLayout title="หน้าจอครัว">
        <div class="flex h-full flex-col">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b bg-card px-4 py-2.5">
                <div class="flex flex-wrap gap-1">
                    <Button
                        :variant="printGroup === '' ? 'secondary' : 'ghost'"
                        size="sm"
                        @click="printGroup = ''"
                    >
                        ทุกจุดผลิต
                    </Button>
                    <Button
                        v-for="g in printGroups"
                        :key="g.value"
                        :variant="printGroup === g.value ? 'secondary' : 'ghost'"
                        size="sm"
                        @click="printGroup = g.value"
                    >
                        {{ g.label }}
                    </Button>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-xs text-muted-foreground">อัพเดตอัตโนมัติทุก 8 วินาที</span>
                    <Button variant="outline" size="icon" aria-label="ดึงข้อมูลใหม่" @click="fetchNow">
                        <RefreshCw :class="loading ? 'animate-spin' : ''" />
                    </Button>
                </div>
            </div>

            <div v-if="tickets.length" class="grid min-h-0 flex-1 gap-3 overflow-y-auto p-3 lg:grid-cols-3">
                <section v-for="col in columnMeta" :key="col.key" class="flex min-h-0 flex-col gap-2">
                    <h2 class="flex items-center justify-between px-1 text-sm font-semibold">
                        {{ col.label }}
                        <Badge variant="secondary">{{ columns[col.key].length }}</Badge>
                    </h2>

                    <ul class="space-y-2">
                        <li
                            v-for="ticket in columns[col.key]"
                            :key="ticket.id"
                            class="rounded-xl border-2 bg-card p-3"
                            :class="urgencyClass[urgency(ticket.waiting_minutes)]"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-lg font-bold">
                                        {{ ticket.table ? `โต๊ะ ${ticket.table}` : 'กลับบ้าน' }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ ticket.ticket_no }} · รอบที่ {{ ticket.round }} ·
                                        {{ ticket.print_group_label }} · {{ time(ticket.queued_at) }}
                                    </p>
                                </div>

                                <span
                                    class="flex shrink-0 items-center gap-1 text-sm font-semibold"
                                    :class="{
                                        'text-[var(--status-warning)]': urgency(ticket.waiting_minutes) === 'late',
                                        'text-[var(--status-critical)]': urgency(ticket.waiting_minutes) === 'critical',
                                    }"
                                >
                                    <AlarmClock class="size-4" />
                                    {{ ticket.waiting_minutes }} น.
                                </span>
                            </div>

                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <!-- คอร์สตัวใหญ่กว่าป้ายอื่น เพราะเป็นตัวบอกว่าใบนี้ต้องรีบแค่ไหน -->
                                <Badge v-if="ticket.course_label" variant="outline" class="font-semibold">
                                    {{ ticket.course_label }}
                                </Badge>
                                <Badge v-if="ticket.source === 'self_order'" variant="outline">
                                    <QrCode class="mr-1 size-3" />
                                    ลูกค้าสั่งเอง
                                </Badge>
                            </div>

                            <ul class="mt-2 space-y-1.5 border-t pt-2">
                                <li v-for="item in ticket.items" :key="item.id" class="text-base">
                                    <span class="tabular font-bold">{{ number(item.qty) }}×</span>
                                    <span class="font-medium"> {{ item.name }}</span>
                                    <span v-if="item.modifiers_text" class="block pl-6 text-sm text-muted-foreground">
                                        {{ item.modifiers_text }}
                                    </span>
                                    <span
                                        v-if="item.note"
                                        class="block pl-6 text-sm font-medium text-[var(--status-warning)]"
                                    >
                                        ★ {{ item.note }}
                                    </span>
                                </li>
                            </ul>

                            <div class="mt-3 flex gap-2">
                                <Button variant="brand" size="lg" class="flex-1" @click="advance(ticket)">
                                    {{ nextLabel[ticket.status] }}
                                </Button>

                                <Button
                                    v-if="ticket.status !== 'queued'"
                                    variant="outline"
                                    size="icon"
                                    class="size-11"
                                    aria-label="ย้อนสถานะ"
                                    @click="moveBack(ticket)"
                                >
                                    <X />
                                </Button>

                                <Link
                                    :href="`/kitchen/tickets/${ticket.id}/print`"
                                    class="grid size-11 shrink-0 place-items-center rounded-md border hover:bg-accent"
                                    aria-label="พิมพ์ใบสั่งครัว"
                                >
                                    <Printer class="size-4" />
                                </Link>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>

            <EmptyState
                v-else
                title="ไม่มีใบสั่งครัวค้าง"
                description="ใบใหม่จะขึ้นเองเมื่อพนักงานส่งรายการเข้าครัว"
            />
        </div>
    </PosLayout>
</template>
