<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { ArrowDown, ArrowUp, Eye, Layers, Pencil, Plus, Save, Sparkles, Star, Store, Tags, Trash2 } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Pagination from '@/components/ui/Pagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import ImageField from '@/components/backoffice/ImageField.vue'
import ProductPreview from '@/components/backoffice/ProductPreview.vue'
import { money } from '@/lib/format'
import type { Category, DietTag, Paginated } from '@/types'

/** ป้ายข้อมูลอาหารจัดเป็นกลุ่มตามที่ลูกค้าอ่าน — มาจาก App\Enums\DietTag::grouped() */
interface DietTagGroup {
    /** ชนิดเดียวกับที่หน้าลูกค้าใช้ ไม่ใช่ string เปล่า ๆ — จะได้พังตั้งแต่ตอน build ถ้าเพิ่มกลุ่มใหม่แล้วลืมแก้ที่นี่ */
    kind: DietTag['kind']
    label: string
    hint: string
    tags: Array<{ value: string; label: string }>
}

/** เซ็ตตัวเลือกของสาขา — ใช้เลือกผูกจากฝั่งเมนู */
interface GroupOption {
    id: number
    name: string
    /** ชื่อที่ลูกค้าเห็นตอนสั่ง */
    shown_as: string
    is_active: boolean
}

type Row = Record<string, any>

/** หนึ่งช่องในตะแกรงโปรโมท */
interface PromoItem {
    product_id: number
    promo_label: string
}

const props = defineProps<{
    products: Paginated<Row>
    categories: Category[]
    modifierGroups: GroupOption[]
    printGroups: Array<{ value: number; label: string }>
    dietTags: DietTagGroup[]
    imageSpec: {
        label: string
        width: number
        height: number
        ratio: string
        max_mb: number
        formats: string
        hint: string
    }
    promo: { title: string; featured_id: number | null; items: PromoItem[]; max_slots: number }
    allProducts: Array<{ id: number; name: string; image_path: string | null }>
    filters: { search?: string; category_id?: number }
    /** เจ้าของเท่านั้นที่แก้เมนูกลางได้ ผู้จัดการปรับได้แค่ค่าของสาขาตัวเอง */
    canEditCentral: boolean
    branchName: string
}>()

/* ---------- เมนูกลาง vs ค่าเฉพาะสาขา ---------- */

/** เมนูกลางใช้ร่วมกันทุกสาขา — แก้ที่นี่กระทบสาขาอื่นด้วย */
const isCentral = (p: Row) => p.branch_id === null

/** ค่าที่สาขานี้ตั้งทับไว้ — ไม่มีแถว = ใช้ค่ากลางทั้งหมด */
const overrideOf = (p: Row) => p.override ?? null

/** ราคาที่สาขานี้ขายจริง — ?? ไม่ใช่ || เพราะราคา 0 คือแจกฟรี ไม่ใช่ไม่ได้ตั้ง */
const priceHere = (p: Row) => Number(overrideOf(p)?.price ?? p.price)

const hasOverride = (p: Row) => {
    const o = overrideOf(p)

    return !!o && (o.price !== null || o.is_active !== null || o.sort_order !== null || o.print_group !== null)
}

/** แก้ฟอร์มหลักได้ไหม — เมนูเฉพาะสาขาแก้ได้เสมอ เมนูกลางต้องเป็นเจ้าของ */
const canEdit = (p: Row) => !isCentral(p) || props.canEditCentral

const search = ref(props.filters.search ?? '')
const categoryId = ref(props.filters.category_id ?? '')

/** ช่องเลือกหมวดให้เห็นเฉพาะหมวดที่เปิดอยู่ — หมวดที่ปิดไว้ไม่ควรถูกเลือกใหม่ */
const activeCategories = computed(() => props.categories.filter((c) => c.is_active !== false))

function reload() {
    router.get(
        '/backoffice/products',
        { search: search.value, category_id: categoryId.value },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

/* ---------- เพิ่ม / แก้ไขสินค้า ---------- */

const showForm = ref(false)
const editing = ref<Row | null>(null)
const confirmingDelete = ref(false)

/*
| ค่าของ "ฟอร์มเมนูเปล่า" — เป็นฟังก์ชัน ไม่ใช่ค่าคงที่ก้อนเดียว ด้วยเหตุผลสองข้อ
|
| 1) useForm จำค่าตั้งต้นไว้ชุดหนึ่ง แล้ว form.reset() คืนค่าจากชุดนั้น
|    openEdit() เรียก form.defaults() ทับด้วยข้อมูลเมนูที่กำลังแก้ ค่าตั้งต้นจึงเปลี่ยนไปแล้ว
|    ถ้า openCreate() เรียกแต่ reset() เฉย ๆ จะได้ข้อมูล "เมนูที่แก้ล่าสุด" กลับมาแทนฟอร์มเปล่า
|    (อาการที่เจอ: แก้เมนูหนึ่ง ปิดหน้าต่าง แล้วกดเพิ่มเมนู จะเจอข้อมูลเมนูเดิมค้างอยู่
|    แล้วกดบันทึกทีเดียวได้เมนูซ้ำโดยไม่ตั้งใจ)
|
| 2) diet_tags เป็นอาร์เรย์ ถ้าคืนก้อนเดิมทุกครั้ง การติ๊กป้ายในฟอร์ม
|    จะไปแก้ค่าตั้งต้นที่ทุกครั้งใช้ร่วมกันด้วย
*/
function emptyForm() {
    return {
        name: '',
        category_id: '' as number | string,
        sku: '',
        barcode: '',
        price: 0 as number | string,
        cost: 0 as number | string,
        staff_price: '' as number | string,
        unit: '',
        description: '',
        sort_order: 0 as number | string,
        print_group: 1 as number | string,
        is_active: true,
        is_alcohol: false,
        track_stock: false,
        is_open_price: false,
        // ค่าของ App\Enums\DietTag เช่น ['spicy', 'contains_nut']
        diet_tags: [] as string[],
        image: null as File | null,
        remove_image: false,
    }
}

/** ค่าของเมนูที่กำลังแก้ ในรูปแบบเดียวกับฟอร์มเปล่า */
function editForm(p: Row) {
    return {
        ...emptyForm(),
        name: p.name ?? '',
        category_id: p.category_id ?? '',
        sku: p.sku ?? '',
        barcode: p.barcode ?? '',
        price: p.price ?? 0,
        cost: p.cost ?? 0,
        staff_price: p.staff_price ?? '',
        unit: p.unit ?? '',
        description: p.description ?? '',
        sort_order: p.sort_order ?? 0,
        print_group: p.print_group ?? 1,
        is_active: Boolean(p.is_active),
        is_alcohol: Boolean(p.is_alcohol),
        track_stock: Boolean(p.track_stock),
        is_open_price: Boolean(p.is_open_price),
        // สำเนาอาร์เรย์เสมอ ไม่งั้นการติ๊กในฟอร์มจะไปแก้แถวในตารางที่อยู่ข้างหลังด้วย
        diet_tags: Array.isArray(p.diet_tags) ? [...p.diet_tags] : [],
    }
}

const form = useForm(emptyForm())

/*
| สีของป้ายแยกตามกลุ่ม ไม่ใช่แยกตามป้าย — ชุดเดียวกับ DietBadges.vue ที่ลูกค้าเห็น
| ตั้งใจให้ตรงกัน คนตั้งค่าจะได้รู้ตั้งแต่ตอนติ๊กว่าอันไหนจะไปขึ้นเป็นคำเตือนสีส้ม
*/
const DIET_KIND_CLASS: Record<string, string> = {
    heat: 'bg-[var(--status-critical)]/12 text-[var(--status-critical)] border-[var(--status-critical)]/30',
    diet: 'bg-[var(--status-good)]/12 text-[var(--status-good)] border-[var(--status-good)]/30',
    allergen: 'bg-[var(--status-warning)]/15 text-[var(--status-warning)] border-[var(--status-warning)]/30',
}

function toggleDietTag(value: string) {
    const i = form.diet_tags.indexOf(value)

    if (i >= 0) {
        form.diet_tags.splice(i, 1)
    } else {
        form.diet_tags.push(value)
    }
}

/** ป้ายที่ติ๊กอยู่ตอนนี้ ในรูปแบบเดียวกับที่หน้าลูกค้าได้รับ — ส่งให้แผ่นตัวอย่าง */
const selectedDietTags = computed<DietTag[]>(() =>
    props.dietTags.flatMap((group) =>
        group.tags
            .filter((t) => form.diet_tags.includes(t.value))
            .map((t) => ({ value: t.value, label: t.label, kind: group.kind })),
    ),
)

/** รูปที่โชว์ในแผ่นตัวอย่าง — ไฟล์ที่เพิ่งเลือก > รูปเดิมของเมนู > ไม่มี */
const localPreview = ref<string | null>(null)

const previewSrc = computed(() => {
    if (localPreview.value) return localPreview.value
    if (form.remove_image) return null
    return (editing.value?.image_path as string | null) ?? null
})

function clearPreview() {
    if (localPreview.value) {
        URL.revokeObjectURL(localPreview.value)
        localPreview.value = null
    }
}

// ImageField ดูแลตัวเลือกไฟล์เอง ตรงนี้สร้าง object URL อีกชุดให้แผ่นตัวอย่าง
watch(
    () => form.image,
    (f) => {
        clearPreview()
        if (f) localPreview.value = URL.createObjectURL(f)
    },
)

onBeforeUnmount(clearPreview)

/**
 * เปิดฟอร์มด้วยค่าชุดหนึ่ง — ต้อง defaults() ก่อน reset() เสมอ
 *
 * ── พฤติกรรมจริงของ useForm (ตรวจจากซอร์ส @inertiajs/vue3) ──────────────
 *   defaults(obj)  ทำ Object.assign({}, cloneDeep(defaults), obj) = **ผสม** ไม่ใช่แทนที่
 *   reset()        เอา cloneDeep(defaults) มาทับทั้งฟอร์ม
 *
 * สองข้อนี้รวมกันแปลว่า ค่าตั้งต้นที่ openEdit() ใส่ไว้จะค้างอยู่ตลอด
 * จนกว่าจะมีใครส่งคีย์นั้นมาทับ — เพราะงั้น emptyForm() ต้องคืน **ทุกคีย์** เสมอ
 * วันไหนมีคนเติมช่องใหม่ในฟอร์มแล้วลืมใส่ใน emptyForm() ข้อมูลเมนูเก่าจะโผล่ทันที
 *
 * บรรทัด diet_tags ข้างล่างเป็นกันเหนียว — reset() ก๊อปลึกให้อยู่แล้ว
 * แต่ค่าตั้งต้นเก็บ "อาร์เรย์ก้อนที่เราส่งเข้าไป" ไว้ตรง ๆ ไม่ได้ก๊อป
 */
function fillForm(values: ReturnType<typeof emptyForm>) {
    clearPreview()
    form.clearErrors()
    form.defaults(values)
    form.reset()
    form.diet_tags = [...values.diet_tags]
    confirmingDelete.value = false
}

function openCreate() {
    editing.value = null
    fillForm(emptyForm())
    showForm.value = true
}

function openEdit(p: Row) {
    editing.value = p
    fillForm(editForm(p))
    showForm.value = true
}

function closeForm() {
    showForm.value = false

    // ล้างกลับเป็นฟอร์มเปล่า ไม่ใช่ reset() เฉย ๆ ซึ่งจะคืนค่าของเมนูที่เพิ่งแก้กลับมาค้างไว้
    fillForm(emptyForm())
}

function submit() {
    const target = editing.value

    // ไฟล์แนบต้องส่งเป็น multipart ซึ่งใช้ PUT ตรง ๆ ไม่ได้
    // จึงยิง POST แล้วแปะ _method=put ให้ Laravel อ่านเป็น update
    form.transform((data) => (target ? { ...data, _method: 'put' } : data))

    form.post(target ? `/backoffice/products/${target.id}` : '/backoffice/products', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => closeForm(),
    })
}

function destroy() {
    if (!editing.value) return

    router.delete(`/backoffice/products/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => closeForm(),
    })
}

/* ---------- ราคาเฉพาะสาขา ---------- */

const showBranchPrice = ref(false)
const branchTarget = ref<Row | null>(null)

/**
 * ช่องว่าง = ใช้ค่ากลาง ไม่ใช่ศูนย์
 *
 * เก็บเป็นสตริงเพราะต้องแยก '' (ไม่ทับ) ออกจาก '0' (แจกฟรี) ให้ได้
 * ถ้าใช้ number ทั้งสองอย่างจะกลายเป็น 0 เหมือนกันแล้วแยกไม่ออก
 */
const branchForm = useForm({
    price: '' as string,
    is_active: '' as string,
    sort_order: '' as string,
    print_group: '' as string,
})

function openBranchPrice(p: Row) {
    branchTarget.value = p
    branchForm.clearErrors()

    const o = overrideOf(p)

    branchForm.defaults({
        price: o?.price ?? '',
        is_active: o?.is_active === null || o?.is_active === undefined ? '' : String(o.is_active ? 1 : 0),
        sort_order: o?.sort_order ?? '',
        print_group: o?.print_group ?? '',
    })
    branchForm.reset()
    showBranchPrice.value = true
}

function submitBranchPrice() {
    const target = branchTarget.value
    if (!target) return

    // ส่ง null ไปเมื่อช่องว่าง เซิร์ฟเวอร์จะล้างค่าทับกลับไปใช้ค่ากลาง
    branchForm
        .transform((d) => ({
            price: d.price === '' ? null : Number(d.price),
            is_active: d.is_active === '' ? null : d.is_active === '1',
            sort_order: d.sort_order === '' ? null : Number(d.sort_order),
            print_group: d.print_group === '' ? null : Number(d.print_group),
        }))
        .put(`/backoffice/products/${target.id}/branch`, {
            preserveScroll: true,
            onSuccess: () => (showBranchPrice.value = false),
        })
}

function clearBranchPrice() {
    branchForm.price = ''
    branchForm.is_active = ''
    branchForm.sort_order = ''
    branchForm.print_group = ''
}

/* ---------- จัดการหมวดหมู่ ---------- */

const showCategories = ref(false)

type CategoryDraft = Category & { is_active: boolean; sort_order: number; products_count: number }

/** สำเนาไว้แก้ในหน้าต่าง ไม่แตะ props ตรง ๆ */
const drafts = ref<CategoryDraft[]>([])

function loadDrafts() {
    drafts.value = props.categories.map((c) => ({
        ...c,
        is_active: c.is_active ?? true,
        sort_order: c.sort_order ?? 0,
        products_count: c.products_count ?? 0,
    }))
}

loadDrafts()
watch(() => props.categories, loadDrafts, { deep: true })

const newCategory = ref({ name: '', color: '#64748b' })

/** ข้อความ validation ต่อแถว — คีย์ 'new' คือแถวเพิ่มใหม่ */
const catErrors = ref<Record<string | number, string>>({})

function firstError(errors: Record<string, string>) {
    return Object.values(errors)[0] ?? 'บันทึกไม่สำเร็จ'
}

function addCategory() {
    if (!newCategory.value.name.trim()) return

    router.post(
        '/backoffice/categories',
        { name: newCategory.value.name, color: newCategory.value.color, is_active: true },
        {
            preserveScroll: true,
            onSuccess: () => {
                newCategory.value = { name: '', color: '#64748b' }
                delete catErrors.value.new
            },
            onError: (e) => (catErrors.value.new = firstError(e)),
        },
    )
}

function saveCategory(c: CategoryDraft) {
    router.put(
        `/backoffice/categories/${c.id}`,
        { name: c.name, color: c.color, is_active: c.is_active, sort_order: c.sort_order },
        {
            preserveScroll: true,
            onSuccess: () => delete catErrors.value[c.id],
            onError: (e) => (catErrors.value[c.id] = firstError(e)),
        },
    )
}

function removeCategory(c: CategoryDraft) {
    router.delete(`/backoffice/categories/${c.id}`, { preserveScroll: true })
}

/* ---------- เซ็ตตัวเลือกของเมนู ---------- */

interface GroupLink {
    modifier_group_id: number
    is_active: boolean
}

const showSets = ref(false)
const setsProduct = ref<Row | null>(null)
const setsForm = useForm<{ items: GroupLink[] }>({ items: [] })

function openSets(p: Row) {
    setsProduct.value = p

    // เรียงตาม sort_order เดิม เซ็ตที่ผูกไว้ก่อนต้องอยู่ลำดับเดิมหลังบันทึก
    const links: GroupLink[] = (p.modifier_groups ?? [])
        .slice()
        .sort((a: any, b: any) => (a.pivot?.sort_order ?? 0) - (b.pivot?.sort_order ?? 0))
        .map((g: any) => ({
            modifier_group_id: g.id,
            is_active: !!g.pivot?.is_active,
        }))

    setsForm.defaults({ items: links })
    setsForm.reset()
    setsForm.clearErrors()
    showSets.value = true
}

function setLinkOf(groupId: number): GroupLink | undefined {
    return setsForm.items.find((l) => l.modifier_group_id === groupId)
}

function toggleSet(groupId: number) {
    const i = setsForm.items.findIndex((l) => l.modifier_group_id === groupId)

    if (i >= 0) {
        setsForm.items.splice(i, 1)
    } else {
        setsForm.items.push({ modifier_group_id: groupId, is_active: true })
    }
}

function toggleSetActive(groupId: number) {
    const link = setLinkOf(groupId)
    if (link) link.is_active = !link.is_active
}

function submitSets() {
    if (!setsProduct.value) return
    setsForm.put(`/backoffice/products/${setsProduct.value.id}/modifier-groups`, {
        preserveScroll: true,
        onSuccess: () => (showSets.value = false),
    })
}

/* ---------- กลุ่มโปรโมทบนหน้าสั่งอาหาร ---------- */

const showPromo = ref(false)

const promoForm = useForm<{
    promo_title: string
    featured_id: number | ''
    items: PromoItem[]
}>({
    promo_title: props.promo.title,
    featured_id: props.promo.featured_id ?? '',
    items: props.promo.items.map((i) => ({ ...i })),
})

function openPromo() {
    promoForm.defaults({
        promo_title: props.promo.title,
        featured_id: props.promo.featured_id ?? '',
        items: props.promo.items.map((i) => ({ ...i })),
    })
    promoForm.reset()
    promoForm.clearErrors()
    showPromo.value = true
}

/** เมนูที่ยังไม่อยู่ในตะแกรง ไว้เติมในช่องเลือก */
const promoCandidates = computed(() => {
    const used = new Set(promoForm.items.map((i) => i.product_id))
    return props.allProducts.filter((p) => !used.has(p.id))
})

const promoFull = computed(() => promoForm.items.length >= props.promo.max_slots)

function nameOf(id: number): string {
    return props.allProducts.find((p) => p.id === id)?.name ?? '(ถูกลบไปแล้ว)'
}

function imageOf(id: number): string | null {
    return props.allProducts.find((p) => p.id === id)?.image_path ?? null
}

/** ข้อมูลของเมนูที่เลือกเป็นสินค้าเด่น ไว้วาดแผ่นตัวอย่าง */
const featuredPreview = computed(() => {
    if (promoForm.featured_id === '') return null
    const id = Number(promoForm.featured_id)
    const p = props.allProducts.find((x) => x.id === id)
    if (!p) return null
    const label = promoForm.items.find((i) => i.product_id === id)?.promo_label || ''
    return { name: p.name, image: p.image_path, label }
})

const addPromoId = ref<number | ''>('')

function addPromo() {
    if (addPromoId.value === '' || promoFull.value) return
    promoForm.items.push({ product_id: Number(addPromoId.value), promo_label: '' })
    addPromoId.value = ''
}

function movePromo(index: number, delta: number) {
    const to = index + delta
    if (to < 0 || to >= promoForm.items.length) return
    const [row] = promoForm.items.splice(index, 1)
    promoForm.items.splice(to, 0, row)
}

function submitPromo() {
    promoForm.transform((d) => ({ ...d, featured_id: d.featured_id === '' ? null : d.featured_id }))
    promoForm.put('/backoffice/promo', {
        preserveScroll: true,
        onSuccess: () => (showPromo.value = false),
    })
}

/** สรุปเซ็ตที่เมนูนี้แสดงจริง ไว้โชว์ในตาราง */
function activeSetNames(p: Row): string[] {
    return (p.modifier_groups ?? [])
        .filter((g: any) => g.is_active && g.pivot?.is_active)
        .map((g: any) => g.display_name || g.name)
}
</script>

<template>
    <Head title="รายละเอียดสินค้า" />

    <BackOfficeLayout title="รายละเอียดสินค้า">
        <SectionCard title="สินค้าทั้งหมด" content-class="p-0">
            <template #actions>
                <div class="flex flex-wrap gap-2">
                    <Input v-model="search" placeholder="ค้นหาชื่อ/SKU" class="w-44" @keyup.enter="reload" />
                    <Select v-model="categoryId" class="w-40" @change="reload">
                        <option value="">ทุกหมวดหมู่</option>
                        <option v-for="c in activeCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </Select>
                    <Button variant="outline" size="sm" @click="showCategories = true">
                        <Tags />
                        จัดการหมวดหมู่
                    </Button>
                    <Button variant="outline" size="sm" @click="openPromo">
                        <Sparkles />
                        กลุ่มโปรโมท
                    </Button>
                    <Button variant="brand" size="sm" @click="openCreate">
                        <Plus />
                        เพิ่มสินค้า
                    </Button>
                </div>
            </template>

            <DataTable v-if="products.data.length">
                <thead>
                    <tr>
                        <th class="w-14">รูป</th>
                        <th>ชื่อสินค้า</th>
                        <th>หมวดหมู่</th>
                        <th>SKU</th>
                        <th class="text-right">ราคาขาย</th>
                        <th class="text-right">ต้นทุน</th>
                        <th class="text-right">กำไร</th>
                        <th>เซ็ตตัวเลือก</th>
                        <th>สถานะ</th>
                        <th class="w-10"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id">
                        <td>
                            <img
                                v-if="p.image_path"
                                :src="p.image_path"
                                :alt="p.name"
                                class="size-10 rounded-md object-cover"
                                loading="lazy"
                            />
                            <span
                                v-else
                                class="grid size-10 place-items-center rounded-md bg-muted text-[11px] text-muted-foreground"
                                aria-label="ยังไม่มีรูป"
                            >
                                ไม่มีรูป
                            </span>
                        </td>
                        <td>
                            <button
                                type="button"
                                class="text-start font-medium underline-offset-2 hover:underline"
                                @click="openEdit(p)"
                            >
                                {{ p.name }}
                            </button>
                            <span
                                v-if="isCentral(p)"
                                class="ms-1.5 rounded px-1.5 py-0.5 text-[10px] text-muted-foreground ring-1 ring-border"
                                title="เมนูกลาง ทุกสาขาใช้ร่วมกัน"
                            >
                                กลาง
                            </span>
                        </td>
                        <td>
                            <span v-if="p.category" class="inline-flex items-center gap-1.5 text-muted-foreground">
                                <span class="size-2 rounded-full" :style="{ background: p.category.color }" />
                                {{ p.category.name }}
                            </span>
                            <span v-else class="text-muted-foreground">-</span>
                        </td>
                        <td class="tabular text-muted-foreground">{{ p.sku ?? '-' }}</td>
                        <td class="tabular text-right">
                            <button
                                type="button"
                                class="underline-offset-2 hover:underline"
                                :class="hasOverride(p) && 'font-semibold text-[var(--series-1)]'"
                                :title="hasOverride(p) ? 'สาขานี้ตั้งราคาเอง — กดเพื่อแก้' : 'กดเพื่อตั้งราคาเฉพาะสาขานี้'"
                                @click="openBranchPrice(p)"
                            >
                                {{ money(priceHere(p)) }}
                            </button>
                            <span
                                v-if="overrideOf(p)?.price !== null && overrideOf(p)?.price !== undefined"
                                class="block text-[11px] font-normal text-muted-foreground line-through"
                            >
                                {{ money(p.price) }}
                            </span>
                        </td>
                        <td class="tabular text-right text-muted-foreground">{{ money(p.cost) }}</td>
                        <td class="tabular text-right">{{ money(priceHere(p) - Number(p.cost)) }}</td>
                        <td>
                            <button
                                type="button"
                                class="flex max-w-56 items-center gap-1.5 rounded-md px-1.5 py-1 text-start text-xs text-muted-foreground hover:bg-accent/50 hover:text-foreground"
                                @click="openSets(p)"
                            >
                                <Layers class="size-3.5 shrink-0" />
                                <span class="truncate">
                                    {{ activeSetNames(p).length ? activeSetNames(p).join(' · ') : 'ยังไม่ผูก' }}
                                </span>
                            </button>
                        </td>
                        <td>
                            <Badge v-if="!p.is_active" variant="secondary">ปิดทุกสาขา</Badge>
                            <Badge v-else-if="overrideOf(p)?.is_active === false" variant="warning">ปิดที่สาขานี้</Badge>
                            <Badge v-else variant="success">เปิดขาย</Badge>
                        </td>
                        <td>
                            <div class="flex justify-end">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="ค่าเฉพาะสาขานี้"
                                    title="ราคาและการเปิดขายของสาขานี้"
                                    @click="openBranchPrice(p)"
                                >
                                    <Store :class="hasOverride(p) && 'text-[var(--series-1)]'" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="canEdit(p) ? 'แก้ไขสินค้า' : 'ดูข้อมูลเมนูกลาง'"
                                    @click="openEdit(p)"
                                >
                                    <Pencil />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else description="ยังไม่มีสินค้า ลองเพิ่มเมนูแรกดู" />

            <Pagination :links="products.links" :total="products.total" />
        </SectionCard>

        <!-- ══ เพิ่ม / แก้ไขสินค้า ══ -->
        <Modal
            v-model:open="showForm"
            :title="editing ? 'แก้ไขสินค้า' : 'เพิ่มสินค้า'"
            :description="editing ? editing.name : 'กรอกข้อมูลเมนูใหม่'"
            class="max-w-6xl"
        >
            <!--
              สองคอลัมน์บนจอกว้าง: กรอกซ้าย เห็นผลขวา
              จอแคบกว่านั้นให้ตัวอย่างไปอยู่บนสุด เพราะเป็นสิ่งที่คนเปิดหน้านี้มาดู
            -->
            <div class="grid max-h-[75dvh] gap-5 overflow-y-auto lg:grid-cols-[minmax(0,1fr)_22rem] xl:grid-cols-[minmax(0,1fr)_26rem]">
                <div class="order-2 min-w-0 lg:order-1">
                <!--
                  เมนูกลางกระทบทุกสาขา ต้องบอกก่อนที่จะเริ่มพิมพ์
                  ผู้จัดการที่แก้ไม่ได้จะได้ไม่เสียเวลากรอกจนจบแล้วโดนปฏิเสธ
                -->
                <p
                    v-if="editing && isCentral(editing)"
                    class="mb-4 rounded-lg border border-dashed p-3 text-sm"
                    :class="canEditCentral
                        ? 'border-[var(--status-warning)] bg-[var(--status-warning)]/10'
                        : 'bg-muted/50 text-muted-foreground'"
                >
                    <template v-if="canEditCentral">
                        <span class="font-medium">เมนูกลาง — แก้ที่นี่กระทบทุกสาขา</span>
                        <span class="mt-0.5 block text-xs">
                            ถ้าอยากเปลี่ยนแค่สาขา {{ branchName }} ให้ปิดหน้าต่างนี้แล้วกดปุ่มรูปร้านที่ท้ายแถวแทน
                        </span>
                    </template>
                    <template v-else>
                        <span class="font-medium">เมนูกลาง — แก้ได้เฉพาะเจ้าของ</span>
                        <span class="mt-0.5 block text-xs">
                            สาขา {{ branchName }} ปรับได้เฉพาะราคาและการเปิดขาย ผ่านปุ่มรูปร้านที่ท้ายแถว
                        </span>
                    </template>
                </p>

                <form class="space-y-4" @submit.prevent="submit">
                    <ImageField
                        v-model:file="form.image"
                        v-model:removed="form.remove_image"
                        :existing="(editing?.image_path as string | null) ?? null"
                        :spec="imageSpec"
                        :error="form.errors.image"
                        shape="square"
                    />

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="p-name">ชื่อสินค้า</Label>
                            <Input id="p-name" v-model="form.name" required />
                            <p v-if="form.errors.name" class="text-xs text-[var(--status-critical)]">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label for="p-category">หมวดหมู่</Label>
                            <Select id="p-category" v-model="form.category_id">
                                <option value="">ไม่ระบุ</option>
                                <option v-for="c in activeCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </Select>
                            <p v-if="form.errors.category_id" class="text-xs text-[var(--status-critical)]">
                                {{ form.errors.category_id }}
                            </p>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label for="p-desc">คำอธิบาย</Label>
                        <textarea
                            id="p-desc"
                            v-model="form.description"
                            rows="2"
                            maxlength="1000"
                            class="w-full resize-none rounded-md border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            placeholder="แสดงใต้ชื่อเมนูบนหน้าสั่งของลูกค้า"
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label for="p-price">ราคาขาย</Label>
                            <Input id="p-price" v-model="form.price" type="number" step="0.01" min="0" required class="tabular" />
                            <p v-if="form.errors.price" class="text-xs text-[var(--status-critical)]">{{ form.errors.price }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label for="p-cost">ต้นทุน</Label>
                            <Input id="p-cost" v-model="form.cost" type="number" step="0.01" min="0" class="tabular" />
                        </div>
                        <div class="space-y-1">
                            <Label for="p-staff">ราคาพนักงาน</Label>
                            <Input id="p-staff" v-model="form.staff_price" type="number" step="0.01" min="0" class="tabular" />
                            <p v-if="form.errors.staff_price" class="text-xs text-[var(--status-critical)]">
                                {{ form.errors.staff_price }}
                            </p>
                            <p v-else class="text-xs text-muted-foreground">เว้นว่าง = ไม่มีสิทธิ์ลด</p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label for="p-sku">SKU</Label>
                            <Input id="p-sku" v-model="form.sku" />
                            <p v-if="form.errors.sku" class="text-xs text-[var(--status-critical)]">{{ form.errors.sku }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label for="p-barcode">บาร์โค้ด</Label>
                            <Input id="p-barcode" v-model="form.barcode" />
                        </div>
                        <div class="space-y-1">
                            <Label for="p-unit">หน่วย</Label>
                            <Input id="p-unit" v-model="form.unit" placeholder="รายการ" />
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="p-print">จุดผลิต</Label>
                            <Select id="p-print" v-model="form.print_group">
                                <option v-for="g in printGroups" :key="g.value" :value="g.value">{{ g.label }}</option>
                            </Select>
                            <p class="text-xs text-muted-foreground">ใบสั่งจะถูกส่งไปที่จุดนี้</p>
                        </div>
                        <div class="space-y-1">
                            <Label for="p-sort">ลำดับ</Label>
                            <Input id="p-sort" v-model="form.sort_order" type="number" min="0" class="tabular" />
                            <p class="text-xs text-muted-foreground">น้อยขึ้นก่อนบนหน้าขาย</p>
                        </div>
                    </div>

                    <div class="grid gap-2 rounded-lg border p-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-input" />
                            เปิดขาย
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input v-model="form.track_stock" type="checkbox" class="size-4 rounded border-input" />
                            ตัดสต๊อกวัตถุดิบตามสูตร
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input v-model="form.is_open_price" type="checkbox" class="size-4 rounded border-input" />
                            ราคาเปิด (พนักงานกรอกเอง)
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input v-model="form.is_alcohol" type="checkbox" class="size-4 rounded border-input" />
                            เครื่องดื่มแอลกอฮอล์
                        </label>
                    </div>

                    <!-- ป้ายข้อมูลอาหาร — ขึ้นบนการ์ดเมนูและในหน้าต่างสั่งของลูกค้า -->
                    <div class="space-y-3 rounded-lg border p-3">
                        <div class="flex items-center gap-2">
                            <Tags class="size-4 shrink-0 text-muted-foreground" />
                            <p class="text-sm font-medium">ป้ายข้อมูลอาหาร</p>
                            <span class="text-xs text-muted-foreground">
                                {{ form.diet_tags.length ? `ติ๊กไว้ ${form.diet_tags.length} ป้าย` : 'ยังไม่ได้ติ๊ก' }}
                            </span>
                        </div>

                        <div v-for="group in dietTags" :key="group.kind" class="space-y-1.5">
                            <p class="text-xs font-medium">{{ group.label }}</p>
                            <p class="text-[11px] leading-relaxed text-muted-foreground">{{ group.hint }}</p>

                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="tag in group.tags"
                                    :key="tag.value"
                                    type="button"
                                    class="rounded-full border px-2.5 py-1 text-xs transition-colors"
                                    :class="
                                        form.diet_tags.includes(tag.value)
                                            ? DIET_KIND_CLASS[group.kind] ?? 'border-input bg-muted'
                                            : 'border-input text-muted-foreground hover:bg-accent'
                                    "
                                    :aria-pressed="form.diet_tags.includes(tag.value)"
                                    @click="toggleDietTag(tag.value)"
                                >
                                    {{ tag.label }}
                                </button>
                            </div>
                        </div>

                        <p v-if="form.errors.diet_tags" class="text-xs text-destructive">
                            {{ form.errors.diet_tags }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 border-t pt-3">
                        <template v-if="editing">
                            <Button
                                v-if="!confirmingDelete"
                                type="button"
                                variant="ghost"
                                class="text-[var(--status-critical)]"
                                @click="confirmingDelete = true"
                            >
                                <Trash2 />
                                ลบสินค้า
                            </Button>
                            <template v-else>
                                <span class="text-sm text-[var(--status-critical)]">ลบเมนูนี้จริงไหม</span>
                                <Button type="button" variant="destructive" size="sm" @click="destroy">ยืนยันลบ</Button>
                                <Button type="button" variant="ghost" size="sm" @click="confirmingDelete = false">
                                    ไม่ลบ
                                </Button>
                            </template>
                        </template>

                        <div class="ms-auto flex gap-2">
                            <Button type="button" variant="outline" @click="closeForm">ยกเลิก</Button>
                            <Button
                                type="submit"
                                variant="brand"
                                :disabled="form.processing || (!!editing && !canEdit(editing))"
                            >
                                {{ form.processing ? 'กำลังบันทึก…' : 'บันทึก' }}
                            </Button>
                        </div>
                    </div>
                </form>
                </div>

                <!-- ตัวอย่าง — ติดขอบบนไว้ให้เห็นตลอดตอนเลื่อนฟอร์มยาว ๆ -->
                <div class="order-1 min-w-0 lg:order-2">
                    <div class="lg:sticky lg:top-0">
                        <div class="mb-2 flex items-baseline justify-between gap-2">
                            <p class="text-sm font-semibold">ลูกค้าจะเห็นแบบนี้</p>
                            <p class="text-xs text-muted-foreground">ยังไม่บันทึกจนกว่าจะกดปุ่ม</p>
                        </div>

                        <ProductPreview
                            :product-id="editing ? Number(editing.id) : null"
                            :name="form.name"
                            :description="form.description || null"
                            :price="form.price"
                            :staff-price="form.staff_price === '' ? null : form.staff_price"
                            :image="previewSrc"
                            :promo-label="(editing?.promo_label as string | null) ?? null"
                            :is-active="form.is_active"
                            :diet-tags="selectedDietTags"
                        />
                    </div>
                </div>
            </div>
        </Modal>

        <!-- ══ จัดการหมวดหมู่ ══ -->
        <Modal
            v-model:open="showCategories"
            title="จัดการหมวดหมู่"
            description="สีที่เลือกคือสีปุ่มบนหน้าขาย ลำดับน้อยขึ้นก่อน"
            class="max-w-2xl"
        >
            <div class="space-y-3">
                <!-- เพิ่มหมวดใหม่ -->
                <div class="rounded-lg border border-dashed p-3">
                    <div class="flex items-end gap-2">
                        <div class="space-y-1">
                            <Label for="nc-color">สี</Label>
                            <input
                                id="nc-color"
                                v-model="newCategory.color"
                                type="color"
                                class="h-9 w-12 cursor-pointer rounded-md border border-input bg-background p-1"
                            />
                        </div>
                        <div class="min-w-0 flex-1 space-y-1">
                            <Label for="nc-name">ชื่อหมวดใหม่</Label>
                            <Input id="nc-name" v-model="newCategory.name" placeholder="ของหวาน" @keyup.enter="addCategory" />
                        </div>
                        <Button variant="brand" :disabled="!newCategory.name.trim()" @click="addCategory">
                            <Plus />
                            เพิ่ม
                        </Button>
                    </div>
                    <p v-if="catErrors.new" class="mt-1.5 text-xs text-[var(--status-critical)]">{{ catErrors.new }}</p>
                </div>

                <!-- รายการหมวดที่มีอยู่ -->
                <EmptyState v-if="!drafts.length" title="ยังไม่มีหมวดหมู่" />

                <div v-else class="max-h-80 space-y-2 overflow-y-auto">
                    <div v-for="c in drafts" :key="c.id" class="flex flex-wrap items-end gap-2 rounded-lg border p-2.5">
                        <input
                            v-model="c.color"
                            type="color"
                            class="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-input bg-background p-1"
                            :aria-label="`สีของ ${c.name}`"
                        />

                        <Input v-model="c.name" class="min-w-32 flex-1" :aria-label="`ชื่อหมวด ${c.name}`" />

                        <div class="w-20 space-y-1">
                            <Label :for="`c-sort-${c.id}`" class="text-[11px]">ลำดับ</Label>
                            <Input
                                :id="`c-sort-${c.id}`"
                                v-model="c.sort_order"
                                type="number"
                                min="0"
                                class="tabular"
                            />
                        </div>

                        <label class="flex h-9 shrink-0 cursor-pointer items-center gap-1.5 text-xs text-muted-foreground">
                            <input v-model="c.is_active" type="checkbox" class="size-4 rounded border-input" />
                            เปิด
                        </label>

                        <span class="h-9 shrink-0 self-end pt-2 text-[11px] text-muted-foreground">
                            {{ c.products_count }} เมนู
                        </span>

                        <div class="flex shrink-0 gap-1">
                            <Button variant="outline" size="icon" aria-label="บันทึก" @click="saveCategory(c)">
                                <Save />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :disabled="c.products_count > 0"
                                :title="c.products_count ? `ยังมีเมนูอยู่ ${c.products_count} รายการ` : 'ลบ'"
                                aria-label="ลบหมวดหมู่"
                                @click="removeCategory(c)"
                            >
                                <Trash2 />
                            </Button>
                        </div>

                        <p v-if="catErrors[c.id]" class="w-full text-xs text-[var(--status-critical)]">
                            {{ catErrors[c.id] }}
                        </p>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    หมวดที่ยังมีเมนูอยู่ลบไม่ได้ — ถ้าไม่อยากให้โผล่บนหน้าขายให้ติ๊ก "เปิด" ออกแทน
                    เมนูเดิมจะยังขายได้ตามปกติ
                </p>

                <div class="flex justify-end pt-1">
                    <Button variant="outline" @click="showCategories = false">ปิด</Button>
                </div>
            </div>
        </Modal>

        <!-- ══ เซ็ตตัวเลือกของเมนู ══ -->
        <Modal
            v-model:open="showSets"
            :title="`เซ็ตตัวเลือกของ ${setsProduct?.name ?? ''}`"
            description="ติ๊กซ้ายเพื่อผูกเซ็ตเข้าเมนูนี้ ติ๊กขวาคือให้แสดงบนหน้าขาย"
            class="max-w-2xl"
        >
            <form class="space-y-3" @submit.prevent="submitSets">
                <EmptyState
                    v-if="!modifierGroups.length"
                    title="ยังไม่มีเซ็ตตัวเลือก"
                    description="สร้างเซ็ตก่อนที่หน้า เซ็ตตัวเลือก แล้วค่อยกลับมาผูกกับเมนู"
                />

                <div v-else class="max-h-72 space-y-1 overflow-y-auto rounded-lg border p-2">
                    <div
                        v-for="g in modifierGroups"
                        :key="g.id"
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent/50"
                        :class="!g.is_active && 'opacity-60'"
                    >
                        <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
                            <input
                                type="checkbox"
                                class="size-4 shrink-0 rounded border-input"
                                :checked="!!setLinkOf(g.id)"
                                @change="toggleSet(g.id)"
                            />
                            <span class="min-w-0 flex-1 truncate">{{ g.name }}</span>
                        </label>

                        <span
                            class="inline-flex shrink-0 items-center gap-1 rounded bg-[var(--series-1)]/10 px-1.5 py-0.5 text-[11px] text-[var(--series-1)]"
                            title="ชื่อที่ลูกค้าเห็น"
                        >
                            <Eye class="size-3" />
                            {{ g.shown_as }}
                        </span>

                        <span
                            v-if="!g.is_active"
                            class="shrink-0 rounded bg-[var(--status-warning)]/10 px-1.5 py-0.5 text-[11px] text-[var(--status-warning)]"
                        >
                            ปิดทั้งเซ็ต
                        </span>

                        <label
                            class="flex w-20 shrink-0 items-center gap-1.5 text-[11px]"
                            :class="setLinkOf(g.id) ? 'cursor-pointer text-muted-foreground' : 'opacity-30'"
                        >
                            <input
                                type="checkbox"
                                class="size-4 rounded border-input"
                                :checked="setLinkOf(g.id)?.is_active ?? false"
                                :disabled="!setLinkOf(g.id)"
                                :aria-label="`แสดงเซ็ต ${g.name} บนเมนูนี้`"
                                @change="toggleSetActive(g.id)"
                            />
                            แสดง
                        </label>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    เซ็ตที่ "ปิดทั้งเซ็ต" จะไม่โผล่หน้าขายถึงจะติ๊กแสดงไว้ — ไปเปิดที่หน้าเซ็ตตัวเลือกก่อน
                </p>

                <div class="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="outline" @click="showSets = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="setsForm.processing">บันทึก</Button>
                </div>
            </form>
        </Modal>

        <!-- ══ กลุ่มโปรโมทบนหน้าสั่งอาหาร ══ -->
        <Modal
            v-model:open="showPromo"
            title="กลุ่มโปรโมท"
            description="สินค้าเด่นรูปใหญ่ 1 เมนู + ตะแกรง 2 คอลัมน์ 4 แถว บนหน้าสั่งอาหารของลูกค้า"
            class="max-w-2xl"
        >
            <form class="space-y-4" @submit.prevent="submitPromo">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="promo-title">หัวข้อกลุ่ม</Label>
                        <Input id="promo-title" v-model="promoForm.promo_title" maxlength="60" placeholder="สำหรับคุณ" />
                        <p v-if="promoForm.errors.promo_title" class="text-xs text-[var(--status-critical)]">
                            {{ promoForm.errors.promo_title }}
                        </p>
                        <p v-else class="text-xs text-muted-foreground">เช่น สำหรับคุณ / ขายดี / เมนูแนะนำ</p>
                    </div>

                    <div class="space-y-1">
                        <Label for="promo-featured">สินค้าเด่น (รูปใหญ่บนสุด)</Label>
                        <Select id="promo-featured" v-model="promoForm.featured_id">
                            <option value="">ไม่แสดงรูปใหญ่</option>
                            <option v-for="p in allProducts" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </Select>
                        <p class="text-xs text-muted-foreground">เลือกอิสระจากตะแกรงด้านล่าง</p>
                    </div>
                </div>

                <!-- ตัวอย่างรูปใหญ่อย่างที่ลูกค้าเห็น -->
                <div class="space-y-1.5">
                    <p class="text-sm font-medium">ตัวอย่างสินค้าเด่นบนหน้าสั่งอาหาร</p>

                    <div v-if="featuredPreview" class="relative max-w-sm overflow-hidden rounded-xl border">
                        <img
                            v-if="featuredPreview.image"
                            :src="featuredPreview.image"
                            :alt="featuredPreview.name"
                            class="aspect-video w-full object-cover"
                        />
                        <div
                            v-else
                            class="aspect-video w-full"
                            :style="{ background: 'linear-gradient(135deg, var(--series-1), var(--series-7))' }"
                        />

                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-3 pt-10">
                            <span
                                v-if="featuredPreview.label"
                                class="mb-1 inline-block rounded-full bg-[var(--status-good)] px-2 py-0.5 text-[11px] font-medium text-white"
                            >
                                {{ featuredPreview.label }}
                            </span>
                            <p class="truncate text-base font-bold text-white">{{ featuredPreview.name }}</p>
                        </div>
                    </div>

                    <p
                        v-else
                        class="max-w-sm rounded-xl border border-dashed py-8 text-center text-xs text-muted-foreground"
                    >
                        ยังไม่ได้เลือกสินค้าเด่น — หน้าลูกค้าจะเริ่มที่กลุ่มโปรโมทเลย
                    </p>

                    <p class="text-xs text-muted-foreground">
                        ป้ายบนรูปใหญ่ใช้ป้ายเดียวกับที่ตั้งไว้ในตะแกรง (ถ้าเมนูนั้นอยู่ในตะแกรงด้วย)
                    </p>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <Label>ตะแกรงโปรโมท</Label>
                        <span class="text-xs text-muted-foreground">
                            {{ promoForm.items.length }} / {{ promo.max_slots }} ช่อง
                        </span>
                    </div>

                    <p
                        v-if="!promoForm.items.length"
                        class="rounded-lg border border-dashed py-6 text-center text-sm text-muted-foreground"
                    >
                        ยังไม่ได้เลือกเมนู — กลุ่มนี้จะไม่แสดงบนหน้าสั่งอาหาร
                    </p>

                    <ul v-else class="space-y-2">
                        <li
                            v-for="(row, i) in promoForm.items"
                            :key="row.product_id"
                            class="flex items-center gap-2 rounded-lg border p-2"
                        >
                            <span class="tabular w-5 shrink-0 text-center text-xs text-muted-foreground">{{ i + 1 }}</span>

                            <img
                                v-if="imageOf(row.product_id)"
                                :src="imageOf(row.product_id) ?? undefined"
                                :alt="nameOf(row.product_id)"
                                class="size-10 shrink-0 rounded-md object-cover"
                            />
                            <span
                                v-else
                                class="grid size-10 shrink-0 place-items-center rounded-md bg-muted text-[10px] text-muted-foreground"
                            >
                                ไม่มีรูป
                            </span>

                            <span class="min-w-0 flex-1 truncate text-sm">
                                {{ nameOf(row.product_id) }}
                                <Star
                                    v-if="promoForm.featured_id === row.product_id"
                                    class="inline size-3.5 text-[var(--status-warning)]"
                                    aria-label="เป็นสินค้าเด่นด้วย"
                                />
                            </span>

                            <Input
                                v-model="row.promo_label"
                                class="w-40 shrink-0"
                                maxlength="20"
                                placeholder="ป้าย เช่น ขายดี"
                                :aria-label="`ป้ายของ ${nameOf(row.product_id)}`"
                            />

                            <div class="flex shrink-0 gap-0.5">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :disabled="i === 0"
                                    aria-label="เลื่อนขึ้น"
                                    @click="movePromo(i, -1)"
                                >
                                    <ArrowUp />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :disabled="i === promoForm.items.length - 1"
                                    aria-label="เลื่อนลง"
                                    @click="movePromo(i, 1)"
                                >
                                    <ArrowDown />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="เอาออกจากตะแกรง"
                                    @click="promoForm.items.splice(i, 1)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </li>
                    </ul>

                    <div class="flex items-end gap-2 rounded-lg border border-dashed p-2">
                        <div class="min-w-0 flex-1 space-y-1">
                            <Label for="promo-add" class="text-[11px]">เพิ่มเมนูเข้าตะแกรง</Label>
                            <Select id="promo-add" v-model="addPromoId" :disabled="promoFull">
                                <option value="">เลือกเมนู</option>
                                <option v-for="p in promoCandidates" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </Select>
                        </div>
                        <Button type="button" variant="outline" :disabled="promoFull || addPromoId === ''" @click="addPromo">
                            <Plus />
                            เพิ่ม
                        </Button>
                    </div>

                    <p v-if="promoFull" class="text-xs text-[var(--status-warning)]">
                        เต็ม {{ promo.max_slots }} ช่องแล้ว เอาเมนูออกก่อนถึงจะเพิ่มใหม่ได้
                    </p>
                    <p v-if="promoForm.errors.items" class="text-xs text-[var(--status-critical)]">
                        {{ promoForm.errors.items }}
                    </p>
                </div>

                <p class="text-xs text-muted-foreground">
                    เมนูที่ปิดขาย ของหมด หรือตั้งเป็นราคาเปิด จะไม่โผล่บนหน้าสั่งอาหารถึงจะใส่ไว้ในตะแกรง
                </p>

                <div class="flex justify-end gap-2 border-t pt-3">
                    <Button type="button" variant="outline" @click="showPromo = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="promoForm.processing">บันทึก</Button>
                </div>
            </form>
        </Modal>

        <!-- ══ ค่าเฉพาะสาขานี้ ══ -->
        <Modal
            v-model:open="showBranchPrice"
            title="ค่าเฉพาะสาขานี้"
            :description="branchTarget ? `${branchTarget.name} · สาขา ${branchName}` : ''"
            class="max-w-lg"
        >
            <form class="space-y-4" @submit.prevent="submitBranchPrice">
                <p class="rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">
                    เว้นว่าง = ใช้ค่ากลาง · ค่าที่กรอกที่นี่มีผลกับสาขา
                    <span class="font-medium text-foreground">{{ branchName }}</span> เท่านั้น
                    ไม่กระทบเมนูกลางและสาขาอื่น
                </p>

                <div class="space-y-1">
                    <Label for="bprice">
                        ราคาขายของสาขานี้
                        <span class="font-normal text-muted-foreground">
                            (ราคากลาง {{ branchTarget ? money(branchTarget.price) : '-' }})
                        </span>
                    </Label>
                    <Input
                        id="bprice"
                        v-model="branchForm.price"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="ใช้ราคากลาง"
                    />
                    <p v-if="branchForm.errors.price" class="text-xs text-[var(--status-critical)]">
                        {{ branchForm.errors.price }}
                    </p>
                    <p v-else class="text-xs text-muted-foreground">ใส่ 0 = แจกฟรีที่สาขานี้ ซึ่งต่างจากเว้นว่าง</p>
                </div>

                <div class="space-y-1">
                    <Label for="bactive">การขายที่สาขานี้</Label>
                    <Select id="bactive" v-model="branchForm.is_active">
                        <option value="">ตามค่ากลาง</option>
                        <option value="1">เปิดขายที่สาขานี้</option>
                        <option value="0">ไม่ขายที่สาขานี้</option>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        ปิดที่ส่วนกลางแล้วสาขาเปิดทับไม่ได้ — ปิดกลางคือปิดทุกสาขา
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="bsort">ลำดับการแสดงผล</Label>
                        <Input id="bsort" v-model="branchForm.sort_order" type="number" min="0" placeholder="ตามค่ากลาง" />
                    </div>
                    <div class="space-y-1">
                        <Label for="bgroup">จุดพิมพ์</Label>
                        <Select id="bgroup" v-model="branchForm.print_group">
                            <option value="">ตามค่ากลาง</option>
                            <option v-for="g in printGroups" :key="g.value" :value="String(g.value)">
                                {{ g.label }}
                            </option>
                        </Select>
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <Button type="button" variant="ghost" size="sm" @click="clearBranchPrice">
                        กลับไปใช้ค่ากลางทั้งหมด
                    </Button>

                    <div class="ms-auto flex gap-2">
                        <Button type="button" variant="outline" @click="showBranchPrice = false">ยกเลิก</Button>
                        <Button type="submit" variant="brand" :disabled="branchForm.processing">
                            {{ branchForm.processing ? 'กำลังบันทึก…' : 'บันทึก' }}
                        </Button>
                    </div>
                </div>
            </form>
        </Modal>
    </BackOfficeLayout>
</template>
