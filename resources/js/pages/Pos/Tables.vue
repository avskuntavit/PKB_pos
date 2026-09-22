<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import {
    ChefHat,
    Clock,
    Columns,
    CreditCard,
    DoorOpen,
    Grid,
    Hourglass,
    LayoutGrid,
    Maximize2,
    Minimize2,
    PlayCircle,
    QrCode,
    RotateCcw,
    Sparkles,
    Square,
    Users,
    Wine,
    X,
} from 'lucide-vue-next'
import PosLayout from '@/layouts/PosLayout.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Modal from '@/components/ui/Modal.vue'
import Badge from '@/components/ui/Badge.vue'
import ServiceCallBar from '@/components/pos/ServiceCallBar.vue'
import { usePolling } from '@/composables/usePolling'
import { money, time } from '@/lib/format'
import { useIdempotencyKey } from '@/lib/idempotency'
import type { DiningTable, FloorPlanObject, ServiceCallItem } from '@/types'

const props = defineProps<{
    zones: Array<{ id: number; name: string }>
    tables: DiningTable[]
    layoutObjects?: FloorPlanObject[]
    shift: Record<string, any> | null
    serviceCalls: ServiceCallItem[]
    stats: { occupied: number; total: number; open_bills: number; pending_approvals: number }
}>()

// แจ้งเตือนจากโต๊ะมาเองทุก 10 วินาที พนักงานไม่ต้องคอยรีเฟรช
const { data: alerts, fetchNow } = usePolling<{
    calls: ServiceCallItem[]
    pending_orders: Array<{ order_id: number; order_no: string; table: string | null; pending_count: number }>
}>('/pos/alerts', 10000)

const calls = computed(() => alerts.value?.calls ?? props.serviceCalls)
const pendingApprovalCount = computed(
    () =>
        alerts.value?.pending_orders.reduce((sum, o) => sum + o.pending_count, 0) ??
        props.stats.pending_approvals,
)

const viewMode = ref<'canvas' | 'grid'>('canvas')
const activeZone = ref<number | 'all'>('all')
const zoom = ref(1)
const showShiftModal = ref(false)
const showOpenModal = ref(false)
const selectedTable = ref<DiningTable | null>(null)

const shiftForm = useForm({ opening_cash: 1000 })
const orderForm = useForm({ dining_table_id: null as number | null, type: 'dine_in', guest_count: 2 })

// เปิดบิลซ้ำ = โต๊ะเดียวมีสองบิล กันด้วยคีย์เดียวกันทั้งบิลโต๊ะและบิลซื้อกลับบ้าน
const { rotate: rotateOpenKey, headers: openHeaders } = useIdempotencyKey()

const visibleTables = computed(() =>
    activeZone.value === 'all'
        ? props.tables
        : props.tables.filter((t) => t.zone?.id === activeZone.value),
)

const visibleObjects = computed(() => {
    const objs = props.layoutObjects ?? []
    if (activeZone.value === 'all') return objs
    return objs.filter((o) => o.zone_id === activeZone.value || !o.zone_id)
})

// คำนวณขอบเขตขนาด Canvas อัตโนมัติจากตำแหน่งของโต๊ะและอ็อบเจ็กต์
const canvasSize = computed(() => {
    let maxX = 1200
    let maxY = 800

    props.tables.forEach((t) => {
        const w = Number(t.width) >= 40 ? Number(t.width) : 90
        const h = Number(t.height) >= 40 ? Number(t.height) : 90
        maxX = Math.max(maxX, (t.pos_x || 0) + w + 120)
        maxY = Math.max(maxY, (t.pos_y || 0) + h + 120)
    })

    ;(props.layoutObjects ?? []).forEach((o) => {
        const w = Number(o.width) >= 20 ? Number(o.width) : 140
        const h = Number(o.height) >= 20 ? Number(o.height) : 70
        maxX = Math.max(maxX, (o.pos_x || 0) + w + 120)
        maxY = Math.max(maxY, (o.pos_y || 0) + h + 120)
    })

    return { width: maxX, height: maxY }
})

const statusStyle: Record<string, { card: string; badge: string; text: string }> = {
    available: {
        card: 'border-border bg-card/90 text-foreground hover:border-primary/60 hover:shadow-md',
        badge: 'bg-muted text-muted-foreground',
        text: 'ว่าง',
    },
    occupied: {
        card: 'border-[var(--series-2)]/60 bg-[var(--series-2)]/12 text-foreground shadow-sm ring-1 ring-[var(--series-2)]/40 hover:border-[var(--series-2)]',
        badge: 'bg-[var(--series-2)]/20 text-[var(--series-2)] font-semibold',
        text: 'มีลูกค้า',
    },
    reserved: {
        card: 'border-[var(--series-4)]/60 bg-[var(--series-4)]/12 text-foreground shadow-sm ring-1 ring-[var(--series-4)]/40 hover:border-[var(--series-4)]',
        badge: 'bg-[var(--series-4)]/20 text-[var(--series-4)] font-semibold',
        text: 'จองแล้ว',
    },
    cleaning: {
        card: 'border-border bg-muted/60 text-muted-foreground',
        badge: 'bg-muted text-muted-foreground',
        text: 'ทำความสะอาด',
    },
}

/**
 * โต๊ะที่ลูกค้าสแกน QR รออยู่แต่ยังไม่มีใครเปิดให้
 *
 * ไม่ได้เก็บเป็นสถานะในฐานข้อมูล เพราะมันไม่ใช่สถานะของโต๊ะ
 * แต่เป็น "มีคำขอค้างอยู่" ซึ่งมาจากแถบแจ้งเตือนที่ poll อยู่แล้วทุก 10 วินาที
 * อ่านจากตรงนั้นจึงอัปเดตเองโดยไม่ต้องยิง query เพิ่ม
 */
const waitingTableIds = computed(
    () =>
        new Set(
            calls.value
                .filter((c) => c.type === 'open_table' && c.dining_table_id)
                .map((c) => c.dining_table_id as number),
        ),
)

const isWaiting = (table: DiningTable) => waitingTableIds.value.has(table.id)

/*
| สีของโต๊ะที่มีคนรออยู่ต้องชนะสีสถานะปกติ
|
| เพราะโต๊ะนั้นสถานะยังเป็น "ว่าง" อยู่จริง ๆ ถ้าโชว์เป็นสีว่างเฉย ๆ
| พนักงานจะกวาดตาผ่านไป ทั้งที่เป็นโต๊ะเดียวบนผังที่มีคนนั่งรอจริง
*/
const WAITING_CARD =
    'border-[var(--status-warning)] bg-[var(--status-warning)]/15 text-foreground shadow-md ring-2 ring-[var(--status-warning)]/50'

const cardClass = (table: DiningTable) =>
    isWaiting(table)
        ? WAITING_CARD
        : statusStyle[table.status]?.card ?? statusStyle.available.card

/** คำอธิบายสีใต้แถบเครื่องมือ — พนักงานใหม่จะได้ไม่ต้องเดาว่าสีไหนแปลว่าอะไร */
const legend: Array<{ label: string; swatch: string }> = [
    { label: 'ว่าง', swatch: 'border-border bg-card' },
    { label: 'มีลูกค้า', swatch: 'border-[var(--series-2)] bg-[var(--series-2)]/25' },
    { label: 'จองแล้ว', swatch: 'border-[var(--series-4)] bg-[var(--series-4)]/25' },
    { label: 'ทำความสะอาด', swatch: 'border-border bg-muted' },
    { label: 'ลูกค้ารอเปิดโต๊ะ', swatch: 'border-[var(--status-warning)] bg-[var(--status-warning)]/25' },
]

function getObjectIcon(type: string) {
    switch (type) {
        case 'cashier':
            return CreditCard
        case 'bar':
            return Wine
        case 'kitchen':
            return ChefHat
        case 'entrance':
            return DoorOpen
        case 'restroom':
            return Users
        case 'pillar':
        case 'wall':
            return Columns
        case 'custom':
            return Sparkles
        default:
            return Square
    }
}

function getObjectAccent(type: string) {
    switch (type) {
        case 'cashier':
            return '#10b981'
        case 'bar':
            return '#f59e0b'
        case 'kitchen':
            return '#f97316'
        case 'entrance':
            return '#0ea5e9'
        case 'restroom':
            return '#6366f1'
        case 'pillar':
        case 'wall':
            return '#64748b'
        case 'custom':
            return '#a855f7'
        default:
            return '#6b7280'
    }
}

function getObjectClass(type: string) {
    switch (type) {
        case 'cashier':
            return 'bg-emerald-100/95 dark:bg-emerald-950/85 border-emerald-500 text-emerald-950 dark:text-emerald-50 shadow-xs'
        case 'bar':
            return 'bg-amber-100/95 dark:bg-amber-950/85 border-amber-500 text-amber-950 dark:text-amber-50 shadow-xs'
        case 'kitchen':
            return 'bg-orange-100/95 dark:bg-orange-950/85 border-orange-500 text-orange-950 dark:text-orange-50 shadow-xs'
        case 'entrance':
            return 'bg-sky-100/95 dark:bg-sky-950/85 border-sky-500 text-sky-950 dark:text-sky-50 border-dashed shadow-xs'
        case 'restroom':
            return 'bg-indigo-100/95 dark:bg-indigo-950/85 border-indigo-500 text-indigo-950 dark:text-indigo-50 shadow-xs'
        case 'pillar':
        case 'wall':
            return 'bg-slate-300/95 dark:bg-slate-700/95 border-slate-500 text-slate-950 dark:text-slate-50 shadow-xs'
        case 'custom':
            return 'bg-purple-100/95 dark:bg-purple-950/85 border-purple-500 text-purple-950 dark:text-purple-50 shadow-xs'
        default:
            return 'bg-card border-border text-foreground shadow-xs'
    }
}

function onTableClick(table: DiningTable) {
    // โต๊ะที่มีบิลเปิดอยู่ ให้เข้าบิลเดิมทันที ไม่ต้องถามซ้ำ
    if (table.open_order) {
        router.get(`/pos/terminal/${table.open_order.id}`)
        return
    }

    selectedTable.value = table
    orderForm.dining_table_id = table.id
    orderForm.guest_count = Math.min(table.seats, 2)
    showOpenModal.value = true
}

function openOrder() {
    orderForm.post('/pos/orders', {
        headers: openHeaders(),
        onSuccess: () => {
            showOpenModal.value = false
            rotateOpenKey()
        },
    })
}

/** พนักงานกด "เปิดโต๊ะ" จากแถบแจ้งเตือน — เปิดหน้าต่างเดิมให้เลย ไม่ต้องไปหาโต๊ะบนผังเอง */
function openTableFromCall(call: ServiceCallItem) {
    const table = props.tables.find((t) => t.id === call.dining_table_id)

    if (! table) return

    onTableClick(table)
}

function openTakeaway() {
    router.post(
        '/pos/orders',
        { dining_table_id: null, type: 'takeaway', guest_count: 1 },
        {
            headers: openHeaders(),
            onSuccess: () => rotateOpenKey(),
        },
    )
}
</script>

<template>
    <Head title="ผังโต๊ะ" />

    <PosLayout title="ผังโต๊ะ">
        <div class="flex h-full flex-col">
            <!-- แถบสถานะรอบการขาย -->
            <div class="flex flex-wrap items-center justify-between gap-3 border-b bg-card px-4 py-2.5">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <Badge :variant="shift ? 'success' : 'danger'">
                        {{ shift ? `รอบ ${shift.shift_no} เปิดอยู่` : 'ยังไม่ได้เปิดรอบการขาย' }}
                    </Badge>
                    <span class="text-muted-foreground">
                        โต๊ะไม่ว่าง {{ stats.occupied }}/{{ stats.total }} · บิลเปิดอยู่ {{ stats.open_bills }}
                    </span>
                    <Badge v-if="pendingApprovalCount > 0" variant="warning">
                        <QrCode class="mr-1 size-3" />
                        รอยืนยัน {{ pendingApprovalCount }} รายการ
                    </Badge>
                </div>

                <div class="flex items-center gap-2">
                    <!-- สลับมุมมอง Canvas ผังร้าน vs Grid -->
                    <div class="inline-flex rounded-lg border bg-muted/40 p-0.5 text-xs">
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-md px-2.5 py-1 font-medium transition-all"
                            :class="viewMode === 'canvas' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            @click="viewMode = 'canvas'"
                        >
                            <LayoutGrid class="size-3.5" />
                            ผังร้านจริง
                        </button>
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-md px-2.5 py-1 font-medium transition-all"
                            :class="viewMode === 'grid' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            @click="viewMode = 'grid'"
                        >
                            <Grid class="size-3.5" />
                            ตารางด่วน
                        </button>
                    </div>

                    <Button v-if="!shift" variant="brand" size="sm" @click="showShiftModal = true">
                        <PlayCircle class="size-4" />
                        เปิดรอบการขาย
                    </Button>
                    <Button variant="outline" size="sm" @click="openTakeaway">เปิดบิลซื้อกลับบ้าน</Button>
                </div>
            </div>

            <ServiceCallBar
                :calls="calls"
                can-open-table
                @refresh="fetchNow"
                @open-table="openTableFromCall"
            />

            <!-- คำอธิบายสีโต๊ะ -->
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b bg-card px-4 py-1.5 text-xs text-muted-foreground">
                <span
                    v-for="item in legend"
                    :key="item.label"
                    class="inline-flex items-center gap-1.5"
                >
                    <span class="size-3 shrink-0 rounded border-2" :class="item.swatch" />
                    {{ item.label }}
                </span>
            </div>

            <!-- แท็บโซน และ ตัวช่วยซูม -->
            <div class="flex flex-wrap items-center justify-between gap-2 border-b bg-card px-4 py-2">
                <div class="flex items-center gap-1 overflow-x-auto">
                    <Button
                        :variant="activeZone === 'all' ? 'secondary' : 'ghost'"
                        size="sm"
                        @click="activeZone = 'all'"
                    >
                        ทั้งหมด ({{ tables.length }})
                    </Button>
                    <Button
                        v-for="zone in zones"
                        :key="zone.id"
                        :variant="activeZone === zone.id ? 'secondary' : 'ghost'"
                        size="sm"
                        @click="activeZone = zone.id"
                    >
                        {{ zone.name }} ({{ tables.filter((t) => t.zone?.id === zone.id).length }})
                    </Button>
                </div>

                <!-- ซูมสำหรับมุมมองผังร้าน -->
                <div v-if="viewMode === 'canvas'" class="flex items-center gap-1">
                    <button
                        type="button"
                        class="rounded-md border p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        title="ย่อ"
                        @click="zoom = Math.max(0.6, zoom - 0.1)"
                    >
                        <Minimize2 class="size-3.5" />
                    </button>
                    <span class="w-11 text-center font-mono text-xs text-muted-foreground">
                        {{ Math.round(zoom * 100) }}%
                    </span>
                    <button
                        type="button"
                        class="rounded-md border p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        title="ขยาย"
                        @click="zoom = Math.min(1.5, zoom + 0.1)"
                    >
                        <Maximize2 class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        class="rounded-md border p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        title="รีเซ็ต 100%"
                        @click="zoom = 1"
                    >
                        <RotateCcw class="size-3.5" />
                    </button>
                </div>
            </div>

            <!-- 1. มุมมองผังร้านจริง (Floor Plan Canvas View) -->
            <div
                v-if="viewMode === 'canvas'"
                class="relative min-h-0 flex-1 overflow-auto bg-muted/20 p-6 select-none"
            >
                <div
                    class="relative rounded-2xl border border-dashed bg-card/60 shadow-xs transition-transform duration-75 origin-top-left"
                    :style="{
                        width: `${canvasSize.width}px`,
                        height: `${canvasSize.height}px`,
                        transform: `scale(${zoom})`,
                        backgroundImage: `radial-gradient(var(--border) 1px, transparent 1px)`,
                        backgroundSize: '20px 20px',
                    }"
                >
                    <!-- อ็อบเจ็กต์ผังร้าน (เคาน์เตอร์, บาร์, ครัว, ทางเข้า ฯลฯ) -->
                    <div
                        v-for="obj in visibleObjects"
                        :key="`obj-${obj.id}`"
                        class="absolute flex flex-col items-center justify-center p-2 text-center shadow-xs transition-all pointer-events-none border-2"
                        :class="[
                            !obj.color && getObjectClass(obj.type),
                            obj.type === 'pillar' ? 'rounded-lg' : 'rounded-2xl',
                        ]"
                        :style="{
                            left: `${obj.pos_x || 0}px`,
                            top: `${obj.pos_y || 0}px`,
                            width: `${Number(obj.width) >= 20 ? Number(obj.width) : 140}px`,
                            height: `${Number(obj.height) >= 20 ? Number(obj.height) : 70}px`,
                            ...(obj.color ? { backgroundColor: `${obj.color}25`, borderColor: obj.color } : {}),
                        }"
                    >
                        <div class="flex items-center justify-center gap-1.5 max-w-full px-1">
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded text-white shadow-2xs font-bold"
                                :style="{ backgroundColor: obj.color || getObjectAccent(obj.type) }"
                            >
                                <component :is="getObjectIcon(obj.type)" class="size-3" />
                            </span>
                            <span class="truncate text-xs font-bold leading-tight">{{ obj.name }}</span>
                        </div>
                    </div>

                    <!-- โต๊ะอาหารพร้อมพิกัดและขนาดจริง -->
                    <button
                        v-for="table in visibleTables"
                        :key="table.id"
                        type="button"
                        class="group absolute flex flex-col items-center justify-center p-2.5 text-center transition-all cursor-pointer shadow-xs border-2"
                        :class="[
                            table.shape === 'circle' ? 'rounded-full' : 'rounded-2xl',
                            cardClass(table),
                        ]"
                        :style="{
                            left: `${table.pos_x || 0}px`,
                            top: `${table.pos_y || 0}px`,
                            width: `${Number(table.width) >= 40 ? Number(table.width) : 90}px`,
                            height: `${Number(table.height) >= 40 ? Number(table.height) : 90}px`,
                        }"
                        @click="onTableClick(table)"
                    >
                        <!--
                            ป้ายคนรออยู่ — กะพริบที่ป้ายอย่างเดียว ไม่กะพริบทั้งการ์ด
                            ถ้ากะพริบทั้งใบ ตัวเลขยอดเงินกับชื่อโต๊ะจะอ่านยากตอนจาง
                        -->
                        <span
                            v-if="isWaiting(table)"
                            class="absolute -right-1.5 -top-1.5 flex size-6 items-center justify-center rounded-full bg-[var(--status-warning)] text-white shadow animate-pulse"
                            aria-label="ลูกค้ารอให้เปิดโต๊ะ"
                        >
                            <Hourglass class="size-3.5" />
                        </span>

                        <!-- ชื่อโต๊ะและไอคอน QR สั่งอาหาร -->
                        <div class="flex items-center justify-center gap-1">
                            <span class="text-base font-bold tracking-tight">{{ table.name }}</span>
                            <QrCode
                                v-if="table.open_order?.pending_approval_items?.length"
                                class="size-4 animate-pulse text-[var(--status-warning)]"
                                aria-label="มีรายการที่ลูกค้าสั่งรอยยืนยัน"
                            />
                        </div>

                        <!-- รายละเอียดเมื่อมีบิลเปิด -->
                        <div v-if="table.open_order" class="mt-1 space-y-0.5 text-xs">
                            <span class="tabular block font-semibold text-[var(--series-2)]">
                                {{ money(table.open_order.grand_total) }} ฿
                            </span>
                            <span class="flex items-center justify-center gap-1 text-[10px] text-muted-foreground">
                                <Clock class="size-2.5" />
                                {{ time(table.open_order.opened_at) }}
                            </span>
                        </div>

                        <!-- รายละเอียดเมื่อโต๊ะว่าง -->
                        <div
                            v-else-if="isWaiting(table)"
                            class="mt-0.5 text-[11px] font-semibold text-[var(--status-warning)]"
                        >
                            ลูกค้ารออยู่
                        </div>
                        <div v-else class="mt-0.5 flex items-center justify-center gap-1 text-[11px] text-muted-foreground">
                            <Users class="size-3 opacity-70" />
                            <span>{{ table.seats }} ที่นั่ง</span>
                        </div>
                    </button>
                </div>
            </div>

            <!-- 2. มุมมองตารางด่วน (Quick Grid View) -->
            <div v-else class="min-h-0 flex-1 overflow-auto p-4">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8">
                    <button
                        v-for="table in visibleTables"
                        :key="table.id"
                        class="relative flex aspect-square flex-col items-center justify-center gap-1 rounded-2xl border-2 p-2.5 text-center transition-all cursor-pointer shadow-xs"
                        :class="[
                            table.shape === 'circle' ? 'rounded-full' : 'rounded-2xl',
                            cardClass(table),
                        ]"
                        @click="onTableClick(table)"
                    >
                        <span
                            v-if="isWaiting(table)"
                            class="absolute -right-1.5 -top-1.5 flex size-6 items-center justify-center rounded-full bg-[var(--status-warning)] text-white shadow animate-pulse"
                            aria-label="ลูกค้ารอให้เปิดโต๊ะ"
                        >
                            <Hourglass class="size-3.5" />
                        </span>

                        <span class="flex items-center gap-1 text-lg font-bold">
                            {{ table.name }}
                            <QrCode
                                v-if="table.open_order?.pending_approval_items?.length"
                                class="size-4 text-[var(--status-warning)] animate-pulse"
                                aria-label="มีรายการที่ลูกค้าสั่งรอยืนยัน"
                            />
                        </span>

                        <span v-if="table.open_order" class="space-y-0.5 text-xs">
                            <span class="tabular block font-semibold text-[var(--series-2)]">
                                {{ money(table.open_order.grand_total) }} ฿
                            </span>
                            <span class="flex items-center justify-center gap-1 text-[11px] text-muted-foreground">
                                <Clock class="size-3" />
                                {{ time(table.open_order.opened_at) }}
                            </span>
                        </span>

                        <span
                            v-else-if="isWaiting(table)"
                            class="text-xs font-semibold text-[var(--status-warning)]"
                        >
                            ลูกค้ารออยู่
                        </span>
                        <span v-else class="flex items-center gap-1 text-xs text-muted-foreground">
                            <Users class="size-3" />
                            {{ table.seats }} ที่นั่ง
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!-- เปิดรอบการขาย -->
        <Modal
            v-model:open="showShiftModal"
            title="เปิดรอบการขาย"
            description="ระบุเงินทอนตั้งต้นในลิ้นชัก"
        >
            <form class="space-y-3" @submit.prevent="shiftForm.post('/pos/shifts', { onSuccess: () => (showShiftModal = false) })">
                <div class="space-y-1">
                    <Label for="cash">เงินทอนตั้งต้น (บาท)</Label>
                    <Input id="cash" v-model="shiftForm.opening_cash" type="number" step="0.01" min="0" required />
                </div>
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="showShiftModal = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="shiftForm.processing">เปิดรอบ</Button>
                </div>
            </form>
        </Modal>

        <!-- เปิดบิลใหม่ -->
        <Modal
            v-model:open="showOpenModal"
            :title="`เปิดบิลโต๊ะ ${selectedTable?.name ?? ''}`"
            description="ระบุจำนวนลูกค้าเพื่อใช้คำนวณรายงานต่อหัว"
        >
            <form class="space-y-3" @submit.prevent="openOrder">
                <div class="space-y-1">
                    <Label for="guests">จำนวนลูกค้า</Label>
                    <Input id="guests" v-model="orderForm.guest_count" type="number" min="1" max="99" required />
                </div>
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="showOpenModal = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="orderForm.processing">เปิดบิล</Button>
                </div>
            </form>
        </Modal>
    </PosLayout>
</template>
