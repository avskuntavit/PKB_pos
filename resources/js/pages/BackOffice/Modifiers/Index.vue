<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import {
    AlertCircle,
    Boxes,
    Check,
    CheckCircle2,
    ChefHat,
    CircleDot,
    Eye,
    EyeOff,
    HelpCircle,
    Info,
    Layers,
    ListCheck,
    Pencil,
    Percent,
    Plus,
    RotateCcw,
    Scale,
    Search,
    ShoppingBag,
    SlidersHorizontal,
    Sparkles,
    Star,
    Tag,
    Trash2,
    TriangleAlert,
    Utensils,
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
import EmptyState from '@/components/ui/EmptyState.vue'
import { money, number } from '@/lib/format'

interface RecipeLine {
    stock_item_id: number | ''
    name?: string
    unit?: string
    qty: number
}

interface ModifierRow {
    id: number
    name: string
    price_delta: number
    portion_multiplier: number
    scales_with_portion: boolean
    is_default: boolean
    is_active: boolean
    marks_takeaway: boolean
    recipe: RecipeLine[]
}

interface ProductLink {
    product_id: number
    is_active: boolean
}

interface GroupRow {
    id: number
    name: string
    display_name: string | null
    shown_as: string
    min_select: number
    max_select: number
    is_required: boolean
    is_active: boolean
    sort_order: number
    links: ProductLink[]
    active_links_count: number
    modifiers: ModifierRow[]
}

const props = defineProps<{
    groups: GroupRow[]
    products: Array<{ id: number; name: string; category_name: string | null }>
    stockItems: Array<{ id: number; name: string; unit: string }>
}>()

/* ---------- 1. จัดการเซ็ตตัวเลือก (Group) ---------- */

const showGroup = ref(false)
const editingGroup = ref<GroupRow | null>(null)

const groupForm = useForm({
    name: '',
    display_name: '',
    min_select: 0,
    max_select: 1,
    is_required: false,
    is_active: true,
    sort_order: 0,
})

/** พรีเซ็ตรูปแบบการเลือกของลูกค้า (คุมโทน สบายตา) */
const GROUP_PRESETS = [
    {
        id: 'single_required',
        label: 'บังคับเลือก 1 อย่าง',
        desc: 'เช่น ระดับความหวาน, ขนาดจาน (ธรรมดา/พิเศษ), เส้นก๋วยเตี๋ยว',
        icon: CircleDot,
        min: 1,
        max: 1,
        req: true,
    },
    {
        id: 'single_optional',
        label: 'เลือกได้ 1 อย่าง (ไม่บังคับ)',
        desc: 'เช่น เครื่องดื่มแถมฟรี, ซอสจิ้มหลัก',
        icon: CircleDot,
        min: 0,
        max: 1,
        req: false,
    },
    {
        id: 'multi_optional',
        label: 'เลือกได้หลายอย่าง อิสระ',
        desc: 'เช่น ท็อปปิ้งเพิ่มเติม, เพิ่มไข่ดาว, เพิ่มชีส',
        icon: ListCheck,
        min: 0,
        max: 10,
        req: false,
    },
    {
        id: 'multi_required',
        label: 'บังคับเลือก 1 อย่างขึ้นไป',
        desc: 'เช่น เลือกผัก 1-3 ชนิด, เมนูชุดเลือกกับข้าว',
        icon: ListCheck,
        min: 1,
        max: 5,
        req: true,
    },
]

function applyGroupPreset(p: (typeof GROUP_PRESETS)[0]) {
    groupForm.min_select = p.min
    groupForm.max_select = p.max
    groupForm.is_required = p.req
}

function openGroupCreate() {
    editingGroup.value = null
    groupForm.reset()
    groupForm.clearErrors()
    showGroup.value = true
}

function openGroupEdit(g: GroupRow) {
    editingGroup.value = g
    groupForm.clearErrors()
    groupForm.defaults({
        name: g.name,
        display_name: g.display_name ?? '',
        min_select: g.min_select,
        max_select: g.max_select,
        is_required: g.is_required,
        is_active: g.is_active,
        sort_order: g.sort_order,
    })
    groupForm.reset()
    showGroup.value = true
}

function submitGroup() {
    const done = { preserveScroll: true, onSuccess: () => (showGroup.value = false) }

    if (editingGroup.value) {
        groupForm.put(`/backoffice/modifiers/groups/${editingGroup.value.id}`, done)
    } else {
        groupForm.post('/backoffice/modifiers/groups', done)
    }
}

function removeGroup(g: GroupRow) {
    if (!confirm(`ต้องการลบเซ็ต "${g.name}" ใช่หรือไม่?`)) return
    router.delete(`/backoffice/modifiers/groups/${g.id}`, { preserveScroll: true })
}

function toggleGroupActive(g: GroupRow) {
    router.put(
        `/backoffice/modifiers/groups/${g.id}`,
        {
            name: g.name,
            display_name: g.display_name,
            min_select: g.min_select,
            max_select: g.max_select,
            is_required: g.is_required,
            is_active: !g.is_active,
            sort_order: g.sort_order,
        },
        { preserveScroll: true },
    )
}

/* ---------- 2. ผูกกับเมนู (Products Link) ---------- */

const showProducts = ref(false)
const productForm = useForm<{ items: ProductLink[] }>({ items: [] })
const productSearch = ref('')
const selectedProductCategory = ref<string>('all')

const productCategories = computed(() => {
    const set = new Set<string>()
    props.products.forEach((p) => {
        if (p.category_name) set.add(p.category_name)
    })
    return Array.from(set)
})

const visibleProducts = computed(() => {
    let list = props.products
    if (selectedProductCategory.value !== 'all') {
        list = list.filter((p) => p.category_name === selectedProductCategory.value)
    }
    const term = productSearch.value.trim().toLowerCase()
    if (term) {
        list = list.filter((p) => p.name.toLowerCase().includes(term))
    }
    return list
})

function linkOf(productId: number): ProductLink | undefined {
    return productForm.items.find((l) => l.product_id === productId)
}

function toggleLink(productId: number) {
    const i = productForm.items.findIndex((l) => l.product_id === productId)
    if (i >= 0) {
        productForm.items.splice(i, 1)
    } else {
        productForm.items.push({ product_id: productId, is_active: true })
    }
}

function toggleLinkActive(productId: number) {
    const link = linkOf(productId)
    if (link) link.is_active = !link.is_active
}

function linkAllVisible() {
    visibleProducts.value.forEach((p) => {
        if (!linkOf(p.id)) {
            productForm.items.push({ product_id: p.id, is_active: true })
        }
    })
}

function unlinkAllVisible() {
    const ids = new Set(visibleProducts.value.map((p) => p.id))
    productForm.items = productForm.items.filter((l) => !ids.has(l.product_id))
}

const activeLinkCount = computed(() => productForm.items.filter((l) => l.is_active).length)

function openProducts(g: GroupRow) {
    editingGroup.value = g
    productForm.defaults({ items: g.links.map((l) => ({ ...l })) })
    productForm.reset()
    productForm.clearErrors()
    productSearch.value = ''
    selectedProductCategory.value = 'all'
    showProducts.value = true
}

function submitProducts() {
    if (!editingGroup.value) return
    productForm.put(`/backoffice/modifiers/groups/${editingGroup.value.id}/products`, {
        preserveScroll: true,
        onSuccess: () => (showProducts.value = false),
    })
}

/* ---------- 3. ตัวเลือกในเซ็ต (Modifier Items) ---------- */

const showModifier = ref(false)
const editingModifier = ref<ModifierRow | null>(null)
const targetGroup = ref<GroupRow | null>(null)

const modifierForm = useForm({
    name: '',
    price_delta: 0,
    portion_multiplier: 1,
    scales_with_portion: true,
    is_default: false,
    is_active: true,
    marks_takeaway: false,
})

const QUICK_PRICES = [0, 5, 10, 15, 20, 25, 30, 40, 50]
const QUICK_MULTIPLIERS = [
    { label: '1.0× ปกติ', val: 1, desc: 'สูตรขนาดมาตรฐาน' },
    { label: '1.5× พิเศษ', val: 1.5, desc: 'เพิ่มวัตถุดิบ 50%' },
    { label: '2.0× จัมโบ้', val: 2.0, desc: 'เพิ่มวัตถุดิบ 100%' },
    { label: '0.5× มินิ', val: 0.5, desc: 'ลดวัตถุดิบครึ่งหนึ่ง' },
]

function openModifierCreate(g: GroupRow) {
    targetGroup.value = g
    editingModifier.value = null
    modifierForm.reset()
    modifierForm.clearErrors()
    showModifier.value = true
}

function openModifierEdit(g: GroupRow, m: ModifierRow) {
    targetGroup.value = g
    editingModifier.value = m
    modifierForm.clearErrors()
    modifierForm.defaults({
        name: m.name,
        price_delta: m.price_delta,
        portion_multiplier: m.portion_multiplier,
        scales_with_portion: m.scales_with_portion,
        is_default: m.is_default,
        is_active: m.is_active,
        marks_takeaway: m.marks_takeaway,
    })
    modifierForm.reset()
    showModifier.value = true
}

const multiplierLocked = computed(() => (editingModifier.value?.recipe.length ?? 0) > 0)

function submitModifier() {
    const done = { preserveScroll: true, onSuccess: () => (showModifier.value = false) }

    if (editingModifier.value) {
        modifierForm.put(`/backoffice/modifiers/items/${editingModifier.value.id}`, done)
    } else if (targetGroup.value) {
        modifierForm.post(`/backoffice/modifiers/groups/${targetGroup.value.id}/items`, done)
    }
}

function removeModifier(m: ModifierRow) {
    if (!confirm(`ต้องการลบตัวเลือก "${m.name}" ใช่หรือไม่?`)) return
    router.delete(`/backoffice/modifiers/items/${m.id}`, { preserveScroll: true })
}

/* ---------- 4. วัตถุดิบของตัวเลือก (Recipe / Stock) ---------- */

const showRecipe = ref(false)
const recipeForm = useForm<{ items: RecipeLine[] }>({ items: [] })

function openRecipe(m: ModifierRow) {
    editingModifier.value = m
    recipeForm.defaults({ items: m.recipe.map((r) => ({ ...r })) })
    recipeForm.reset()
    recipeForm.clearErrors()
    showRecipe.value = true
}

function submitRecipe() {
    if (!editingModifier.value) return
    recipeForm.put(`/backoffice/modifiers/items/${editingModifier.value.id}/recipe`, {
        preserveScroll: true,
        onSuccess: () => (showRecipe.value = false),
    })
}

function stockEffect(m: ModifierRow): string {
    if (m.portion_multiplier !== 1) return `คูณสูตร ×${m.portion_multiplier}`
    if (!m.recipe.length) return 'ไม่กระทบสต๊อก'
    return m.recipe.map((r) => `${r.name} ${number(r.qty, 2)} ${r.unit}`).join(' · ')
}

function unitOf(id: number | ''): string {
    return props.stockItems.find((i) => i.id === id)?.unit ?? ''
}
</script>

<template>
    <Head title="เซ็ตตัวเลือก" />

    <BackOfficeLayout title="เซ็ตตัวเลือก">
        <div class="space-y-4">
            <!-- กล่องคำแนะนำการใช้งาน (Clean, Soothing Cards) -->
            <SectionCard title="ทำความเข้าใจเซ็ตตัวเลือก">
                <div class="grid gap-3 text-xs sm:grid-cols-3">
                    <div class="rounded-xl border bg-card p-3.5 shadow-xs space-y-1.5">
                        <div class="flex items-center gap-2 font-bold text-foreground">
                            <span class="flex size-6 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Tag class="size-3.5" />
                            </span>
                            <span>ชื่อภายใน vs หน้าบ้าน</span>
                        </div>
                        <p class="text-muted-foreground leading-relaxed">
                            ตั้งชื่อเซ็ตภายใน เช่น <b class="text-foreground">"ก๋วยเตี๋ยว - เพิ่มเติม"</b> เพื่อจำแนกในหลังบ้าน
                            แต่ลูกค้าหน้าจอจะเห็นเพียงคำว่า <b class="text-primary font-medium">"เพิ่มเติม"</b>
                        </p>
                    </div>

                    <div class="rounded-xl border bg-card p-3.5 shadow-xs space-y-1.5">
                        <div class="flex items-center gap-2 font-bold text-foreground">
                            <span class="flex size-6 items-center justify-center rounded-lg bg-[var(--status-warning)]/15 text-[var(--status-warning)]">
                                <SlidersHorizontal class="size-3.5" />
                            </span>
                            <span>ตัวคูณขนาดจาน (Scaling)</span>
                        </div>
                        <p class="text-muted-foreground leading-relaxed">
                            ตั้ง <b class="text-foreground">ธรรมดา ×1, พิเศษ ×1.5, จัมโบ้ ×2</b> ระบบจะนำตัวคูณนี้ไปคำนวณตัดสต๊อกตามสูตรของเมนูนั้นๆ โดยอัตโนมัติ
                        </p>
                    </div>

                    <div class="rounded-xl border bg-card p-3.5 shadow-xs space-y-1.5">
                        <div class="flex items-center gap-2 font-bold text-foreground">
                            <span class="flex size-6 items-center justify-center rounded-lg bg-[var(--status-critical)]/15 text-[var(--status-critical)]">
                                <TriangleAlert class="size-3.5" />
                            </span>
                            <span>การตัดสต๊อกแยกทางกัน</span>
                        </div>
                        <p class="text-muted-foreground leading-relaxed">
                            ตัวเลือกหนึ่งรายการเลือกได้ทางเดียว: <b class="text-foreground">ใช้ตัวคูณขนาด</b> หรือ <b class="text-foreground">ผูกวัตถุดิบเฉพาะ</b> เพื่อป้องกันการตัดสต๊อกซ้ำซ้อน
                        </p>
                    </div>
                </div>
            </SectionCard>

            <!-- รายการเซ็ตทั้งหมด -->
            <SectionCard title="เซ็ตตัวเลือกทั้งหมด" content-class="space-y-4">
                <template #actions>
                    <Button variant="brand" size="sm" class="shadow-xs" @click="openGroupCreate">
                        <Plus class="size-3.5 mr-1" />
                        สร้างเซ็ตตัวเลือก
                    </Button>
                </template>

                <EmptyState
                    v-if="!groups.length"
                    title="ยังไม่มีเซ็ตตัวเลือก"
                    description="สร้างเซ็ตเช่น 'ระดับความหวาน', 'ขนาดจาน', หรือ 'ท็อปปิ้งเพิ่มเติม' แล้วนำไปผูกกับเมนูที่ต้องการ"
                />

                <div
                    v-for="g in groups"
                    :key="g.id"
                    class="rounded-xl border bg-card shadow-xs transition-all overflow-hidden"
                    :class="!g.is_active && 'opacity-60 bg-muted/20'"
                >
                    <!-- แถบหัวเซ็ต (Calm, Unified Header) -->
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/30 px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary">
                                <Layers class="size-3.5" />
                            </div>
                            <span class="font-bold text-foreground text-sm" :class="!g.is_active && 'line-through'">
                                {{ g.name }}
                            </span>

                            <span
                                class="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                                title="ชื่อที่ลูกค้าเห็นบนหน้าจอ"
                            >
                                <Eye class="size-3 text-muted-foreground" />
                                {{ g.shown_as }}
                            </span>

                            <Badge :variant="g.is_required ? 'danger' : 'secondary'" class="text-xs">
                                {{ g.is_required ? 'บังคับเลือก' : 'เลือกหรือไม่ก็ได้' }}
                                · {{ g.max_select > 1 ? `เลือกได้ถึง ${g.max_select} อย่าง` : 'เลือกได้ 1 อย่าง' }}
                            </Badge>

                            <span class="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                <Utensils class="size-3" />
                                ผูก {{ g.active_links_count }} เมนู
                                <template v-if="g.links.length > g.active_links_count">
                                    (พักไว้ {{ g.links.length - g.active_links_count }})
                                </template>
                            </span>

                            <Badge v-if="!g.is_active" variant="warning" class="text-xs">
                                ปิดใช้งานทั้งเซ็ต
                            </Badge>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <label class="flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground pr-2 border-r">
                                <input
                                    type="checkbox"
                                    class="size-4 rounded border-input text-primary focus:ring-primary/20"
                                    :checked="g.is_active"
                                    :aria-label="`เปิดใช้เซ็ต ${g.name}`"
                                    @change="toggleGroupActive(g)"
                                />
                                เปิดใช้
                            </label>

                            <Button
                                variant="outline"
                                size="sm"
                                class="h-8 text-xs font-medium"
                                @click="openProducts(g)"
                            >
                                <Utensils class="size-3.5 mr-1" />
                                ผูกเมนู ({{ g.links.length }})
                            </Button>

                            <Button variant="ghost" size="icon" class="h-8 w-8" aria-label="แก้ไขเซ็ต" @click="openGroupEdit(g)">
                                <Pencil class="size-3.5" />
                            </Button>
                            <Button variant="ghost" size="icon" class="h-8 w-8 text-destructive hover:bg-destructive/10" aria-label="ลบเซ็ต" @click="removeGroup(g)">
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>
                    </div>

                    <!-- รายการตัวเลือกในเซ็ต -->
                    <div class="divide-y divide-border/60">
                        <div
                            v-for="m in g.modifiers"
                            :key="m.id"
                            class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 hover:bg-muted/20 transition-colors"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    v-if="m.is_default"
                                    class="flex size-4 items-center justify-center text-amber-500"
                                    title="เลือกไว้เป็นค่าเริ่มต้น"
                                >
                                    <Star class="size-3.5 fill-amber-400" />
                                </span>
                                <span class="text-sm font-medium text-foreground" :class="!m.is_active && 'text-muted-foreground line-through'">
                                    {{ m.name }}
                                </span>

                                <span
                                    class="tabular text-xs font-semibold px-2 py-0.5 rounded-md"
                                    :class="m.price_delta > 0 ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : (m.price_delta < 0 ? 'bg-destructive/10 text-destructive' : 'bg-muted text-muted-foreground')"
                                >
                                    {{ m.price_delta > 0 ? '+' : '' }}{{ m.price_delta !== 0 ? money(m.price_delta) : 'ฟรี' }}
                                </span>

                                <span
                                    class="rounded-md border px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        m.portion_multiplier !== 1
                                            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20'
                                            : m.recipe.length
                                              ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20'
                                              : 'bg-muted/60 text-muted-foreground border-border'
                                    "
                                >
                                    {{ stockEffect(m) }}
                                </span>

                                <span v-if="m.marks_takeaway" class="text-[11px] text-primary flex items-center gap-1 font-medium">
                                    <ShoppingBag class="size-3" />
                                    ห่อกลับบ้าน
                                </span>
                            </div>

                            <div class="flex items-center gap-1">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="h-7 text-xs font-medium"
                                    :class="m.recipe.length > 0 ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-700 dark:text-emerald-400' : ''"
                                    @click="openRecipe(m)"
                                >
                                    <Boxes class="size-3 mr-1" />
                                    วัตถุดิบ {{ m.recipe.length ? `(${m.recipe.length})` : '' }}
                                </Button>
                                <Button variant="ghost" size="icon" class="h-7 w-7" aria-label="แก้ไขตัวเลือก" @click="openModifierEdit(g, m)">
                                    <Pencil class="size-3" />
                                </Button>
                                <Button variant="ghost" size="icon" class="h-7 w-7 text-destructive hover:bg-destructive/10" aria-label="ลบตัวเลือก" @click="removeModifier(m)">
                                    <Trash2 class="size-3" />
                                </Button>
                            </div>
                        </div>

                        <div class="p-3 bg-muted/10">
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-8 text-xs font-medium"
                                @click="openModifierCreate(g)"
                            >
                                <Plus class="size-3.5 mr-1" />
                                เพิ่มตัวเลือกในเซ็ตนี้
                            </Button>
                        </div>
                    </div>
                </div>
            </SectionCard>
        </div>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- ══ MODAL 1: สร้าง / แก้ไขเซ็ตตัวเลือก ══ -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <Modal
            v-model:open="showGroup"
            :title="editingGroup ? `แก้ไขเซ็ต: ${editingGroup.name}` : 'สร้างเซ็ตตัวเลือกใหม่'"
            description="กำหนดชื่อและเงื่อนไขการเลือกของลูกค้า เช่น บังคับเลือก 1 อย่าง หรือเลือกได้อิสระ"
            class="max-w-xl"
        >
            <form class="space-y-4" @submit.prevent="submitGroup">
                <!-- พรีเซ็ตรูปแบบการเลือกสำเร็จรูป (Cohesive Cards) -->
                <div v-if="!editingGroup" class="space-y-1.5">
                    <Label class="text-xs text-muted-foreground font-semibold">เลือกรูปแบบที่ต้องการ:</Label>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-for="p in GROUP_PRESETS"
                            :key="p.id"
                            type="button"
                            class="flex flex-col text-left rounded-xl border p-2.5 transition-all cursor-pointer"
                            :class="[
                                groupForm.min_select === p.min && groupForm.max_select === p.max && groupForm.is_required === p.req
                                    ? 'border-primary bg-primary/8 ring-1 ring-primary text-foreground font-bold shadow-xs'
                                    : 'border-border bg-card hover:border-primary/40 hover:bg-accent/30 text-muted-foreground'
                            ]"
                            @click="applyGroupPreset(p)"
                        >
                            <div class="flex items-center gap-1.5 text-xs font-bold text-foreground">
                                <component :is="p.icon" class="size-3.5 text-primary" />
                                {{ p.label }}
                            </div>
                            <span class="text-[11px] text-muted-foreground mt-0.5 line-clamp-2 leading-tight">{{ p.desc }}</span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="g-name" class="font-semibold">ชื่อเซ็ต (สำหรับร้านค้า)</Label>
                        <Input id="g-name" v-model="groupForm.name" required placeholder="เช่น ก๋วยเตี๋ยว - ความหวาน" />
                        <p v-if="groupForm.errors.name" class="text-xs text-destructive">{{ groupForm.errors.name }}</p>
                    </div>

                    <div class="space-y-1">
                        <Label for="g-display" class="font-semibold">
                            ชื่อที่ลูกค้าเห็น
                            <span class="text-xs font-normal text-muted-foreground">(เว้นว่าง = ใช้ชื่อเซ็ต)</span>
                        </Label>
                        <Input id="g-display" v-model="groupForm.display_name" placeholder="เช่น ระดับความหวาน" />
                        <p v-if="groupForm.errors.display_name" class="text-xs text-destructive">
                            {{ groupForm.errors.display_name }}
                        </p>
                    </div>
                </div>

                <!-- กฎการเลือก Min / Max -->
                <div class="rounded-xl border bg-muted/20 p-3.5 space-y-3">
                    <div class="flex items-center justify-between">
                        <Label class="text-xs font-bold text-foreground flex items-center gap-1.5">
                            <Scale class="size-3.5 text-muted-foreground" />
                            เงื่อนไขจำนวนการเลือกของลูกค้า:
                        </Label>
                        <Badge variant="outline" class="text-xs font-mono bg-card">
                            {{ groupForm.is_required ? `บังคับเลือก ${groupForm.min_select}–${groupForm.max_select} รายการ` : `เลือกได้ 0–${groupForm.max_select} รายการ` }}
                        </Badge>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div class="space-y-1">
                            <Label for="g-min" class="text-[11px] text-muted-foreground font-medium">เลือกอย่างน้อย (Min)</Label>
                            <Input id="g-min" v-model.number="groupForm.min_select" type="number" min="0" max="20" class="tabular font-semibold" />
                            <p v-if="groupForm.errors.min_select" class="text-xs text-destructive">
                                {{ groupForm.errors.min_select }}
                            </p>
                        </div>

                        <div class="space-y-1">
                            <Label for="g-max" class="text-[11px] text-muted-foreground font-medium">เลือกได้มากสุด (Max)</Label>
                            <Input id="g-max" v-model.number="groupForm.max_select" type="number" min="1" max="20" class="tabular font-semibold" />
                            <p v-if="groupForm.errors.max_select" class="text-xs text-destructive">
                                {{ groupForm.errors.max_select }}
                            </p>
                        </div>

                        <div class="space-y-1">
                            <Label for="g-sort" class="text-[11px] text-muted-foreground font-medium">ลำดับแสดงผล</Label>
                            <Input id="g-sort" v-model.number="groupForm.sort_order" type="number" min="0" class="tabular font-semibold" />
                        </div>
                    </div>
                </div>

                <!-- การตั้งค่าตัวเลือก -->
                <div class="space-y-2">
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 hover:bg-accent/40 transition-colors">
                        <input v-model="groupForm.is_required" type="checkbox" class="mt-0.5 size-4 rounded border-input text-primary focus:ring-primary/20" />
                        <div>
                            <span class="text-xs font-bold text-foreground block">บังคับให้ลูกค้าต้องเลือก (Required)</span>
                            <span class="text-[11px] text-muted-foreground">ลูกค้าจะไม่สามารถเพิ่มเมนูลงตะกร้าได้จนกว่าจะเลือกตัวเลือกในเซ็ตนี้</span>
                        </div>
                    </label>

                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 hover:bg-accent/40 transition-colors">
                        <input v-model="groupForm.is_active" type="checkbox" class="mt-0.5 size-4 rounded border-input text-primary focus:ring-primary/20" />
                        <div>
                            <span class="text-xs font-bold text-foreground block">เปิดใช้งานเซ็ตนี้ (Active)</span>
                            <span class="text-[11px] text-muted-foreground">หากปิด เซ็ตนี้จะถูกซ่อนออกจากทุกเมนูที่ผูกไว้พร้อมกันทันที</span>
                        </div>
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t">
                    <Button type="button" variant="outline" @click="showGroup = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="groupForm.processing">
                        {{ editingGroup ? 'บันทึกการแก้ไข' : 'สร้างเซ็ต' }}
                    </Button>
                </div>
            </form>
        </Modal>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- ══ MODAL 2: เพิ่ม / แก้ไขตัวเลือกในเซ็ต ══ -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <Modal
            v-model:open="showModifier"
            :title="editingModifier ? `แก้ไขตัวเลือก: ${editingModifier.name}` : `เพิ่มตัวเลือกในเซ็ต ${targetGroup?.name ?? ''}`"
            description="กำหนดชื่อ ราคาที่เพิ่ม/ลด และตัวคูณขนาดจานของตัวเลือกนี้"
            class="max-w-xl"
        >
            <form class="space-y-4" @submit.prevent="submitModifier">
                <div class="space-y-1">
                    <Label for="m-name" class="font-semibold">ชื่อตัวเลือก</Label>
                    <Input id="m-name" v-model="modifierForm.name" required placeholder="เช่น เพิ่มหมูสับ, พิเศษ, หวาน 50%" />
                    <p v-if="modifierForm.errors.name" class="text-xs text-destructive">{{ modifierForm.errors.name }}</p>
                </div>

                <!-- ราคาเพิ่ม / ลด -->
                <div class="space-y-2 rounded-xl border bg-muted/20 p-3.5">
                    <div class="flex items-center justify-between">
                        <Label class="text-xs font-bold text-foreground flex items-center gap-1.5">
                            <Tag class="size-3.5 text-muted-foreground" />
                            ราคาที่บวกหรือลด (บาท):
                        </Label>
                        <span
                            class="text-xs font-bold tabular px-2.5 py-0.5 rounded-md"
                            :class="modifierForm.price_delta > 0 ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : (modifierForm.price_delta < 0 ? 'bg-destructive/10 text-destructive' : 'bg-muted text-muted-foreground')"
                        >
                            {{ modifierForm.price_delta > 0 ? '+' : '' }}{{ money(modifierForm.price_delta) }} บาท
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <Input
                            id="m-price"
                            v-model.number="modifierForm.price_delta"
                            type="number"
                            step="0.5"
                            class="tabular font-bold"
                            placeholder="0.00"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-9 px-3.5 text-xs font-medium shrink-0"
                            :class="modifierForm.price_delta === 0 ? 'border-primary bg-primary/10 text-primary font-bold' : ''"
                            @click="modifierForm.price_delta = 0"
                        >
                            ฟรี (0฿)
                        </Button>
                    </div>

                    <!-- ปุ่มลัดเลือกราคาบวก -->
                    <div class="flex flex-wrap items-center gap-1.5 pt-1">
                        <span class="text-[11px] text-muted-foreground mr-1">ปุ่มลัด:</span>
                        <button
                            v-for="p in QUICK_PRICES"
                            :key="p"
                            type="button"
                            class="rounded-lg border bg-card px-2.5 py-1 text-xs font-medium transition-all hover:border-primary/50"
                            :class="modifierForm.price_delta === p ? 'border-primary bg-primary text-primary-foreground font-bold shadow-xs' : 'border-border text-foreground'"
                            @click="modifierForm.price_delta = p"
                        >
                            {{ p === 0 ? 'ฟรี' : `+${p}฿` }}
                        </button>
                    </div>
                </div>

                <!-- ตัวคูณขนาดจาน (Portion Multiplier) -->
                <div class="space-y-2 rounded-xl border bg-muted/20 p-3.5">
                    <div class="flex items-center justify-between">
                        <Label for="m-mult" class="text-xs font-bold text-foreground flex items-center gap-1.5">
                            <SlidersHorizontal class="size-3.5 text-muted-foreground" />
                            ตัวคูณขนาดจาน (Portion Multiplier):
                        </Label>
                        <Badge variant="outline" class="text-xs font-mono font-bold bg-card">
                            ×{{ modifierForm.portion_multiplier }}
                        </Badge>
                    </div>

                    <Input
                        id="m-mult"
                        v-model.number="modifierForm.portion_multiplier"
                        type="number"
                        step="0.1"
                        min="0.1"
                        max="20"
                        class="tabular font-bold"
                        :disabled="multiplierLocked"
                    />

                    <!-- ปุ่มลัดตัวคูณ -->
                    <div v-if="!multiplierLocked" class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1">
                        <button
                            v-for="qm in QUICK_MULTIPLIERS"
                            :key="qm.val"
                            type="button"
                            class="rounded-xl border bg-card p-2 text-left text-xs transition-all cursor-pointer"
                            :class="modifierForm.portion_multiplier === qm.val ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary font-bold' : 'border-border hover:border-primary/40 text-foreground'"
                            @click="modifierForm.portion_multiplier = qm.val"
                        >
                            <span class="block font-bold">{{ qm.label }}</span>
                            <span class="text-[10px] text-muted-foreground">{{ qm.desc }}</span>
                        </button>
                    </div>

                    <p v-if="multiplierLocked" class="flex items-center gap-2 text-xs text-amber-600 dark:text-amber-400 font-medium bg-amber-500/10 p-2.5 rounded-xl border border-amber-500/20">
                        <TriangleAlert class="size-4 shrink-0 text-amber-600" />
                        ตัวเลือกนี้ผูกวัตถุดิบเฉพาะไว้แล้ว จึงไม่สามารถตั้งตัวคูณขนาดได้ (ป้องกันการตัดสต๊อกซ้ำซ้อน)
                    </p>
                    <p v-else class="text-[11px] text-muted-foreground">
                        ค่าปกติคือ 1.0 (ธรรมดา) — หากเลือกเป็น 1.5 หรือ 2.0 ระบบจะคูณสูตรวัตถุดิบของเมนูอาหารนั้นทั้งจาน
                    </p>
                </div>

                <!-- การตั้งค่าเพิ่มเติม -->
                <div class="space-y-2">
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 hover:bg-accent/40 transition-colors">
                        <input v-model="modifierForm.is_default" type="checkbox" class="mt-0.5 size-4 rounded border-input text-primary focus:ring-primary/20" />
                        <div>
                            <span class="text-xs font-bold text-foreground block flex items-center gap-1.5">
                                <Star class="size-3.5 text-amber-500 fill-amber-400" />
                                เลือกเป็นค่าเริ่มต้น (Default Selection)
                            </span>
                            <span class="text-[11px] text-muted-foreground">ตัวเลือกนี้จะถูกเลือกไว้ให้อัตโนมัติเมื่อเปิดหน้าต่างสั่งอาหาร</span>
                        </div>
                    </label>

                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 hover:bg-accent/40 transition-colors">
                        <input v-model="modifierForm.marks_takeaway" type="checkbox" class="mt-0.5 size-4 rounded border-input text-primary focus:ring-primary/20" />
                        <div>
                            <span class="text-xs font-bold text-foreground block flex items-center gap-1.5">
                                <ShoppingBag class="size-3.5 text-primary" />
                                ตัวเลือกนี้หมายถึง "ห่อกลับบ้าน" (Takeaway)
                            </span>
                            <span class="text-[11px] text-muted-foreground">บิลที่มีจานที่เลือกตัวเลือกนี้ จะถูกจัดประเภทเป็นออเดอร์กลับบ้านในรายงาน</span>
                        </div>
                    </label>

                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 hover:bg-accent/40 transition-colors">
                        <input v-model="modifierForm.scales_with_portion" type="checkbox" class="mt-0.5 size-4 rounded border-input text-primary focus:ring-primary/20" />
                        <div>
                            <span class="text-xs font-bold text-foreground block flex items-center gap-1.5">
                                <Scale class="size-3.5 text-muted-foreground" />
                                วัตถุดิบที่เพิ่มให้โตตามขนาดจานด้วย
                            </span>
                            <span class="text-[11px] text-muted-foreground">หากลูกค้าสั่งจานพิเศษหรือจัมโบ้ วัตถุดิบของตัวเลือกนี้จะถูกคูณตามขนาดจานด้วย</span>
                        </div>
                    </label>

                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 hover:bg-accent/40 transition-colors">
                        <input v-model="modifierForm.is_active" type="checkbox" class="mt-0.5 size-4 rounded border-input text-primary focus:ring-primary/20" />
                        <div>
                            <span class="text-xs font-bold text-foreground block">เปิดใช้งานตัวเลือกนี้ (Active)</span>
                        </div>
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t">
                    <Button type="button" variant="outline" @click="showModifier = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="modifierForm.processing">
                        {{ editingModifier ? 'บันทึกการแก้ไข' : 'เพิ่มตัวเลือก' }}
                    </Button>
                </div>
            </form>
        </Modal>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- ══ MODAL 3: วัตถุดิบของตัวเลือก (Stock & Recipe) ══ -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <Modal
            v-model:open="showRecipe"
            :title="`วัตถุดิบของตัวเลือก: ${editingModifier?.name ?? ''}`"
            description="ระบุวัตถุดิบที่จะถูกตัดสต๊อกเพิ่มเมื่อลูกค้าเลือกรายการนี้ (เช่น เพิ่มหมูสับ 50 กรัม)"
            class="max-w-2xl"
        >
            <form class="space-y-4" @submit.prevent="submitRecipe">
                <div
                    v-if="editingModifier && editingModifier.portion_multiplier !== 1"
                    class="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3.5 text-xs text-amber-800 dark:text-amber-200"
                >
                    <div class="flex size-5 items-center justify-center rounded-md bg-amber-500/20 text-amber-700 dark:text-amber-300 shrink-0 mt-0.5">
                        <TriangleAlert class="size-3.5" />
                    </div>
                    <div>
                        <span class="font-bold text-sm block">ตัวเลือกนี้ตั้งตัวคูณขนาดจานไว้ที่ ×{{ editingModifier.portion_multiplier }}</span>
                        <span class="text-muted-foreground mt-0.5 block leading-relaxed">ระบบจะคูณสูตรของเมนูนั้นโดยอัตโนมัติ ไม่จำเป็นต้องใส่วัตถุดิบเพิ่มที่นี่</span>
                    </div>
                </div>

                <div
                    v-if="!recipeForm.items.length"
                    class="flex flex-col items-center justify-center rounded-xl border border-dashed py-8 text-center"
                >
                    <div class="flex size-10 items-center justify-center rounded-xl bg-muted text-muted-foreground mb-2">
                        <Boxes class="size-5" />
                    </div>
                    <p class="text-sm font-bold text-foreground">ยังไม่มีการผูกวัตถุดิบ</p>
                    <p class="text-xs text-muted-foreground mt-1 max-w-xs">
                        เมื่อลูกค้าเลือกตัวเลือกนี้ ระบบจะไม่ตัดสต๊อกวัตถุดิบเพิ่มเติม
                    </p>
                </div>

                <!-- รายการวัตถุดิบ -->
                <div v-else class="space-y-2 max-h-80 overflow-y-auto pr-1">
                    <div
                        v-for="(line, i) in recipeForm.items"
                        :key="i"
                        class="flex items-center gap-2.5 rounded-xl border bg-card p-2.5 shadow-xs transition-all"
                    >
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-bold text-muted-foreground">
                            {{ i + 1 }}
                        </span>

                        <div class="flex-1 min-w-0">
                            <Select v-model="line.stock_item_id" required>
                                <option value="">เลือกวัตถุดิบ</option>
                                <option v-for="s in stockItems" :key="s.id" :value="s.id">
                                    {{ s.name }} ({{ s.unit }})
                                </option>
                            </Select>
                        </div>

                        <div class="w-36 flex items-center gap-1.5 shrink-0">
                            <Input
                                v-model.number="line.qty"
                                type="number"
                                step="0.01"
                                required
                                class="tabular font-bold text-right"
                                placeholder="ปริมาณ"
                            />
                            <Badge variant="outline" class="text-xs shrink-0 bg-muted/40 min-w-10 text-center justify-center">
                                {{ unitOf(line.stock_item_id) || 'หน่วย' }}
                            </Badge>
                        </div>

                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 text-destructive hover:bg-destructive/10 shrink-0"
                            aria-label="ลบวัตถุดิบ"
                            @click="recipeForm.items.splice(i, 1)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-1">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="font-medium"
                        :disabled="editingModifier?.portion_multiplier !== 1"
                        @click="recipeForm.items.push({ stock_item_id: '', qty: 1 })"
                    >
                        <Plus class="size-3.5 mr-1" />
                        เพิ่มวัตถุดิบ
                    </Button>

                    <span class="text-xs text-muted-foreground">
                        ผูกแล้ว {{ recipeForm.items.length }} รายการ
                    </span>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t">
                    <Button type="button" variant="outline" @click="showRecipe = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="recipeForm.processing">บันทึกวัตถุดิบ</Button>
                </div>
            </form>
        </Modal>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- ══ MODAL 4: ผูกเซ็ตกับเมนูอาหาร (Link Products) ══ -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <Modal
            v-model:open="showProducts"
            :title="`ผูกเซ็ต: ${editingGroup?.name ?? ''}`"
            description="เลือกเมนูที่ต้องการให้มีเซ็ตตัวเลือกนี้ปรากฏในหน้าต่างสั่งอาหาร"
            class="max-w-2xl"
        >
            <form class="space-y-4" @submit.prevent="submitProducts">
                <!-- ค้นหาและตัวกรองหมวดหมู่ -->
                <div class="space-y-2.5">
                    <div class="relative">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                        <Input
                            v-model="productSearch"
                            placeholder="ค้นหาชื่อเมนู..."
                            class="pl-9 pr-8"
                        />
                        <button
                            v-if="productSearch"
                            type="button"
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                            @click="productSearch = ''"
                        >
                            <X class="size-4" />
                        </button>
                    </div>

                    <!-- แท็บหมวดหมู่ -->
                    <div class="flex flex-wrap items-center gap-1.5 overflow-x-auto pb-1">
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1 text-xs font-semibold transition-all"
                            :class="selectedProductCategory === 'all' ? 'border-primary bg-primary text-primary-foreground shadow-xs' : 'border-border bg-card text-muted-foreground hover:text-foreground hover:border-primary/40'"
                            @click="selectedProductCategory = 'all'"
                        >
                            ทั้งหมด ({{ products.length }})
                        </button>
                        <button
                            v-for="cat in productCategories"
                            :key="cat"
                            type="button"
                            class="rounded-lg border px-3 py-1 text-xs font-semibold transition-all"
                            :class="selectedProductCategory === cat ? 'border-primary bg-primary text-primary-foreground shadow-xs' : 'border-border bg-card text-muted-foreground hover:text-foreground hover:border-primary/40'"
                            @click="selectedProductCategory = cat"
                        >
                            {{ cat }} ({{ products.filter(p => p.category_name === cat).length }})
                        </button>
                    </div>
                </div>

                <!-- แถบปุ่มลัดเลือกด่วน -->
                <div class="flex items-center justify-between text-xs border-b pb-2">
                    <div class="flex items-center gap-2">
                        <Button type="button" variant="outline" size="sm" class="h-7 text-xs" @click="linkAllVisible">
                            <Check class="size-3 mr-1 text-primary" />
                            ผูกทั้งหมดในรายการนี้
                        </Button>
                        <Button type="button" variant="outline" size="sm" class="h-7 text-xs text-destructive hover:bg-destructive/10" @click="unlinkAllVisible">
                            <X class="size-3 mr-1" />
                            ถอดทั้งหมดในรายการนี้
                        </Button>
                    </div>
                    <span class="text-muted-foreground font-medium">
                        พบ {{ visibleProducts.length }} เมนู
                    </span>
                </div>

                <!-- รายการเมนูสำหรับผูก -->
                <div class="max-h-72 space-y-1.5 overflow-y-auto pr-1">
                    <div
                        v-for="p in visibleProducts"
                        :key="p.id"
                        class="flex items-center justify-between gap-3 rounded-xl border p-2.5 transition-all"
                        :class="linkOf(p.id) ? 'border-primary/40 bg-primary/5 shadow-xs' : 'border-border bg-card hover:border-primary/30'"
                    >
                        <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5 select-none">
                            <input
                                type="checkbox"
                                class="size-4 shrink-0 rounded border-input text-primary focus:ring-primary/20"
                                :checked="!!linkOf(p.id)"
                                @change="toggleLink(p.id)"
                            />
                            <div class="min-w-0 flex-1">
                                <span class="font-bold text-xs text-foreground block truncate">{{ p.name }}</span>
                                <span class="text-[10px] text-muted-foreground">{{ p.category_name ?? 'ไม่มีหมวดหมู่' }}</span>
                            </div>
                        </label>

                        <!-- เปิด/พักการแสดงผล -->
                        <div class="flex items-center gap-2 shrink-0">
                            <label
                                class="flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs transition-all select-none font-semibold"
                                :class="linkOf(p.id) ? (linkOf(p.id)?.is_active ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300') : 'opacity-30 pointer-events-none'"
                            >
                                <input
                                    type="checkbox"
                                    class="size-3.5 rounded border-input text-primary"
                                    :checked="linkOf(p.id)?.is_active ?? false"
                                    :disabled="!linkOf(p.id)"
                                    :aria-label="`แสดงเซ็ตนี้บนเมนู ${p.name}`"
                                    @change="toggleLinkActive(p.id)"
                                />
                                {{ linkOf(p.id)?.is_active ? 'แสดง' : 'พักไว้' }}
                            </label>
                        </div>
                    </div>

                    <p v-if="!visibleProducts.length" class="py-8 text-center text-sm text-muted-foreground">
                        ไม่พบเมนูที่ตรงกับคำค้นหา
                    </p>
                </div>

                <!-- สรุปผล -->
                <div class="flex items-center justify-between rounded-xl bg-muted/40 border border-border px-3.5 py-2.5 text-xs">
                    <span class="text-muted-foreground font-medium">
                        ผูกไว้ทั้งหมด <b class="text-foreground font-bold">{{ productForm.items.length }}</b> เมนู · แสดงจริง <b class="text-emerald-600 dark:text-emerald-400 font-bold">{{ activeLinkCount }}</b> เมนู
                    </span>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t">
                    <Button type="button" variant="outline" @click="showProducts = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="productForm.processing">บันทึกการผูกเมนู</Button>
                </div>
            </form>
        </Modal>
    </BackOfficeLayout>
</template>
