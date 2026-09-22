<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import {
    Armchair,
    ChefHat,
    Columns,
    Copy,
    CreditCard,
    DoorOpen,
    Grid,
    Layers,
    LayoutGrid,
    List,
    Maximize2,
    Minimize2,
    Move,
    Pencil,
    Plus,
    QrCode,
    RotateCw,
    Save,
    Sparkles,
    Square,
    Trash2,
    Users,
    Wine,
    X,
} from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import type { DiningTable, FloorPlanObject, FloorPlanObjectType } from '@/types'

const props = defineProps<{
    zones: Array<{ id: number; name: string }>
    tables: DiningTable[]
    layoutObjects?: FloorPlanObject[]
}>()

/* ---------- ตัวแปรสถานะหลัก ---------- */

const viewMode = ref<'designer' | 'list'>('designer')
const activeZone = ref<number | 'all'>('all')
const zoom = ref(1)
const GRID = 20

// สำเนาสำหรับแก้ไขใน canvas
const layoutTables = ref<DiningTable[]>(
    props.tables.map((t) => ({
        ...t,
        width: Number(t.width) >= 40 ? Number(t.width) : 90,
        height: Number(t.height) >= 40 ? Number(t.height) : 90,
        shape: t.shape || 'square',
    })),
)

const layoutObjs = ref<FloorPlanObject[]>(
    (props.layoutObjects ?? []).map((o) => ({
        ...o,
        width: Number(o.width) >= 20 ? Number(o.width) : 140,
        height: Number(o.height) >= 20 ? Number(o.height) : 70,
    })),
)

// ซิงค์เมื่อ props อัปเดตจาก server
watch(
    () => props.tables,
    (newVal) => {
        layoutTables.value = newVal.map((t) => ({
            ...t,
            width: Number(t.width) >= 40 ? Number(t.width) : 90,
            height: Number(t.height) >= 40 ? Number(t.height) : 90,
            shape: t.shape || 'square',
        }))
    },
    { deep: true },
)

watch(
    () => props.layoutObjects,
    (newVal) => {
        layoutObjs.value = (newVal ?? []).map((o) => ({
            ...o,
            width: Number(o.width) >= 20 ? Number(o.width) : 140,
            height: Number(o.height) >= 20 ? Number(o.height) : 70,
        }))
    },
    { deep: true },
)

/* ---------- กรองตามโซน ---------- */

const visibleTables = computed(() => {
    if (activeZone.value === 'all') return layoutTables.value
    return layoutTables.value.filter((t) => t.zone_id === activeZone.value)
})

const visibleObjects = computed(() => {
    if (activeZone.value === 'all') return layoutObjs.value
    return layoutObjs.value.filter((o) => o.zone_id === activeZone.value || !o.zone_id)
})

/* ---------- นิยามประเภทอ็อบเจ็กต์ผังร้าน ---------- */

const COLOR_PRESETS = [
    { label: 'เขียวมรกต (Emerald)', color: '#10b981' },
    { label: 'เหลืองอำพัน (Amber)', color: '#f59e0b' },
    { label: 'ส้มสด (Orange)', color: '#f97316' },
    { label: 'ฟ้าคราม (Sky)', color: '#0ea5e9' },
    { label: 'น้ำเงินม่วง (Indigo)', color: '#6366f1' },
    { label: 'ม่วงสด (Purple)', color: '#a855f7' },
    { label: 'ชมพูกุหลาบ (Rose)', color: '#f43f5e' },
    { label: 'เทากราไฟต์ (Slate)', color: '#475569' },
]

const OBJECT_TYPES: Array<{
    type: FloorPlanObjectType
    label: string
    desc: string
    icon: any
    defaultWidth: number
    defaultHeight: number
    colorClass: string
    badgeClass: string
    iconBadgeClass: string
    accentColor: string
}> = [
    {
        type: 'cashier',
        label: 'จุดชำระเงิน / แคชเชียร์',
        desc: 'เคาน์เตอร์คิดเงินและออกใบเสร็จ',
        icon: CreditCard,
        defaultWidth: 140,
        defaultHeight: 80,
        colorClass: 'bg-emerald-100/95 dark:bg-emerald-950/85 border-emerald-500 text-emerald-950 dark:text-emerald-50 shadow-xs',
        badgeClass: 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-200 border-emerald-300 dark:border-emerald-700',
        iconBadgeClass: 'bg-emerald-600 text-white',
        accentColor: '#10b981',
    },
    {
        type: 'bar',
        label: 'เคาน์เตอร์บาร์ / บาร์น้ำ',
        desc: 'บาร์เครื่องดื่มหรือเคาน์เตอร์บริการ',
        icon: Wine,
        defaultWidth: 180,
        defaultHeight: 80,
        colorClass: 'bg-amber-100/95 dark:bg-amber-950/85 border-amber-500 text-amber-950 dark:text-amber-50 shadow-xs',
        badgeClass: 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-200 border-amber-300 dark:border-amber-700',
        iconBadgeClass: 'bg-amber-600 text-white',
        accentColor: '#f59e0b',
    },
    {
        type: 'kitchen',
        label: 'จุดรับส่งอาหาร / ครัว',
        desc: 'หน้าต่างรับส่งอาหารหรือทางเข้าครัว',
        icon: ChefHat,
        defaultWidth: 180,
        defaultHeight: 80,
        colorClass: 'bg-orange-100/95 dark:bg-orange-950/85 border-orange-500 text-orange-950 dark:text-orange-50 shadow-xs',
        badgeClass: 'bg-orange-100 dark:bg-orange-950/80 text-orange-800 dark:text-orange-200 border-orange-300 dark:border-orange-700',
        iconBadgeClass: 'bg-orange-600 text-white',
        accentColor: '#f97316',
    },
    {
        type: 'entrance',
        label: 'ทางเข้า - ออก',
        desc: 'ประตูทางเข้าหลักหรือทางออกร้าน',
        icon: DoorOpen,
        defaultWidth: 130,
        defaultHeight: 60,
        colorClass: 'bg-sky-100/95 dark:bg-sky-950/85 border-sky-500 text-sky-950 dark:text-sky-50 border-dashed shadow-xs',
        badgeClass: 'bg-sky-100 dark:bg-sky-950/80 text-sky-800 dark:text-sky-200 border-sky-300 dark:border-sky-700',
        iconBadgeClass: 'bg-sky-600 text-white',
        accentColor: '#0ea5e9',
    },
    {
        type: 'restroom',
        label: 'ห้องน้ำ',
        desc: 'ห้องสุขาชาย / หญิง / รวม',
        icon: Users,
        defaultWidth: 110,
        defaultHeight: 60,
        colorClass: 'bg-indigo-100/95 dark:bg-indigo-950/85 border-indigo-500 text-indigo-950 dark:text-indigo-50 shadow-xs',
        badgeClass: 'bg-indigo-100 dark:bg-indigo-950/80 text-indigo-800 dark:text-indigo-200 border-indigo-300 dark:border-indigo-700',
        iconBadgeClass: 'bg-indigo-600 text-white',
        accentColor: '#6366f1',
    },
    {
        type: 'pillar',
        label: 'เสา / ผนัง / ฉากกั้น',
        desc: 'สิ่งกีดขวางหรือโครงสร้างผนัง',
        icon: Columns,
        defaultWidth: 60,
        defaultHeight: 60,
        colorClass: 'bg-slate-300/95 dark:bg-slate-700/95 border-slate-500 text-slate-950 dark:text-slate-50 shadow-xs',
        badgeClass: 'bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 border-slate-400 dark:border-slate-600',
        iconBadgeClass: 'bg-slate-600 text-white',
        accentColor: '#64748b',
    },
    {
        type: 'custom',
        label: 'พื้นที่ / ป้ายข้อความ',
        desc: 'เวที ป้ายกำกับ หรือพื้นที่เฉพาะ',
        icon: Sparkles,
        defaultWidth: 140,
        defaultHeight: 60,
        colorClass: 'bg-purple-100/95 dark:bg-purple-950/85 border-purple-500 text-purple-950 dark:text-purple-50 shadow-xs',
        badgeClass: 'bg-purple-100 dark:bg-purple-950/80 text-purple-800 dark:text-purple-200 border-purple-300 dark:border-purple-700',
        iconBadgeClass: 'bg-purple-600 text-white',
        accentColor: '#a855f7',
    },
]

function getObjectMeta(type: string) {
    return OBJECT_TYPES.find((o) => o.type === type) ?? OBJECT_TYPES[OBJECT_TYPES.length - 1]
}

function getObjectStyle(o: FloorPlanObject) {
    const meta = getObjectMeta(o.type)
    const baseStyle: Record<string, string> = {
        left: `${o.pos_x}px`,
        top: `${o.pos_y}px`,
        width: `${o.width || meta.defaultWidth}px`,
        height: `${o.height || meta.defaultHeight}px`,
    }

    if (o.color) {
        baseStyle.backgroundColor = `${o.color}25`
        baseStyle.borderColor = o.color
    }

    return baseStyle
}

/* ---------- พรีเซ็ตขนาดโต๊ะ ---------- */

const TABLE_PRESETS = [
    { label: '2 ที่นั่ง (80×80)', shape: 'square', width: 80, height: 80, seats: 2 },
    { label: '4 ที่นั่ง จัตุรัส (100×100)', shape: 'square', width: 100, height: 100, seats: 4 },
    { label: '4-6 ที่นั่ง ผืนผ้า (160×80)', shape: 'rectangle', width: 160, height: 80, seats: 4 },
    { label: '4-6 ที่นั่ง แนวตั้ง (80×160)', shape: 'rectangle', width: 80, height: 160, seats: 4 },
    { label: '6-8 ที่นั่ง ตัวยาว (220×90)', shape: 'rectangle', width: 220, height: 90, seats: 6 },
    { label: 'โต๊ะกลม 4 ที่นั่ง (100×100)', shape: 'circle', width: 100, height: 100, seats: 4 },
    { label: 'โต๊ะกลมใหญ่ 8 ที่นั่ง (140×140)', shape: 'circle', width: 140, height: 140, seats: 8 },
]

/* ---------- การเลือกและการลากวางใน Canvas ---------- */

interface SelectedItem {
    kind: 'table' | 'object'
    id: number
}

interface DropPreview {
    x: number
    y: number
    width: number
    height: number
    shape?: string
    name: string
    color?: string | null
    kind: 'table' | 'object'
    type?: string
}

const canvasContainer = ref<HTMLElement | null>(null)
const canvasInner = ref<HTMLElement | null>(null)

const selected = ref<SelectedItem | null>(null)
const draggingTarget = ref<{ kind: 'table' | 'object'; id: number; grabOffsetX: number; grabOffsetY: number } | null>(null)
const dropPreview = ref<DropPreview | null>(null)
const isSaving = ref(false)

const selectedTable = computed(() => {
    if (selected.value?.kind !== 'table') return null
    return layoutTables.value.find((t) => t.id === selected.value!.id) ?? null
})

const selectedObject = computed(() => {
    if (selected.value?.kind !== 'object') return null
    return layoutObjs.value.find((o) => o.id === selected.value!.id) ?? null
})

function calculateSnappedPos(event: DragEvent) {
    if (!draggingTarget.value) return null

    const target = draggingTarget.value
    const offsetX = target.grabOffsetX || 40
    const offsetY = target.grabOffsetY || 40

    // พิกัดเทียบกับ origin ของ canvasInner
    const innerElem = canvasInner.value
    const originLeft = innerElem ? innerElem.getBoundingClientRect().left : 0
    const originTop = innerElem ? innerElem.getBoundingClientRect().top : 0

    const rawX = (event.clientX - offsetX - originLeft) / zoom.value
    const rawY = (event.clientY - offsetY - originTop) / zoom.value

    const snappedX = Math.max(0, Math.round(rawX / GRID) * GRID)
    const snappedY = Math.max(0, Math.round(rawY / GRID) * GRID)

    return { snappedX, snappedY }
}

function onDragStart(event: DragEvent, kind: 'table' | 'object', id: number) {
    const elem = (event.target as HTMLElement).getBoundingClientRect()
    draggingTarget.value = {
        kind,
        id,
        grabOffsetX: event.clientX - elem.left,
        grabOffsetY: event.clientY - elem.top,
    }
    selected.value = { kind, id }

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move'
    }
}

function onDragOver(event: DragEvent) {
    event.preventDefault()
    if (!draggingTarget.value) return

    const pos = calculateSnappedPos(event)
    if (!pos) return

    const target = draggingTarget.value

    if (target.kind === 'table') {
        const t = layoutTables.value.find((item) => item.id === target.id)
        if (t) {
            dropPreview.value = {
                x: pos.snappedX,
                y: pos.snappedY,
                width: t.width || 90,
                height: t.height || 90,
                shape: t.shape || 'square',
                name: t.name,
                kind: 'table',
            }
        }
    } else {
        const o = layoutObjs.value.find((item) => item.id === target.id)
        if (o) {
            const meta = getObjectMeta(o.type)
            dropPreview.value = {
                x: pos.snappedX,
                y: pos.snappedY,
                width: o.width || meta.defaultWidth,
                height: o.height || meta.defaultHeight,
                name: o.name,
                kind: 'object',
                type: o.type,
                color: o.color,
            }
        }
    }
}

function onDragLeave(event: DragEvent) {
    const related = event.relatedTarget as HTMLElement | null
    if (!related || !canvasContainer.value?.contains(related)) {
        dropPreview.value = null
    }
}

function onDragEnd() {
    draggingTarget.value = null
    dropPreview.value = null
}

function onDrop(event: DragEvent) {
    event.preventDefault()
    if (!draggingTarget.value) return

    const pos = calculateSnappedPos(event)
    const target = draggingTarget.value

    if (pos) {
        if (target.kind === 'table') {
            const t = layoutTables.value.find((item) => item.id === target.id)
            if (t) {
                t.pos_x = pos.snappedX
                t.pos_y = pos.snappedY
            }
        } else {
            const o = layoutObjs.value.find((item) => item.id === target.id)
            if (o) {
                o.pos_x = pos.snappedX
                o.pos_y = pos.snappedY
            }
        }
    }

    draggingTarget.value = null
    dropPreview.value = null
}

/* ---------- ฟังก์ชันปรับขนาดด่วนบน Selection ---------- */

function adjustSelectedWidth(delta: number) {
    if (selectedTable.value) {
        const next = Math.max(60, Math.min(600, (selectedTable.value.width || 80) + delta))
        selectedTable.value.width = next
        if (selectedTable.value.shape === 'square' || selectedTable.value.shape === 'circle') {
            selectedTable.value.shape = 'rectangle'
        }
    } else if (selectedObject.value) {
        const next = Math.max(40, Math.min(1000, (selectedObject.value.width || 120) + delta))
        selectedObject.value.width = next
    }
}

function adjustSelectedHeight(delta: number) {
    if (selectedTable.value) {
        const next = Math.max(60, Math.min(600, (selectedTable.value.height || 80) + delta))
        selectedTable.value.height = next
        if (selectedTable.value.shape === 'square' || selectedTable.value.shape === 'circle') {
            selectedTable.value.shape = 'rectangle'
        }
    } else if (selectedObject.value) {
        const next = Math.max(30, Math.min(800, (selectedObject.value.height || 60) + delta))
        selectedObject.value.height = next
    }
}

function rotateSelected() {
    if (selectedTable.value) {
        const w = selectedTable.value.width || 80
        const h = selectedTable.value.height || 80
        selectedTable.value.width = h
        selectedTable.value.height = w
    } else if (selectedObject.value) {
        const w = selectedObject.value.width || 120
        const h = selectedObject.value.height || 60
        selectedObject.value.width = h
        selectedObject.value.height = w
    }
}

function setTableShape(shape: string) {
    if (!selectedTable.value) return
    selectedTable.value.shape = shape
    if (shape === 'circle' || shape === 'square') {
        const size = Math.max(selectedTable.value.width || 80, selectedTable.value.height || 80)
        selectedTable.value.width = size
        selectedTable.value.height = size
    }
}

/* ---------- บันทึกผังร้าน ---------- */

function saveLayout() {
    isSaving.value = true
    router.put(
        '/backoffice/tables/layout',
        {
            tables: layoutTables.value.map((t) => ({
                id: t.id,
                pos_x: t.pos_x,
                pos_y: t.pos_y,
                width: t.width,
                height: t.height,
                shape: t.shape,
            })),
            objects: layoutObjs.value.map((o) => ({
                id: o.id,
                pos_x: o.pos_x,
                pos_y: o.pos_y,
                width: o.width,
                height: o.height,
            })),
        },
        {
            preserveScroll: true,
            onFinish: () => {
                isSaving.value = false
            },
        },
    )
}

/* ---------- Modal จัดการโต๊ะ (เพิ่ม / แก้ไข) ---------- */

const showTableModal = ref(false)
const editingTable = ref<DiningTable | null>(null)

const tableForm = useForm({
    name: '',
    zone_id: '' as number | string,
    seats: 4,
    pos_x: 0,
    pos_y: 0,
    width: 90,
    height: 90,
    shape: 'square',
    is_active: true,
})

function openCreateTable(preset?: (typeof TABLE_PRESETS)[0]) {
    editingTable.value = null
    tableForm.reset()
    tableForm.clearErrors()

    if (preset) {
        tableForm.shape = preset.shape
        tableForm.width = preset.width
        tableForm.height = preset.height
        tableForm.seats = preset.seats
    } else {
        tableForm.shape = 'square'
        tableForm.width = 90
        tableForm.height = 90
        tableForm.seats = 4
    }

    if (activeZone.value !== 'all') {
        tableForm.zone_id = activeZone.value
    }

    showTableModal.value = true
}

function openEditTable(table: DiningTable) {
    editingTable.value = table
    tableForm.clearErrors()
    tableForm.defaults({
        name: table.name,
        zone_id: table.zone_id ?? '',
        seats: table.seats,
        pos_x: table.pos_x,
        pos_y: table.pos_y,
        width: table.width || 90,
        height: table.height || 90,
        shape: table.shape || 'square',
        is_active: table.is_active ?? true,
    })
    tableForm.reset()
    showTableModal.value = true
}

function applyPreset(p: (typeof TABLE_PRESETS)[0]) {
    tableForm.shape = p.shape
    tableForm.width = p.width
    tableForm.height = p.height
    tableForm.seats = p.seats
}

function submitTable() {
    if (editingTable.value) {
        tableForm.put(`/backoffice/tables/${editingTable.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showTableModal.value = false
                editingTable.value = null
            },
        })
    } else {
        tableForm.post('/backoffice/tables', {
            preserveScroll: true,
            onSuccess: () => {
                showTableModal.value = false
                tableForm.reset()
            },
        })
    }
}

function removeTable(table?: DiningTable | null) {
    if (!table?.id) return
    if (!confirm(`ต้องการลบโต๊ะ ${table.name} ใช่หรือไม่?`)) return

    router.delete(`/backoffice/tables/${table.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            layoutTables.value = layoutTables.value.filter((t) => t.id !== table.id)
            if (selected.value?.kind === 'table' && selected.value.id === table.id) {
                selected.value = null
            }
            showTableModal.value = false
            editingTable.value = null
        },
    })
}

/* ---------- Modal จัดการอ็อบเจ็กต์ผังร้าน (เพิ่ม / แก้ไข) ---------- */

const showObjectModal = ref(false)
const editingObject = ref<FloorPlanObject | null>(null)

const objectForm = useForm({
    type: 'cashier' as FloorPlanObjectType,
    name: '',
    zone_id: '' as number | string,
    pos_x: 0,
    pos_y: 0,
    width: 140,
    height: 70,
    color: '',
    icon: '',
    is_active: true,
})

function openCreateObject(type: FloorPlanObjectType = 'cashier') {
    editingObject.value = null
    objectForm.reset()
    objectForm.clearErrors()

    const meta = getObjectMeta(type)
    objectForm.type = type
    objectForm.name = meta.label
    objectForm.width = meta.defaultWidth
    objectForm.height = meta.defaultHeight

    if (activeZone.value !== 'all') {
        objectForm.zone_id = activeZone.value
    }

    showObjectModal.value = true
}

function openEditObject(obj: FloorPlanObject) {
    editingObject.value = obj
    objectForm.clearErrors()
    objectForm.defaults({
        type: obj.type,
        name: obj.name,
        zone_id: obj.zone_id ?? '',
        pos_x: obj.pos_x,
        pos_y: obj.pos_y,
        width: obj.width || 140,
        height: obj.height || 70,
        color: obj.color ?? '',
        icon: obj.icon ?? '',
        is_active: obj.is_active ?? true,
    })
    objectForm.reset()
    showObjectModal.value = true
}

function onObjectTypeChange(type: FloorPlanObjectType) {
    const meta = getObjectMeta(type)
    if (!editingObject.value || !objectForm.name) {
        objectForm.name = meta.label
    }
    objectForm.width = meta.defaultWidth
    objectForm.height = meta.defaultHeight
}

function submitObject() {
    if (editingObject.value) {
        objectForm.put(`/backoffice/tables/objects/${editingObject.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showObjectModal.value = false
                editingObject.value = null
            },
        })
    } else {
        objectForm.post('/backoffice/tables/objects', {
            preserveScroll: true,
            onSuccess: () => {
                showObjectModal.value = false
                objectForm.reset()
            },
        })
    }
}

function removeObject(obj?: FloorPlanObject | null) {
    if (!obj?.id) return
    if (!confirm(`ต้องการลบ ${obj.name} ออกจากผังร้านใช่หรือไม่?`)) return

    router.delete(`/backoffice/tables/objects/${obj.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            layoutObjs.value = layoutObjs.value.filter((o) => o.id !== obj.id)
            if (selected.value?.kind === 'object' && selected.value.id === obj.id) {
                selected.value = null
            }
            showObjectModal.value = false
            editingObject.value = null
        },
    })
}

/* ---------- Modal เพิ่มโซน ---------- */

const showZoneModal = ref(false)
const zoneForm = useForm({ name: '' })

function submitZone() {
    zoneForm.post('/backoffice/tables/zones', {
        preserveScroll: true,
        onSuccess: () => {
            showZoneModal.value = false
            zoneForm.reset()
        },
    })
}
</script>

<template>
    <Head title="ผังโต๊ะและสิ่งอำนวยความสะดวก" />

    <BackOfficeLayout title="ผังโต๊ะ">
        <div class="space-y-4">
            <!-- แถบด้านบน: สลับมุมมอง, กรองโซน และปุ่มบันทึก -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3 shadow-xs">
                <!-- โซน -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="mr-1 text-xs font-semibold text-muted-foreground">โซน:</span>
                    <Button
                        :variant="activeZone === 'all' ? 'brand' : 'outline'"
                        size="sm"
                        class="h-8 text-xs"
                        @click="activeZone = 'all'"
                    >
                        ทั้งหมด ({{ tables.length }})
                    </Button>
                    <Button
                        v-for="z in zones"
                        :key="z.id"
                        :variant="activeZone === z.id ? 'brand' : 'outline'"
                        size="sm"
                        class="h-8 text-xs"
                        @click="activeZone = z.id"
                    >
                        {{ z.name }} ({{ tables.filter((t) => t.zone_id === z.id).length }})
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-8 text-xs text-muted-foreground"
                        title="เพิ่มโซนใหม่"
                        @click="showZoneModal = true"
                    >
                        <Plus class="size-3.5 mr-1" />
                        เพิ่มโซน
                    </Button>
                </div>

                <!-- มุมมอง & เครื่องมือหลัก -->
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex rounded-lg border bg-muted/40 p-0.5 text-xs">
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-md px-2.5 py-1 font-medium transition-all"
                            :class="viewMode === 'designer' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            @click="viewMode = 'designer'"
                        >
                            <LayoutGrid class="size-3.5" />
                            ผังร้าน (Floor Plan)
                        </button>
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-md px-2.5 py-1 font-medium transition-all"
                            :class="viewMode === 'list' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            @click="viewMode = 'list'"
                        >
                            <List class="size-3.5" />
                            ตารางโต๊ะ
                        </button>
                    </div>

                    <Link
                        href="/backoffice/tables/qr"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-xs font-medium hover:bg-accent"
                    >
                        <QrCode class="size-3.5" />
                        พิมพ์ QR สั่งอาหาร
                    </Link>

                    <Button
                        v-if="viewMode === 'designer'"
                        variant="brand"
                        size="sm"
                        class="h-8 font-medium shadow-xs"
                        :disabled="isSaving"
                        @click="saveLayout"
                    >
                        <Save class="size-3.5 mr-1.5" />
                        {{ isSaving ? 'กำลังบันทึก...' : 'บันทึกผังร้าน' }}
                    </Button>
                </div>
            </div>

            <!-- ══ โหมด 1: DESIGNER ผังร้าน ══ -->
            <div v-if="viewMode === 'designer'" class="space-y-3">
                <!-- แถบเครื่องมือเพิ่มอ็อบเจ็กต์รวดเร็ว -->
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-card px-4 py-2.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold text-muted-foreground">เพิ่มในผัง:</span>

                        <!-- เพิ่มโต๊ะ -->
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-7 text-xs font-medium border-primary/30 hover:border-primary"
                            @click="openCreateTable()"
                        >
                            <Plus class="size-3 mr-1 text-primary" />
                            โต๊ะอาหาร
                        </Button>

                        <div class="h-4 w-px bg-border mx-1" />

                        <!-- อ็อบเจ็กต์ร้านค้า -->
                        <button
                            v-for="obj in OBJECT_TYPES"
                            :key="obj.type"
                            type="button"
                            class="inline-flex h-7 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-all hover:scale-105 active:scale-95"
                            :class="obj.badgeClass"
                            :title="obj.desc"
                            @click="openCreateObject(obj.type)"
                        >
                            <component :is="obj.icon" class="size-3.5" />
                            {{ obj.label.split('/')[0].trim() }}
                        </button>
                    </div>

                    <!-- Zoom Controls -->
                    <div class="flex items-center gap-1 text-xs text-muted-foreground">
                        <span>ซูม:</span>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-7 w-7 p-0"
                            :disabled="zoom <= 0.7"
                            @click="zoom = Math.max(0.6, Number((zoom - 0.1).toFixed(1)))"
                        >
                            <Minimize2 class="size-3.5" />
                        </Button>
                        <span class="tabular font-mono text-xs w-9 text-center">{{ Math.round(zoom * 100) }}%</span>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-7 w-7 p-0"
                            :disabled="zoom >= 1.4"
                            @click="zoom = Math.min(1.4, Number((zoom + 0.1).toFixed(1)))"
                        >
                            <Maximize2 class="size-3.5" />
                        </Button>
                    </div>
                </div>

                <!-- Canvas ผังร้าน -->
                <div class="relative rounded-xl border bg-card shadow-inner overflow-hidden">
                    <div
                        ref="canvasContainer"
                        class="relative h-[620px] w-full overflow-auto p-4 select-none floorplan-canvas"
                        @dragover="onDragOver"
                        @dragleave="onDragLeave"
                        @drop="onDrop"
                        @click="selected = null"
                    >
                        <div
                            ref="canvasInner"
                            class="relative min-h-[900px] min-w-[1300px] origin-top-left transition-transform duration-75"
                            :style="{ transform: `scale(${zoom})` }"
                        >
                            <!-- พื้นตัวอย่างตำแหน่งที่จะวาง (Drop Target Preview / Ghost Placeholder) -->
                            <div
                                v-if="dropPreview"
                                class="pointer-events-none absolute z-30 flex flex-col items-center justify-center border-2 border-dashed border-primary bg-primary/20 shadow-xl backdrop-blur-xs transition-all duration-75 ring-2 ring-primary/40 ring-offset-2 ring-offset-background animate-pulse"
                                :class="[
                                    dropPreview.shape === 'circle'
                                        ? 'rounded-full'
                                        : (dropPreview.type === 'pillar' ? 'rounded-lg' : 'rounded-2xl'),
                                ]"
                                :style="{
                                    left: `${dropPreview.x}px`,
                                    top: `${dropPreview.y}px`,
                                    width: `${dropPreview.width}px`,
                                    height: `${dropPreview.height}px`,
                                    ...(dropPreview.color ? { borderColor: dropPreview.color, backgroundColor: `${dropPreview.color}35` } : {}),
                                }"
                            >
                                <div
                                    class="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold text-white shadow-xs"
                                    :style="{ backgroundColor: dropPreview.color || 'var(--primary)' }"
                                >
                                    <Grid class="size-3" />
                                    <span>{{ dropPreview.x }}, {{ dropPreview.y }}</span>
                                </div>
                                <span class="mt-0.5 truncate text-[11px] font-extrabold text-foreground drop-shadow-xs max-w-[90%] px-1 text-center">
                                    {{ dropPreview.name }}
                                </span>
                            </div>

                            <!-- 1. สิ่งอำนวยความสะดวก / อ็อบเจ็กต์ผังร้าน -->
                            <div
                                v-for="o in visibleObjects"
                                :key="`obj-${o.id}`"
                                draggable="true"
                                class="absolute flex flex-col items-center justify-center p-2 text-center cursor-move border-2 shadow-xs transition-all select-none group"
                                :class="[
                                    !o.color && getObjectMeta(o.type).colorClass,
                                    o.type === 'pillar' ? 'rounded-lg' : 'rounded-2xl',
                                    selected?.kind === 'object' && selected?.id === o.id
                                        ? 'ring-2 ring-primary ring-offset-2 ring-offset-background z-20 shadow-xl scale-[1.02]'
                                        : 'z-10 hover:shadow-md hover:scale-[1.01]',
                                    draggingTarget?.kind === 'object' && draggingTarget?.id === o.id ? 'opacity-35 scale-95 ring-2 ring-dashed ring-primary' : '',
                                ]"
                                :style="getObjectStyle(o)"
                                @dragstart="onDragStart($event, 'object', o.id)"
                                @dragend="onDragEnd"
                                @click.stop="selected = { kind: 'object', id: o.id }"
                            >
                                <div class="flex items-center justify-center gap-1.5 max-w-full px-1">
                                    <span
                                        class="flex size-6 shrink-0 items-center justify-center rounded-md shadow-2xs font-bold"
                                        :class="getObjectMeta(o.type).iconBadgeClass"
                                        :style="o.color ? { backgroundColor: o.color, color: '#ffffff' } : {}"
                                    >
                                        <component :is="getObjectMeta(o.type).icon" class="size-3.5" />
                                    </span>
                                    <span class="truncate text-xs font-bold tracking-tight">{{ o.name }}</span>
                                </div>
                                <span
                                    v-if="o.zone || o.type === 'pillar'"
                                    class="text-[10px] font-semibold opacity-85 truncate max-w-full mt-0.5"
                                >
                                    {{ o.zone?.name || (o.type === 'pillar' ? 'โครงสร้าง' : '') }}
                                </span>
                            </div>

                            <!-- 2. โต๊ะอาหาร -->
                            <div
                                v-for="t in visibleTables"
                                :key="`tbl-${t.id}`"
                                draggable="true"
                                class="absolute flex flex-col items-center justify-center p-2 text-center cursor-move border-2 bg-card shadow-sm transition-all hover:shadow-md select-none group"
                                :class="[
                                    t.shape === 'circle' ? 'rounded-full' : 'rounded-xl',
                                    t.is_active === false ? 'opacity-50 border-dashed' : 'border-border',
                                    selected?.kind === 'table' && selected?.id === t.id
                                        ? 'border-primary ring-2 ring-primary ring-offset-2 ring-offset-background z-20 shadow-lg'
                                        : 'hover:border-primary/60 z-10',
                                    draggingTarget?.kind === 'table' && draggingTarget?.id === t.id ? 'opacity-35 scale-95 ring-2 ring-dashed ring-primary' : '',
                                ]"
                                :style="{
                                    left: `${t.pos_x}px`,
                                    top: `${t.pos_y}px`,
                                    width: `${t.width || 90}px`,
                                    height: `${t.height || 90}px`,
                                }"
                                @dragstart="onDragStart($event, 'table', t.id)"
                                @dragend="onDragEnd"
                                @click.stop="selected = { kind: 'table', id: t.id }"
                            >
                                <div class="min-w-0 px-1 text-center">
                                    <p class="font-bold text-sm leading-tight text-foreground truncate">
                                        {{ t.name }}
                                    </p>
                                    <p class="text-[11px] text-muted-foreground flex items-center justify-center gap-1 mt-0.5">
                                        <Armchair class="size-3" />
                                        {{ t.seats }} ที่นั่ง
                                    </p>
                                    <p v-if="t.zone && activeZone === 'all'" class="text-[10px] text-muted-foreground/80 truncate">
                                        {{ t.zone.name }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- แถบควบคุมรายการที่เลือก (Floating Action Bar) -->
                    <div
                        v-if="selected"
                        class="absolute bottom-4 left-1/2 -translate-x-1/2 flex flex-wrap items-center gap-2 rounded-xl border bg-card/95 backdrop-blur-md px-4 py-2.5 shadow-xl z-30 animate-in fade-in slide-in-from-bottom-3 duration-150"
                        @click.stop
                    >
                        <div class="flex items-center gap-2 pr-2 border-r">
                            <span class="text-xs font-bold text-foreground">
                                {{ selectedTable ? `โต๊ะ: ${selectedTable.name}` : `อ็อบเจ็กต์: ${selectedObject?.name}` }}
                            </span>
                            <Badge variant="outline" class="text-[10px] py-0">
                                {{ selectedTable ? `${selectedTable.width || 90}×${selectedTable.height || 90} px` : `${selectedObject?.width}×${selectedObject?.height} px` }}
                            </Badge>
                        </div>

                        <!-- ปรับรูปทรงโต๊ะ -->
                        <div v-if="selectedTable" class="flex items-center gap-1 border-r pr-2">
                            <button
                                type="button"
                                class="rounded p-1 text-xs hover:bg-accent"
                                :class="selectedTable.shape === 'square' && 'bg-accent text-primary font-bold'"
                                title="สี่เหลี่ยมจัตุรัส"
                                @click="setTableShape('square')"
                            >
                                จัตุรัส
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-xs hover:bg-accent"
                                :class="selectedTable.shape === 'rectangle' && 'bg-accent text-primary font-bold'"
                                title="สี่เหลี่ยมผืนผ้า"
                                @click="setTableShape('rectangle')"
                            >
                                ผืนผ้า
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-xs hover:bg-accent"
                                :class="selectedTable.shape === 'circle' && 'bg-accent text-primary font-bold'"
                                title="วงกลม"
                                @click="setTableShape('circle')"
                            >
                                วงกลม
                            </button>
                        </div>

                        <!-- ปรับความกว้าง / ความสูง -->
                        <div class="flex items-center gap-1 border-r pr-2 text-xs">
                            <span class="text-muted-foreground">กว้าง:</span>
                            <Button variant="outline" size="sm" class="h-6 w-6 p-0 text-xs" @click="adjustSelectedWidth(-10)">-</Button>
                            <Button variant="outline" size="sm" class="h-6 w-6 p-0 text-xs" @click="adjustSelectedWidth(10)">+</Button>

                            <span class="text-muted-foreground ml-1">สูง:</span>
                            <Button variant="outline" size="sm" class="h-6 w-6 p-0 text-xs" @click="adjustSelectedHeight(-10)">-</Button>
                            <Button variant="outline" size="sm" class="h-6 w-6 p-0 text-xs" @click="adjustSelectedHeight(10)">+</Button>

                            <Button
                                variant="outline"
                                size="sm"
                                class="h-6 px-1.5 text-xs ml-1"
                                title="หมุน / สลับความกว้างและความยาว"
                                @click="rotateSelected"
                            >
                                <RotateCw class="size-3 mr-1" />
                                สลับ W/H
                            </Button>
                        </div>

                        <!-- แก้ไข / ลบ -->
                        <div class="flex items-center gap-1">
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-7 text-xs"
                                @click="selectedTable ? openEditTable(selectedTable) : (selectedObject ? openEditObject(selectedObject) : null)"
                            >
                                <Pencil class="size-3 mr-1" />
                                แก้ไข
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 text-xs text-destructive hover:bg-destructive/10"
                                @click="selectedTable ? removeTable(selectedTable) : (selectedObject ? removeObject(selectedObject) : null)"
                            >
                                <Trash2 class="size-3 mr-1" />
                                ลบ
                            </Button>
                            <Button variant="ghost" size="sm" class="h-7 w-7 p-0 ml-1" @click="selected = null">
                                <X class="size-3.5" />
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between text-xs text-muted-foreground px-1">
                    <p>💡 คลิกที่โต๊ะหรืออ็อบเจ็กต์เพื่อปรับขนาด กว้าง×ยาว หรือหมุนแนวราบ-แนวตั้งได้ทันที</p>
                    <p>อย่าลืมกด <strong>"บันทึกผังร้าน"</strong> เพื่อบันทึกพิกัดและขนาดทั้งหมด</p>
                </div>
            </div>

            <!-- ══ โหมด 2: ตารางรายการโต๊ะ ══ -->
            <SectionCard v-else title="รายการโต๊ะอาหาร" content-class="p-0">
                <template #actions>
                    <Button variant="brand" size="sm" @click="openCreateTable()">
                        <Plus />
                        เพิ่มโต๊ะ
                    </Button>
                </template>

                <DataTable v-if="tables.length">
                    <thead>
                        <tr>
                            <th>ชื่อโต๊ะ</th>
                            <th>โซน</th>
                            <th>จำนวนที่นั่ง</th>
                            <th>สถานะ</th>
                            <th class="text-right">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in tables" :key="t.id">
                            <td class="font-bold">{{ t.name }}</td>
                            <td>
                                <Badge v-if="t.zone" variant="secondary">{{ t.zone.name }}</Badge>
                                <span v-else class="text-xs text-muted-foreground">-</span>
                            </td>
                            <td>{{ t.seats }} ที่นั่ง</td>
                            <td>
                                <Badge :variant="t.is_active ? 'default' : 'secondary'">
                                    {{ t.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}
                                </Badge>
                            </td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <Button variant="ghost" size="icon" @click="openEditTable(t)">
                                        <Pencil />
                                    </Button>
                                    <Button variant="ghost" size="icon" class="text-destructive hover:bg-destructive/10" @click="removeTable(t)">
                                        <Trash2 />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </DataTable>
                <EmptyState v-else description="ยังไม่มีโต๊ะในระบบ — กด เพิ่มโต๊ะ เพื่อเริ่มต้น" />
            </SectionCard>
        </div>

        <!-- ══ MODAL 1: เพิ่ม / แก้ไขโต๊ะ ══ -->
        <Modal
            v-model:open="showTableModal"
            :title="editingTable ? `แก้ไขโต๊ะ ${editingTable.name}` : 'เพิ่มโต๊ะอาหาร'"
            description="ระบุชื่อ จำนวนที่นั่ง และขนาดความกว้าง-ยาวของโต๊ะ"
        >
            <form class="space-y-4" @submit.prevent="submitTable">
                <!-- พรีเซ็ตด่วนตอนสร้างโต๊ะใหม่ -->
                <div v-if="!editingTable" class="space-y-1.5">
                    <Label class="text-xs text-muted-foreground">เลือกขนาดและรูปทรงมาตรฐาน:</Label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
                        <button
                            v-for="p in TABLE_PRESETS"
                            :key="p.label"
                            type="button"
                            class="rounded-lg border px-2 py-1.5 text-left text-xs transition-all hover:bg-accent hover:border-primary"
                            :class="tableForm.width === p.width && tableForm.height === p.height && tableForm.shape === p.shape ? 'border-primary bg-primary/10 font-bold' : 'border-border'"
                            @click="applyPreset(p)"
                        >
                            {{ p.label }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="tname">ชื่อโต๊ะ</Label>
                        <Input id="tname" v-model="tableForm.name" required placeholder="A1, B2, VIP1" />
                        <p v-if="tableForm.errors.name" class="text-xs text-destructive">{{ tableForm.errors.name }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="tzone">โซน</Label>
                        <Select id="tzone" v-model="tableForm.zone_id">
                            <option value="">ทุกโซน / ไม่ระบุ</option>
                            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
                        </Select>
                    </div>
                </div>

                <div class="space-y-1">
                    <Label for="tseats">จำนวนที่นั่ง</Label>
                    <Input id="tseats" v-model.number="tableForm.seats" type="number" min="1" max="50" required />
                </div>

                <!-- ปรับขนาด กว้าง x ยาว และรูปทรง -->
                <div class="rounded-lg border bg-muted/30 p-3 space-y-3">
                    <div class="text-xs font-semibold text-muted-foreground">ขนาดและรูปทรงโต๊ะในผัง</div>

                    <div class="grid grid-cols-3 gap-2">
                        <button
                            type="button"
                            class="rounded-md border p-2 text-xs text-center transition-all"
                            :class="tableForm.shape === 'square' ? 'border-primary bg-primary/10 font-bold' : 'border-border bg-card'"
                            @click="tableForm.shape = 'square'"
                        >
                            จัตุรัส
                        </button>
                        <button
                            type="button"
                            class="rounded-md border p-2 text-xs text-center transition-all"
                            :class="tableForm.shape === 'rectangle' ? 'border-primary bg-primary/10 font-bold' : 'border-border bg-card'"
                            @click="tableForm.shape = 'rectangle'"
                        >
                            ผืนผ้า
                        </button>
                        <button
                            type="button"
                            class="rounded-md border p-2 text-xs text-center transition-all"
                            :class="tableForm.shape === 'circle' ? 'border-primary bg-primary/10 font-bold' : 'border-border bg-card'"
                            @click="tableForm.shape = 'circle'"
                        >
                            วงกลม
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="twidth" class="text-xs">ความกว้าง (px)</Label>
                            <Input id="twidth" v-model.number="tableForm.width" type="number" min="40" max="600" step="10" />
                        </div>
                        <div class="space-y-1">
                            <Label for="theight" class="text-xs">ความยาว (px)</Label>
                            <Input id="theight" v-model.number="tableForm.height" type="number" min="40" max="600" step="10" />
                        </div>
                    </div>

                    <!-- Preview จิ๋ว -->
                    <div class="flex items-center justify-center pt-2">
                        <div
                            class="flex items-center justify-center border-2 border-primary/60 bg-card text-xs font-bold text-foreground shadow-xs"
                            :class="tableForm.shape === 'circle' ? 'rounded-full' : 'rounded-lg'"
                            :style="{
                                width: `${Math.min(180, tableForm.width * 0.7)}px`,
                                height: `${Math.min(140, tableForm.height * 0.7)}px`,
                            }"
                        >
                            {{ tableForm.name || 'ตัวอย่าง' }} ({{ tableForm.seats }} ที่นั่ง)
                        </div>
                    </div>
                </div>

                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input v-model="tableForm.is_active" type="checkbox" class="size-4 rounded border-input" />
                    เปิดใช้งานโต๊ะนี้
                </label>

                <div class="flex items-center justify-between pt-2">
                    <div>
                        <Button
                            v-if="editingTable"
                            type="button"
                            variant="ghost"
                            class="text-xs text-destructive hover:bg-destructive/10"
                            @click="removeTable(editingTable)"
                        >
                            <Trash2 class="size-3.5 mr-1" /> ลบโต๊ะนี้
                        </Button>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button type="button" variant="outline" @click="showTableModal = false">ยกเลิก</Button>
                        <Button type="submit" variant="brand" :disabled="tableForm.processing">
                            {{ editingTable ? 'บันทึกการแก้ไข' : 'เพิ่มโต๊ะ' }}
                        </Button>
                    </div>
                </div>
            </form>
        </Modal>

        <!-- ══ MODAL 2: เพิ่ม / แก้ไขอ็อบเจ็กต์ผังร้าน (เคาน์เตอร์, บาร์, ทางเข้า ฯลฯ) ══ -->
        <Modal
            v-model:open="showObjectModal"
            :title="editingObject ? `แก้ไข ${editingObject.name}` : 'เพิ่มอ็อบเจ็กต์ในผังร้าน'"
            description="เช่น เคาน์เตอร์แคชเชียร์, บาร์เครื่องดื่ม, จุดรับอาหาร, ทางเข้า, ห้องน้ำ"
        >
            <form class="space-y-4" @submit.prevent="submitObject">
                <!-- เลือกประเภท -->
                <div class="space-y-1.5">
                    <Label>ประเภทอ็อบเจ็กต์</Label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        <button
                            v-for="obj in OBJECT_TYPES"
                            :key="obj.type"
                            type="button"
                            class="flex flex-col items-center justify-center gap-1.5 rounded-lg border p-2.5 text-center transition-all hover:border-primary"
                            :class="objectForm.type === obj.type ? 'border-primary bg-primary/10 font-bold ring-1 ring-primary' : 'border-border bg-card'"
                            @click="objectForm.type = obj.type; onObjectTypeChange(obj.type)"
                        >
                            <component :is="obj.icon" class="size-5" />
                            <span class="text-xs leading-tight">{{ obj.label.split('/')[0].trim() }}</span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="oname">ชื่อ / ป้ายกำกับ</Label>
                        <Input id="oname" v-model="objectForm.name" required placeholder="เช่น แคชเชียร์ 1" />
                        <p v-if="objectForm.errors.name" class="text-xs text-destructive">{{ objectForm.errors.name }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="ozone">โซน (ถ้ามี)</Label>
                        <Select id="ozone" v-model="objectForm.zone_id">
                            <option value="">ทุกโซน / ไม่ระบุ</option>
                            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
                        </Select>
                    </div>
                </div>

                <!-- ปรับขนาด กว้าง x ยาว -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="owidth">ความกว้าง (Width px)</Label>
                        <Input
                            id="owidth"
                            v-model.number="objectForm.width"
                            type="number"
                            step="10"
                            min="20"
                            max="1000"
                            required
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="oheight">ความยาว/สูง (Height px)</Label>
                        <Input
                            id="oheight"
                            v-model.number="objectForm.height"
                            type="number"
                            step="10"
                            min="20"
                            max="800"
                            required
                        />
                    </div>
                </div>

                <!-- ปรับสีพื้นหลัง / สีประจำอ็อบเจ็กต์ -->
                <div class="space-y-2 rounded-lg border bg-muted/20 p-3">
                    <div class="flex items-center justify-between">
                        <Label class="text-xs font-semibold">สีพื้นหลัง / ไฮไลต์ประจำอ็อบเจ็กต์</Label>
                        <span v-if="objectForm.color" class="text-[11px] font-mono font-bold" :style="{ color: objectForm.color }">
                            {{ objectForm.color }}
                        </span>
                        <span v-else class="text-[11px] text-muted-foreground font-medium">ตามค่าเริ่มต้นของหมวดหมู่</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        <button
                            type="button"
                            class="h-7 px-2.5 rounded-md border text-xs font-medium transition-all"
                            :class="!objectForm.color ? 'border-primary ring-2 ring-primary/40 bg-card font-bold' : 'border-border bg-card/60 text-muted-foreground hover:text-foreground'"
                            @click="objectForm.color = ''"
                        >
                            ค่าเริ่มต้น
                        </button>
                        <button
                            v-for="preset in COLOR_PRESETS"
                            :key="preset.color"
                            type="button"
                            class="size-7 rounded-full border-2 transition-transform hover:scale-110 active:scale-95 flex items-center justify-center cursor-pointer"
                            :class="objectForm.color === preset.color ? 'ring-2 ring-primary ring-offset-2 scale-110' : 'border-transparent shadow-2xs'"
                            :style="{ backgroundColor: preset.color }"
                            :title="preset.label"
                            @click="objectForm.color = preset.color"
                        />
                        <div class="relative flex items-center">
                            <input
                                v-model="objectForm.color"
                                type="color"
                                class="size-7 rounded-md border p-0.5 cursor-pointer bg-card"
                                title="เลือกสีกำหนดเอง"
                            />
                        </div>
                    </div>

                    <!-- พรีวิวอ็อบเจ็กต์ -->
                    <div class="flex items-center justify-center pt-2">
                        <div
                            class="flex flex-col items-center justify-center border-2 rounded-xl p-2 text-center shadow-xs transition-all"
                            :class="!objectForm.color && getObjectMeta(objectForm.type).colorClass"
                            :style="{
                                width: `${Math.min(220, objectForm.width * 0.7)}px`,
                                height: `${Math.min(100, Math.max(50, objectForm.height * 0.7))}px`,
                                ...(objectForm.color ? { backgroundColor: `${objectForm.color}25`, borderColor: objectForm.color } : {}),
                            }"
                        >
                            <div class="flex items-center justify-center gap-1.5 px-1 max-w-full">
                                <span
                                    class="flex size-5 shrink-0 items-center justify-center rounded text-white shadow-2xs text-xs font-bold"
                                    :style="{ backgroundColor: objectForm.color || getObjectMeta(objectForm.type).accentColor }"
                                >
                                    <component :is="getObjectMeta(objectForm.type).icon" class="size-3" />
                                </span>
                                <span class="truncate text-xs font-bold">{{ objectForm.name || getObjectMeta(objectForm.type).label }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <div>
                        <Button
                            v-if="editingObject"
                            type="button"
                            variant="ghost"
                            class="text-xs text-destructive hover:bg-destructive/10"
                            @click="removeObject(editingObject)"
                        >
                            <Trash2 class="size-3.5 mr-1" /> ลบอ็อบเจ็กต์นี้
                        </Button>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button type="button" variant="outline" @click="showObjectModal = false">ยกเลิก</Button>
                        <Button type="submit" variant="brand" :disabled="objectForm.processing">
                            {{ editingObject ? 'บันทึกการแก้ไข' : 'เพิ่มในผัง' }}
                        </Button>
                    </div>
                </div>
            </form>
        </Modal>

        <!-- ══ MODAL 3: เพิ่มโซนใหม่ ══ -->
        <Modal v-model:open="showZoneModal" title="เพิ่มโซนร้าน" description="เช่น ห้องแอร์, ระเบียงริมสวน, ชั้น 2">
            <form class="space-y-3" @submit.prevent="submitZone">
                <div class="space-y-1">
                    <Label for="zname">ชื่อโซน</Label>
                    <Input id="zname" v-model="zoneForm.name" required placeholder="เช่น ห้องแอร์, ระเบียง" />
                    <p v-if="zoneForm.errors.name" class="text-xs text-destructive">{{ zoneForm.errors.name }}</p>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="outline" @click="showZoneModal = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="zoneForm.processing">บันทึกโซน</Button>
                </div>
            </form>
        </Modal>
    </BackOfficeLayout>
</template>

<style scoped>
.floorplan-canvas {
    background-color: var(--card);
    background-image: radial-gradient(circle, currentColor 1px, transparent 1px);
    background-size: 20px 20px;
    color: color-mix(in srgb, var(--border) 60%, transparent);
}
</style>
