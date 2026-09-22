<script setup lang="ts">
/**
 * โปรโมชั่นรายสาขา
 *
 * หน้าจอให้เลือกเป็น 5 แบบตามที่ร้านคิด แต่ส่งขึ้นเซิร์ฟเวอร์เป็นสองแกน
 * (เงื่อนไข × รางวัล) แบบที่ 2 กับ 3 มีทางแยกว่าจะ "แถมเมนู" หรือ "ลดราคา"
 *
 * ป้ายกำกับรางวัลทั้งหมดมาจากเซิร์ฟเวอร์ ไม่ได้พิมพ์ซ้ำที่นี่
 * ไม่งั้นวันเพิ่มรางวัลแบบใหม่จะต้องไล่แก้สองที่แล้วลืมที่หนึ่ง
 */
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import {
    AlertCircle,
    BadgePercent,
    Calendar,
    Check,
    CheckCircle2,
    Clock,
    Coins,
    Gift,
    HelpCircle,
    Info,
    Layers,
    Pencil,
    Percent,
    Plus,
    Receipt,
    ShoppingBag,
    SlidersHorizontal,
    Sparkles,
    Tag,
    Trash2,
    X,
} from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import ProductCategoryPicker from '@/components/backoffice/ProductCategoryPicker.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Pagination from '@/components/ui/Pagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import { date, dateTime, money, number } from '@/lib/format'
import type { Paginated } from '@/types'

interface PromotionRow {
    id: number
    name: string
    description: string | null
    trigger_type: string
    trigger_value: number
    reward_type: string
    reward_value: number
    free_qty: number
    max_discount: number | null
    max_rounds: number
    starts_at: string | null
    ends_at: string | null
    is_active: boolean
    sort_order: number
    trigger_products: number[]
    trigger_categories: number[]
    reward_products: number[]
    reward_categories: number[]
    condition_text: string
    reward_text: string
}

interface Option {
    value: string
    label: string
    unit: string | null
}

const props = defineProps<{
    promotions: Paginated<PromotionRow>
    products: { id: number; name: string; price: number | string; category_id: number | null }[]
    categories: { id: number; name: string }[]
    triggerOptions: Option[]
    rewardOptions: Option[]
}>()

/** 5 แบบที่ร้านเลือก — แต่ละแบบล็อกเงื่อนไขไว้ แล้วเปิดให้เลือกรางวัลได้เฉพาะที่เข้ากัน */
const PRESETS = [
    {
        id: 1,
        title: 'ลดราคาเมนูที่ร่วมรายการ',
        desc: 'ลดทันทีเมื่อมีเมนูที่กำหนดอยู่ในบิล ไม่มีขั้นต่ำในการซื้อ',
        badge: 'ลดตามเมนู',
        color: 'from-blue-500/10 to-indigo-500/5 text-blue-600 dark:text-blue-400 border-blue-200 dark:border-blue-900',
        activeBorder: 'border-blue-500 bg-blue-500/10 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300',
        icon: Tag,
        trigger: 'none',
        rewards: ['item_percent', 'item_amount', 'item_fixed_price'],
    },
    {
        id: 2,
        title: 'ซื้อครบ X ชิ้น แถมฟรีหรือลดราคา',
        desc: 'นับจำนวนชิ้นของเมนูที่ร่วมรายการ เช่น ซื้อ 1 แถม 1 หรือซื้อ 3 ชิ้นลด 20%',
        badge: 'ซื้อ X ชิ้น แถม/ลด',
        color: 'from-purple-500/10 to-pink-500/5 text-purple-600 dark:text-purple-400 border-purple-200 dark:border-purple-900',
        activeBorder: 'border-purple-500 bg-purple-500/10 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300',
        icon: Gift,
        trigger: 'qty',
        rewards: ['free_item', 'item_percent', 'item_amount', 'item_fixed_price'],
    },
    {
        id: 3,
        title: 'ซื้อครบ X บาท แถมฟรีหรือลดราคา',
        desc: 'นับยอดเงินของเมนูที่ร่วมรายการ เช่น ซื้อครบ 300 บาท แถมฟรี หรือลด 50 บาท',
        badge: 'ซื้อครบยอด แถม/ลด',
        color: 'from-emerald-500/10 to-teal-500/5 text-emerald-600 dark:text-emerald-400 border-emerald-200 dark:border-emerald-900',
        activeBorder: 'border-emerald-500 bg-emerald-500/10 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300',
        icon: ShoppingBag,
        trigger: 'amount',
        rewards: ['free_item', 'item_percent', 'item_amount', 'item_fixed_price'],
    },
    {
        id: 4,
        title: 'ซื้อครบ X ชิ้น ลดท้ายบิล',
        desc: 'ส่วนลดคิดจากยอดทั้งบิล ไม่ใช่เฉพาะเมนูที่ร่วมรายการ',
        badge: 'ลดท้ายบิลตามชิ้น',
        color: 'from-amber-500/10 to-orange-500/5 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-900',
        activeBorder: 'border-amber-500 bg-amber-500/10 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300',
        icon: Receipt,
        trigger: 'qty',
        rewards: ['bill_percent', 'bill_amount'],
    },
    {
        id: 5,
        title: 'ซื้อครบ X บาท ลดท้ายบิล',
        desc: 'ส่วนลดคิดจากยอดทั้งบิล เมื่อมียอดสั่งซื้อถึงเกณฑ์ที่กำหนด',
        badge: 'ลดท้ายบิลตามยอด',
        color: 'from-rose-500/10 to-red-500/5 text-rose-600 dark:text-rose-400 border-rose-200 dark:border-rose-900',
        activeBorder: 'border-rose-500 bg-rose-500/10 dark:bg-rose-500/20 text-rose-700 dark:text-rose-300',
        icon: Coins,
        trigger: 'amount',
        rewards: ['bill_percent', 'bill_amount'],
    },
] as const

/** เทมเพลตโปรโมชั่นยอดนิยม กดเลือกแล้วตั้งค่าอัตโนมัติ */
const POPULAR_TEMPLATES = [
    {
        name: 'ซื้อ 1 แถม 1',
        desc: 'ซื้อเมนูร่วมรายการ 1 ชิ้น รับฟรีอีก 1 ชิ้น',
        tag: '1 แถม 1',
        icon: Gift,
        apply: () => {
            pickPreset(2)
            form.name = 'ซื้อ 1 แถม 1'
            form.description = 'สั่งซื้อ 1 ชิ้น แถมฟรีอีก 1 ชิ้นทันที'
            form.trigger_value = 1
            form.reward_type = 'free_item'
            form.free_qty = 1
            form.max_rounds = 3
        },
    },
    {
        name: 'ซื้อ 2 แถม 1',
        desc: 'ซื้อ 2 ชิ้น แถมฟรี 1 ชิ้น',
        tag: '2 แถม 1',
        icon: Gift,
        apply: () => {
            pickPreset(2)
            form.name = 'ซื้อ 2 แถม 1'
            form.description = 'สั่งซื้อ 2 ชิ้น รับฟรีอีก 1 ชิ้น'
            form.trigger_value = 2
            form.reward_type = 'free_item'
            form.free_qty = 1
            form.max_rounds = 2
        },
    },
    {
        name: 'ลดพิเศษ 10%',
        desc: 'ลดทันที 10% ทุกเมนูที่ร่วมรายการ',
        tag: 'ลด 10%',
        icon: Percent,
        apply: () => {
            pickPreset(1)
            form.name = 'ลดพิเศษ 10% เมนูโปรด'
            form.description = 'รับส่วนลด 10% ทันทีสำหรับเมนูที่ร่วมรายการ'
            form.reward_type = 'item_percent'
            form.reward_value = 10
        },
    },
    {
        name: 'ซื้อครบ 500 ลด 50.-',
        desc: 'ซื้อเมนูร่วมรายการครบ 500฿ ลด 50฿',
        tag: 'ลด 50฿',
        icon: ShoppingBag,
        apply: () => {
            pickPreset(3)
            form.name = 'ช้อปครบ 500 ลด 50 บาท'
            form.description = 'ซื้อเมนูร่วมรายการครบ 500 บาท รับส่วนลดทันที 50 บาท'
            form.trigger_value = 500
            form.reward_type = 'item_amount'
            form.reward_value = 50
        },
    },
    {
        name: 'บิลครบ 1,000 ลด 10%',
        desc: 'ยอดทั้งบิลครบ 1,000฿ รับส่วนลดท้ายบิล 10%',
        tag: 'ลดท้ายบิล',
        icon: Receipt,
        apply: () => {
            pickPreset(5)
            form.name = 'ยอดครบ 1,000 ลดท้ายบิล 10%'
            form.description = 'สั่งครบ 1,000 บาท รับส่วนลดท้ายบิลทันที 10%'
            form.trigger_value = 1000
            form.reward_type = 'bill_percent'
            form.reward_value = 10
        },
    },
]

const showModal = ref(false)
const editingId = ref<number | null>(null)
const preset = ref<number>(1)
const showTemplatePicker = ref(true)

const form = useForm({
    name: '',
    description: '',
    trigger_type: 'none',
    trigger_value: 0,
    reward_type: 'item_percent',
    reward_value: 0,
    free_qty: 1,
    max_discount: null as number | null,
    max_rounds: 1,
    starts_at: '',
    ends_at: '',
    is_active: true,
    sort_order: 0,
    trigger_products: [] as number[],
    trigger_categories: [] as number[],
    reward_products: [] as number[],
    reward_categories: [] as number[],
})

const current = computed(() => PRESETS.find((p) => p.id === preset.value) ?? PRESETS[0])

const rewardChoices = computed(() =>
    props.rewardOptions.filter((o) => (current.value.rewards as readonly string[]).includes(o.value)),
)

const isFreeItem = computed(() => form.reward_type === 'free_item')
const hasCondition = computed(() => form.trigger_type !== 'none')

const triggerUnit = computed(() => (form.trigger_type === 'qty' ? 'ชิ้น' : 'บาท'))
const rewardUnit = computed(() => props.rewardOptions.find((o) => o.value === form.reward_type)?.unit ?? '')

function pickPreset(id: number) {
    preset.value = id
    const found = PRESETS.find((p) => p.id === id) ?? PRESETS[0]
    form.trigger_type = found.trigger

    // รางวัลเดิมใช้กับแบบใหม่ไม่ได้ ก็เลื่อนไปตัวแรกที่ใช้ได้
    if (!(found.rewards as readonly string[]).includes(form.reward_type)) {
        form.reward_type = found.rewards[0]
    }
}

/** ปรับค่าด่วนของ Trigger Value */
function setTriggerQuickValue(val: number) {
    form.trigger_value = val
}

/** ปรับค่าด่วนของ Reward Value หรือ Free Qty */
function setRewardQuickValue(val: number) {
    if (isFreeItem.value) {
        form.free_qty = val
    } else {
        form.reward_value = val
    }
}

/** ตั้งค่าลัดช่วงเวลาโปรโมชั่น */
function setDatePreset(type: 'always' | '7days' | '30days' | 'monthEnd') {
    if (type === 'always') {
        form.starts_at = ''
        form.ends_at = ''
        return
    }

    const now = new Date()
    const pad = (n: number) => String(n).padStart(2, '0')
    const formatDt = (d: Date) =>
        `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`

    form.starts_at = formatDt(now)

    if (type === '7days') {
        const end = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000)
        form.ends_at = formatDt(end)
    } else if (type === '30days') {
        const end = new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000)
        form.ends_at = formatDt(end)
    } else if (type === 'monthEnd') {
        const end = new Date(now.getFullYear(), now.getMonth() + 1, 0, 23, 59, 59)
        form.ends_at = formatDt(end)
    }
}

/** แบบที่ใช้อยู่ของโปรที่บันทึกไว้แล้ว — ย้อนจากสองแกนกลับเป็นการ์ด */
function presetOf(row: PromotionRow): number {
    if (row.trigger_type === 'none') return 1

    const billWide = row.reward_type === 'bill_percent' || row.reward_type === 'bill_amount'

    if (row.trigger_type === 'qty') return billWide ? 4 : 2

    return billWide ? 5 : 3
}

function openCreate() {
    form.reset()
    form.clearErrors()
    editingId.value = null
    preset.value = 1
    showTemplatePicker.value = true
    pickPreset(1)
    showModal.value = true
}

function openEdit(row: PromotionRow) {
    form.clearErrors()
    editingId.value = row.id
    preset.value = presetOf(row)
    showTemplatePicker.value = false

    form.name = row.name
    form.description = row.description ?? ''
    form.trigger_type = row.trigger_type
    form.trigger_value = row.trigger_value
    form.reward_type = row.reward_type
    form.reward_value = row.reward_value
    form.free_qty = row.free_qty
    form.max_discount = row.max_discount
    form.max_rounds = row.max_rounds
    form.starts_at = row.starts_at ?? ''
    form.ends_at = row.ends_at ?? ''
    form.is_active = row.is_active
    form.sort_order = row.sort_order
    form.trigger_products = [...row.trigger_products]
    form.trigger_categories = [...row.trigger_categories]
    form.reward_products = [...row.reward_products]
    form.reward_categories = [...row.reward_categories]

    showModal.value = true
}

function submit() {
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false
            form.reset()
        },
    }

    if (editingId.value !== null) {
        form.put(`/backoffice/promotions/${editingId.value}`, done)
    } else {
        form.post('/backoffice/promotions', done)
    }
}

const confirmingDelete = ref<PromotionRow | null>(null)

function destroy() {
    const row = confirmingDelete.value
    if (!row) return

    router.delete(`/backoffice/promotions/${row.id}`, {
        preserveScroll: true,
        onFinish: () => (confirmingDelete.value = null),
    })
}

/** สรุปข้อความโปรโมชั่นแบบสั้น */
const previewText = computed(() => {
    const parts: string[] = []

    if (form.trigger_type === 'qty') parts.push(`ซื้อครบ ${form.trigger_value || 0} ชิ้น`)
    else if (form.trigger_type === 'amount') parts.push(`ซื้อครบ ${money(form.trigger_value || 0)} บาท`)
    else parts.push('มีเมนูที่ร่วมรายการอยู่ในบิล')

    if (isFreeItem.value) parts.push(`แถมฟรี ${form.free_qty} ชิ้น`)
    else if (form.reward_type === 'item_percent') parts.push(`ลดราคา ${form.reward_value || 0}%`)
    else if (form.reward_type === 'item_amount') parts.push(`ลดราคา ${money(form.reward_value || 0)} บาท`)
    else if (form.reward_type === 'item_fixed_price') parts.push(`เหลือชิ้นละ ${money(form.reward_value || 0)} บาท`)
    else if (form.reward_type === 'bill_percent') parts.push(`ลดท้ายบิล ${form.reward_value || 0}%`)
    else parts.push(`ลดท้ายบิล ${money(form.reward_value || 0)} บาท`)

    if (hasCondition.value && form.max_rounds > 1) parts.push(`สูงสุด ${form.max_rounds} รอบ/บิล`)
    if (form.max_discount) parts.push(`ลดไม่เกิน ${money(form.max_discount)}฿`)

    return parts.join(' · ')
})

/** สรุปข้อความขอบเขตเมนู */
const triggerScopeText = computed(() => {
    const n = form.trigger_categories.length
    const m = form.trigger_products.length
    if (!n && !m) return 'ทุกเมนูในร้าน'
    const bits: string[] = []
    if (n) bits.push(`${n} หมวดหมู่`)
    if (m) bits.push(`${m} เมนูเฉพาะ`)
    return bits.join(' + ')
})

/** สรุปข้อความของแถม */
const rewardScopeText = computed(() => {
    if (!isFreeItem.value) return null
    const n = form.reward_categories.length
    const m = form.reward_products.length
    if (!n && !m) return 'ยังไม่ได้เลือก (ต้องเลือกอย่างน้อย 1 อย่าง)'
    const bits: string[] = []
    if (n) bits.push(`${n} หมวดหมู่`)
    if (m) bits.push(`${m} เมนู`)
    return bits.join(' + ')
})

/** จำลองการคำนวณบิลจริงเพื่อให้เห็นภาพชัดเจน */
const simulation = computed(() => {
    if (preset.value === 1) {
        // Preset 1: Menu discount (no minimum)
        if (form.reward_type === 'item_percent') {
            const p = form.reward_value || 10
            const samplePrice = 100
            const discount = (samplePrice * p) / 100
            const finalPrice = Math.max(0, samplePrice - discount)
            return {
                title: 'ตัวอย่างการคิดส่วนลด (สมมติสั่งเมนู 100฿)',
                steps: [
                    `สั่งเมนูที่ร่วมรายการ 1 ชิ้น มูลค่า ${money(samplePrice)} ฿`,
                    `รับส่วนลด ${p}% ทันที (-${money(discount)} ฿)`,
                ],
                total: `ลูกค้าจ่ายเพียง ${money(finalPrice)} ฿ (ประหยัด ${money(discount)} ฿)`,
            }
        }
        if (form.reward_type === 'item_amount') {
            const discount = form.reward_value || 20
            const samplePrice = 100
            const finalPrice = Math.max(0, samplePrice - discount)
            return {
                title: 'ตัวอย่างการคิดส่วนลด (สมมติสั่งเมนู 100฿)',
                steps: [
                    `สั่งเมนูที่ร่วมรายการ 1 ชิ้น มูลค่า ${money(samplePrice)} ฿`,
                    `รับส่วนลดทันที (-${money(discount)} ฿)`,
                ],
                total: `ลูกค้าจ่ายเพียง ${money(finalPrice)} ฿ (ประหยัด ${money(discount)} ฿)`,
            }
        }
        if (form.reward_type === 'item_fixed_price') {
            const fixed = form.reward_value || 49
            const samplePrice = 80
            return {
                title: 'ตัวอย่างการคิดราคาพิเศษ (จากปกติ 80฿)',
                steps: [
                    `สั่งเมนูที่ร่วมรายการราคาปกติ 80.00 ฿`,
                    `ปรับราคาพิเศษเป็นชิ้นละ ${money(fixed)} ฿`,
                ],
                total: `ลูกค้าจ่ายเพียง ${money(fixed)} ฿ ต่อชิ้น`,
            }
        }
    }

    if (preset.value === 2) {
        // Preset 2: Buy X items get free / discount
        const trigQty = form.trigger_value || 1
        if (form.reward_type === 'free_item') {
            const freeQ = form.free_qty || 1
            const totalQty = trigQty + freeQ
            return {
                title: `ตัวอย่างโปร ซื้อ ${trigQty} แถม ${freeQ}`,
                steps: [
                    `ลูกค้าหยิบเมนูร่วมรายการรวม ${totalQty} ชิ้น (สมมติชิ้นละ 50 ฿)`,
                    `คิดเงินเฉพาะ ${trigQty} ชิ้นแรก = ${money(trigQty * 50)} ฿`,
                    `แถมฟรี ${freeQ} ชิ้น = ฟรี ${money(freeQ * 50)} ฿`,
                ],
                total: `จ่าย ${money(trigQty * 50)} ฿ ได้สินค้า ${totalQty} ชิ้น (ประหยัด ${money(freeQ * 50)} ฿)`,
            }
        }
        if (form.reward_type === 'item_percent') {
            const p = form.reward_value || 10
            return {
                title: `ตัวอย่างโปร ซื้อครบ ${trigQty} ชิ้น ลด ${p}%`,
                steps: [
                    `สั่งเมนูร่วมรายการครบ ${trigQty} ชิ้น (สมมติรวม ${money(trigQty * 60)} ฿)`,
                    `รับส่วนลด ${p}% = -${money(((trigQty * 60) * p) / 100)} ฿`,
                ],
                total: `จ่ายสุทธิ ${money((trigQty * 60) * (1 - p / 100))} ฿`,
            }
        }
        if (form.reward_type === 'item_amount') {
            const d = form.reward_value || 20
            return {
                title: `ตัวอย่างโปร ซื้อครบ ${trigQty} ชิ้น ลด ${money(d)} ฿`,
                steps: [
                    `สั่งเมนูร่วมรายการครบ ${trigQty} ชิ้น (สมมติรวม ${money(trigQty * 60)} ฿)`,
                    `รับส่วนลดทันที -${money(d)} ฿`,
                ],
                total: `จ่ายสุทธิ ${money(Math.max(0, trigQty * 60 - d))} ฿`,
            }
        }
    }

    if (preset.value === 3) {
        // Preset 3: Buy X baht get free / discount
        const trigAmt = form.trigger_value || 300
        if (form.reward_type === 'free_item') {
            const freeQ = form.free_qty || 1
            return {
                title: `ตัวอย่างโปร ซื้อครบ ${money(trigAmt)} ฿ รับของแถม`,
                steps: [
                    `ลูกค้ามียอดสั่งเมนูร่วมรายการครบ ${money(trigAmt)} ฿`,
                    `รับฟรีเมนูของแถม ${freeQ} ชิ้นทันที`,
                ],
                total: `จ่าย ${money(trigAmt)} ฿ + รับของแถมฟรี ${freeQ} ชิ้น`,
            }
        }
        if (form.reward_type === 'item_percent') {
            const p = form.reward_value || 10
            const discount = (trigAmt * p) / 100
            return {
                title: `ตัวอย่างโปร ซื้อครบ ${money(trigAmt)} ฿ ลด ${p}%`,
                steps: [
                    `ยอดซื้อเมนูร่วมรายการ ${money(trigAmt)} ฿`,
                    `รับส่วนลด ${p}% = -${money(discount)} ฿`,
                ],
                total: `จ่ายสุทธิ ${money(trigAmt - discount)} ฿`,
            }
        }
        if (form.reward_type === 'item_amount') {
            const d = form.reward_value || 50
            return {
                title: `ตัวอย่างโปร ซื้อครบ ${money(trigAmt)} ฿ ลด ${money(d)} ฿`,
                steps: [
                    `ยอดซื้อเมนูร่วมรายการ ${money(trigAmt)} ฿`,
                    `รับส่วนลดทันที -${money(d)} ฿`,
                ],
                total: `จ่ายสุทธิ ${money(Math.max(0, trigAmt - d))} ฿`,
            }
        }
    }

    if (preset.value === 4) {
        // Preset 4: Buy X items get bill discount
        const trigQty = form.trigger_value || 3
        if (form.reward_type === 'bill_percent') {
            const p = form.reward_value || 10
            const sampleBill = 500
            const discount = (sampleBill * p) / 100
            return {
                title: `ตัวอย่างโปร ซื้อครบ ${trigQty} ชิ้น ลดท้ายบิล ${p}%`,
                steps: [
                    `สั่งเมนูร่วมรายการครบ ${trigQty} ชิ้น (ยอดทั้งบิล ${money(sampleBill)} ฿)`,
                    `รับส่วนลดท้ายบิล ${p}% = -${money(discount)} ฿`,
                ],
                total: `ยอดชำระสุทธิทั้งบิล ${money(sampleBill - discount)} ฿`,
            }
        }
        if (form.reward_type === 'bill_amount') {
            const d = form.reward_value || 50
            const sampleBill = 500
            return {
                title: `ตัวอย่างโปร ซื้อครบ ${trigQty} ชิ้น ลดท้ายบิล ${money(d)} ฿`,
                steps: [
                    `สั่งเมนูร่วมรายการครบ ${trigQty} ชิ้น (ยอดทั้งบิล ${money(sampleBill)} ฿)`,
                    `รับส่วนลดท้ายบิลทันที -${money(d)} ฿`,
                ],
                total: `ยอดชำระสุทธิทั้งบิล ${money(Math.max(0, sampleBill - d))} ฿`,
            }
        }
    }

    if (preset.value === 5) {
        // Preset 5: Buy X baht get bill discount
        const trigAmt = form.trigger_value || 1000
        if (form.reward_type === 'bill_percent') {
            const p = form.reward_value || 10
            const discount = (trigAmt * p) / 100
            return {
                title: `ตัวอย่างโปร ซื้อครบ ${money(trigAmt)} ฿ ลดท้ายบิล ${p}%`,
                steps: [
                    `ยอดรวมทั้งบิลครบ ${money(trigAmt)} ฿`,
                    `รับส่วนลดท้ายบิล ${p}% = -${money(discount)} ฿`,
                ],
                total: `ยอดชำระสุทธิทั้งบิล ${money(trigAmt - discount)} ฿`,
            }
        }
        if (form.reward_type === 'bill_amount') {
            const d = form.reward_value || 100
            return {
                title: `ตัวอย่างโปร ซื้อครบ ${money(trigAmt)} ฿ ลดท้ายบิล ${money(d)} ฿`,
                steps: [
                    `ยอดรวมทั้งบิลครบ ${money(trigAmt)} ฿`,
                    `รับส่วนลดท้ายบิลทันที -${money(d)} ฿`,
                ],
                total: `ยอดชำระสุทธิทั้งบิล ${money(Math.max(0, trigAmt - d))} ฿`,
            }
        }
    }

    return null
})

function scopeText(row: PromotionRow): string {
    const n = row.trigger_categories.length
    const m = row.trigger_products.length

    if (!n && !m) return 'ทั้งร้าน'

    const bits: string[] = []
    if (n) bits.push(`${n} หมวด`)
    if (m) bits.push(`${m} เมนู`)

    return bits.join(' + ')
}
</script>

<template>
    <Head title="โปรโมชั่น" />

    <BackOfficeLayout title="โปรโมชั่น">
        <SectionCard title="โปรโมชั่นของสาขานี้" content-class="p-0">
            <template #actions>
                <Button variant="brand" size="sm" @click="openCreate">
                    <Plus class="size-4" />
                    สร้างโปรโมชั่น
                </Button>
            </template>

            <div class="flex items-center gap-2 border-b bg-muted/40 px-4 py-2.5 text-xs text-muted-foreground">
                <Info class="size-4 shrink-0 text-[var(--series-1)]" />
                <span>บิลหนึ่งใบใช้โปรได้ใบเดียว — ระบบจะเลือกโปรโมชั่นที่ลูกค้าได้ประโยชน์สูงสุดให้อัตโนมัติ</span>
            </div>

            <DataTable v-if="promotions.data.length">
                <thead>
                    <tr>
                        <th>ชื่อโปรโมชั่น</th>
                        <th>เงื่อนไข</th>
                        <th>สิทธิประโยชน์ / รางวัล</th>
                        <th>เมนูที่ร่วมรายการ</th>
                        <th>ช่วงเวลา</th>
                        <th>สถานะ</th>
                        <th class="text-right">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in promotions.data" :key="p.id" class="transition-colors hover:bg-muted/30">
                        <td class="font-medium">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold">{{ p.name }}</span>
                            </div>
                            <span v-if="p.description" class="mt-0.5 block text-xs font-normal text-muted-foreground line-clamp-1">
                                {{ p.description }}
                            </span>
                        </td>
                        <td>
                            <span class="inline-flex items-center gap-1 rounded-md bg-secondary px-2 py-0.5 text-xs font-medium">
                                {{ p.condition_text }}
                            </span>
                        </td>
                        <td>
                            <div class="font-medium text-foreground">
                                {{ p.reward_text }}
                            </div>
                            <span class="block text-xs text-muted-foreground">
                                <template v-if="p.reward_type === 'free_item'">แถมฟรี {{ p.free_qty }} ชิ้น/รอบ</template>
                                <template v-else-if="p.reward_type.endsWith('percent')">ลด {{ p.reward_value }}%</template>
                                <template v-else-if="p.reward_type === 'item_fixed_price'">เหลือชิ้นละ {{ money(p.reward_value) }} ฿</template>
                                <template v-else>ลด {{ money(p.reward_value) }} ฿</template>
                            </span>
                        </td>
                        <td class="text-muted-foreground">
                            <span class="inline-flex items-center gap-1 text-xs">
                                <Layers class="size-3.5 text-muted-foreground/70" />
                                {{ scopeText(p) }}
                            </span>
                        </td>
                        <td class="text-xs text-muted-foreground">
                            <div v-if="!p.starts_at && !p.ends_at" class="flex items-center gap-1">
                                <Clock class="size-3.5" />
                                <span>ไม่จำกัดระยะเวลา</span>
                            </div>
                            <div v-else class="space-y-0.5">
                                <div>เริ่ม: {{ p.starts_at ? date(p.starts_at) : '-' }}</div>
                                <div>สิ้นสุด: {{ p.ends_at ? date(p.ends_at) : 'ไม่จำกัด' }}</div>
                            </div>
                        </td>
                        <td>
                            <Badge :variant="p.is_active ? 'success' : 'secondary'">
                                <span class="mr-1 inline-block size-1.5 rounded-full" :class="p.is_active ? 'bg-emerald-500' : 'bg-muted-foreground'" />
                                {{ p.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}
                            </Badge>
                        </td>
                        <td>
                            <div class="flex justify-end gap-1">
                                <Button variant="ghost" size="sm" class="h-8 w-8 p-0" title="แก้ไข" @click="openEdit(p)">
                                    <Pencil class="size-4" />
                                </Button>
                                <Button variant="ghost" size="sm" class="h-8 w-8 p-0 hover:bg-destructive/10" title="ลบ" @click="confirmingDelete = p">
                                    <Trash2 class="size-4 text-[var(--status-critical)]" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else description="ยังไม่มีโปรโมชั่น กดปุ่ม 'สร้างโปรโมชั่น' ด้านบนเพื่อเริ่มต้น" />

            <Pagination :links="promotions.links" :total="promotions.total" />
        </SectionCard>

        <!-- ══ MODAL สร้าง / แก้ไขโปรโมชั่น ══ -->
        <Modal
            v-model:open="showModal"
            :title="editingId ? 'แก้ไขโปรโมชั่น' : 'สร้างโปรโมชั่นใหม่'"
            :description="editingId ? 'ปรับปรุงเงื่อนไข ส่วนลด หรือระยะเวลาของโปรโมชั่น' : 'เลือกเทมเพลตหรือกำหนดรูปแบบโปรโมชั่นตามความต้องการของร้าน'"
            class="max-h-[92dvh] max-w-5xl overflow-y-auto"
        >
            <form class="space-y-6 pt-1" @submit.prevent="submit">
                <!-- ── 1. Quick Templates (เฉพาะตอนสร้างใหม่) ── -->
                <div v-if="!editingId && showTemplatePicker" class="rounded-xl border border-primary/20 bg-gradient-to-br from-primary/5 via-accent/30 to-background p-4 shadow-sm">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="flex size-7 items-center justify-center rounded-lg bg-[var(--series-1)] text-white shadow-xs">
                                <Sparkles class="size-4" />
                            </span>
                            <div>
                                <h4 class="text-sm font-semibold text-foreground">เทมเพลตโปรโมชั่นยอดนิยม</h4>
                                <p class="text-xs text-muted-foreground">คลิกเพื่อเลือกรูปแบบสำเร็จรูปและปรับแต่งค่าได้ทันที</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="text-xs text-muted-foreground hover:text-foreground"
                            @click="showTemplatePicker = false"
                        >
                            ซ่อนเทมเพลต
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-5">
                        <button
                            v-for="tpl in POPULAR_TEMPLATES"
                            :key="tpl.name"
                            type="button"
                            class="group relative flex flex-col items-start justify-between rounded-lg border bg-card p-2.5 text-left transition-all hover:border-[var(--series-1)] hover:bg-[var(--series-1)]/5 hover:shadow-xs active:scale-[0.98]"
                            @click="tpl.apply()"
                        >
                            <div class="mb-2 flex w-full items-center justify-between">
                                <span class="flex size-6 items-center justify-center rounded-md bg-secondary text-primary group-hover:bg-[var(--series-1)] group-hover:text-white transition-colors">
                                    <component :is="tpl.icon" class="size-3.5" />
                                </span>
                                <span class="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold text-primary">
                                    {{ tpl.tag }}
                                </span>
                            </div>
                            <span class="text-xs font-semibold text-foreground group-hover:text-[var(--series-1)]">{{ tpl.name }}</span>
                            <span class="mt-0.5 text-[11px] text-muted-foreground line-clamp-1">{{ tpl.desc }}</span>
                        </button>
                    </div>
                </div>

                <!-- ── Grid แบ่ง 2 คอลัมน์: Form ด้านซ้าย (7 cols) + Live Preview ด้านขวา (5 cols) ── -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-12 items-start">
                    <!-- ══ คอลัมน์ซ้าย: ฟอร์มกรอกข้อมูล ══ -->
                    <div class="space-y-6 lg:col-span-7">
                        <!-- ส่วนที่ 1: ข้อมูลพื้นฐานและชื่อโปรโมชั่น -->
                        <div class="rounded-xl border bg-card p-4 shadow-xs space-y-4">
                            <div class="flex items-center gap-2 border-b pb-2.5">
                                <Tag class="size-4 text-[var(--series-1)]" />
                                <h3 class="text-sm font-semibold text-foreground">1. ข้อมูลพื้นฐานโปรโมชั่น</h3>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="space-y-1 sm:col-span-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="pname" class="font-medium text-foreground">ชื่อโปรโมชั่น <span class="text-destructive">*</span></Label>
                                        <span class="text-[11px] text-muted-foreground">เช่น ซื้อ 1 แถม 1 ชานมไต้หวัน</span>
                                    </div>
                                    <Input
                                        id="pname"
                                        v-model="form.name"
                                        required
                                        placeholder="ระบุชื่อโปรโมชั่นที่สื่อความหมายชัดเจน"
                                        class="font-medium"
                                    />
                                    <p v-if="form.errors.name" class="text-xs text-[var(--status-critical)]">{{ form.errors.name }}</p>
                                </div>

                                <div class="space-y-1 sm:col-span-2">
                                    <Label for="pdesc" class="font-medium text-foreground">คำอธิบายสำหรับลูกค้า (สิทธิ์/เงื่อนไข)</Label>
                                    <Input
                                        id="pdesc"
                                        v-model="form.description"
                                        placeholder="เช่น เมื่อซื้อชาไทยหรือชาเขียว 1 แก้ว รับฟรีชามะลิ 1 แก้ว"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- ส่วนที่ 2: รูปแบบโปรโมชั่น (5 Presets) -->
                        <div class="rounded-xl border bg-card p-4 shadow-xs space-y-3">
                            <div class="flex items-center justify-between border-b pb-2.5">
                                <div class="flex items-center gap-2">
                                    <BadgePercent class="size-4 text-[var(--series-1)]" />
                                    <h3 class="text-sm font-semibold text-foreground">2. เลือกรูปแบบโปรโมชั่น</h3>
                                </div>
                                <span class="text-xs text-muted-foreground">เลือก 1 รูปแบบ</span>
                            </div>

                            <div class="grid gap-2.5">
                                <button
                                    v-for="ps in PRESETS"
                                    :key="ps.id"
                                    type="button"
                                    class="group relative flex items-start gap-3 rounded-xl border-2 p-3 text-left transition-all hover:shadow-xs"
                                    :class="
                                        preset === ps.id
                                            ? ps.activeBorder + ' shadow-xs ring-1 ring-primary/10'
                                            : 'border-border bg-card hover:border-primary/40 hover:bg-accent/40'
                                    "
                                    @click="pickPreset(ps.id)"
                                >
                                    <div
                                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary transition-colors"
                                        :class="preset === ps.id ? 'bg-primary text-primary-foreground' : 'text-muted-foreground group-hover:text-foreground'"
                                    >
                                        <component :is="ps.icon" class="size-5" />
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-sm font-semibold">{{ ps.title }}</span>
                                            <span
                                                class="shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-medium"
                                                :class="ps.color"
                                            >
                                                {{ ps.badge }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground leading-relaxed">{{ ps.desc }}</p>
                                    </div>

                                    <div v-if="preset === ps.id" class="absolute right-2 top-2 text-primary">
                                        <CheckCircle2 class="size-4 fill-primary text-primary-foreground" />
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- ส่วนที่ 3: กำหนดเงื่อนไข & ของรางวัล -->
                        <div class="rounded-xl border bg-card p-4 shadow-xs space-y-4">
                            <div class="flex items-center gap-2 border-b pb-2.5">
                                <Gift class="size-4 text-[var(--series-1)]" />
                                <h3 class="text-sm font-semibold text-foreground">3. เงื่อนไขและของรางวัล / ส่วนลด</h3>
                            </div>

                            <!-- เงื่อนไขขั้นต่ำ (ถ้ามี) -->
                            <div v-if="hasCondition" class="space-y-2 rounded-lg bg-muted/30 p-3 border">
                                <div class="flex items-center justify-between">
                                    <Label for="ptrigger" class="text-xs font-semibold text-foreground">
                                        เงื่อนไขการซื้อขั้นต่ำ ({{ triggerUnit }}) <span class="text-destructive">*</span>
                                    </Label>
                                    <span class="text-[11px] text-muted-foreground">
                                        {{ form.trigger_type === 'qty' ? 'นับรวมชิ้นของเมนูที่ร่วมรายการ' : 'ยอดเงินรวมของเมนูที่ร่วมรายการ' }}
                                    </span>
                                </div>

                                <div class="flex gap-2">
                                    <div class="relative flex-1">
                                        <Input
                                            id="ptrigger"
                                            v-model="form.trigger_value"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            required
                                            class="pr-12 font-semibold tabular"
                                        />
                                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-muted-foreground">
                                            {{ triggerUnit }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Quick Chips สำหรับ Trigger -->
                                <div class="flex flex-wrap items-center gap-1.5 pt-1">
                                    <span class="text-[11px] text-muted-foreground">ลัด:</span>
                                    <template v-if="form.trigger_type === 'qty'">
                                        <button
                                            v-for="q in [1, 2, 3, 4, 5]"
                                            :key="q"
                                            type="button"
                                            class="rounded-md border bg-card px-2 py-0.5 text-xs font-medium hover:bg-accent transition-colors"
                                            :class="form.trigger_value === q ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground'"
                                            @click="setTriggerQuickValue(q)"
                                        >
                                            {{ q }} ชิ้น
                                        </button>
                                    </template>
                                    <template v-else>
                                        <button
                                            v-for="amt in [100, 200, 300, 500, 1000]"
                                            :key="amt"
                                            type="button"
                                            class="rounded-md border bg-card px-2 py-0.5 text-xs font-medium hover:bg-accent transition-colors"
                                            :class="form.trigger_value === amt ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground'"
                                            @click="setTriggerQuickValue(amt)"
                                        >
                                            {{ amt }} ฿
                                        </button>
                                    </template>
                                </div>

                                <p v-if="form.errors.trigger_value" class="text-xs text-[var(--status-critical)]">
                                    {{ form.errors.trigger_value }}
                                </p>
                            </div>

                            <!-- รางวัลและส่วนลด -->
                            <div class="space-y-3">
                                <Label class="text-xs font-semibold text-foreground">สิ่งที่ลูกค้าได้รับเมื่อเข้าเงื่อนไข</Label>

                                <!-- Visual Segmented Cards สำหรับ Reward Choices -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <button
                                        v-for="o in rewardChoices"
                                        :key="o.value"
                                        type="button"
                                        class="flex items-center gap-2.5 rounded-lg border p-2.5 text-left text-xs transition-all hover:bg-accent"
                                        :class="
                                            form.reward_type === o.value
                                                ? 'border-primary bg-primary/10 font-semibold text-primary shadow-2xs'
                                                : 'border-border text-muted-foreground hover:text-foreground'
                                        "
                                        @click="form.reward_type = o.value"
                                    >
                                        <span class="flex size-6 shrink-0 items-center justify-center rounded-md bg-secondary text-primary">
                                            <Gift v-if="o.value === 'free_item'" class="size-3.5" />
                                            <Percent v-else-if="o.value.endsWith('percent')" class="size-3.5" />
                                            <Coins v-else class="size-3.5" />
                                        </span>
                                        <span class="min-w-0 flex-1 truncate">{{ o.label }}</span>
                                    </button>
                                </div>
                                <p v-if="form.errors.reward_type" class="text-xs text-[var(--status-critical)]">{{ form.errors.reward_type }}</p>

                                <!-- Input กรอกมูลค่า หรือ จำนวนของแถม -->
                                <div v-if="isFreeItem" class="space-y-2 rounded-lg bg-purple-500/5 p-3 border border-purple-500/20">
                                    <div class="flex items-center justify-between">
                                        <Label for="pfreeqty" class="text-xs font-semibold text-purple-900 dark:text-purple-300">
                                            จำนวนชิ้นที่แถมฟรีต่อรอบ (ชิ้น)
                                        </Label>
                                        <span class="text-[11px] text-muted-foreground">เช่น ซื้อ 1 แถม 1 -> ใส่ 1 ชิ้น</span>
                                    </div>
                                    <div class="flex gap-2 items-center">
                                        <Input
                                            id="pfreeqty"
                                            v-model="form.free_qty"
                                            type="number"
                                            min="1"
                                            max="20"
                                            class="w-32 font-semibold tabular"
                                        />
                                        <div class="flex gap-1.5">
                                            <button
                                                v-for="n in [1, 2, 3, 5]"
                                                :key="n"
                                                type="button"
                                                class="rounded-md border bg-card px-2.5 py-1 text-xs font-medium hover:bg-accent"
                                                :class="form.free_qty === n ? 'border-purple-500 bg-purple-500/10 text-purple-700 dark:text-purple-300' : 'text-muted-foreground'"
                                                @click="setRewardQuickValue(n)"
                                            >
                                                แถม {{ n }} ชิ้น
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div v-else class="space-y-2 rounded-lg bg-blue-500/5 p-3 border border-blue-500/20">
                                    <div class="flex items-center justify-between">
                                        <Label for="pvalue" class="text-xs font-semibold text-blue-900 dark:text-blue-300">
                                            มูลค่าส่วนลด ({{ rewardUnit }}) <span class="text-destructive">*</span>
                                        </Label>
                                        <span class="text-[11px] text-muted-foreground">
                                            {{ form.reward_type.endsWith('percent') ? 'ระบุเปอร์เซ็นต์ส่วนลด (1-100%)' : 'ระบุจำนวนเงินบาท' }}
                                        </span>
                                    </div>

                                    <div class="relative">
                                        <Input
                                            id="pvalue"
                                            v-model="form.reward_value"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            required
                                            class="pr-12 font-semibold tabular"
                                        />
                                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-muted-foreground">
                                            {{ rewardUnit }}
                                        </span>
                                    </div>

                                    <!-- Quick Chips สำหรับมูลค่า -->
                                    <div class="flex flex-wrap items-center gap-1.5 pt-1">
                                        <span class="text-[11px] text-muted-foreground">ลัด:</span>
                                        <template v-if="form.reward_type.endsWith('percent')">
                                            <button
                                                v-for="p in [5, 10, 15, 20, 30, 50]"
                                                :key="p"
                                                type="button"
                                                class="rounded-md border bg-card px-2 py-0.5 text-xs font-medium hover:bg-accent"
                                                :class="form.reward_value === p ? 'border-blue-500 bg-blue-500/10 text-blue-700 dark:text-blue-300' : 'text-muted-foreground'"
                                                @click="setRewardQuickValue(p)"
                                            >
                                                {{ p }}%
                                            </button>
                                        </template>
                                        <template v-else-if="form.reward_type === 'item_fixed_price'">
                                            <button
                                                v-for="pr in [19, 29, 39, 49, 59, 99]"
                                                :key="pr"
                                                type="button"
                                                class="rounded-md border bg-card px-2 py-0.5 text-xs font-medium hover:bg-accent"
                                                :class="form.reward_value === pr ? 'border-blue-500 bg-blue-500/10 text-blue-700 dark:text-blue-300' : 'text-muted-foreground'"
                                                @click="setRewardQuickValue(pr)"
                                            >
                                                {{ pr }} ฿
                                            </button>
                                        </template>
                                        <template v-else>
                                            <button
                                                v-for="amt in [10, 20, 50, 100, 200]"
                                                :key="amt"
                                                type="button"
                                                class="rounded-md border bg-card px-2 py-0.5 text-xs font-medium hover:bg-accent"
                                                :class="form.reward_value === amt ? 'border-blue-500 bg-blue-500/10 text-blue-700 dark:text-blue-300' : 'text-muted-foreground'"
                                                @click="setRewardQuickValue(amt)"
                                            >
                                                {{ amt }} ฿
                                            </button>
                                        </template>
                                    </div>

                                    <p v-if="form.errors.reward_value" class="text-xs text-[var(--status-critical)]">
                                        {{ form.errors.reward_value }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- ส่วนที่ 4: ขอบเขตเมนูที่ร่วมรายการ -->
                        <div class="rounded-xl border bg-card p-4 shadow-xs space-y-4">
                            <div class="flex items-center justify-between border-b pb-2.5">
                                <div class="flex items-center gap-2">
                                    <Layers class="size-4 text-[var(--series-1)]" />
                                    <h3 class="text-sm font-semibold text-foreground">4. เมนูที่ร่วมรายการ</h3>
                                </div>
                                <span class="rounded bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                    {{ triggerScopeText }}
                                </span>
                            </div>

                            <div class="space-y-1.5">
                                <p class="text-xs text-muted-foreground">
                                    เลือกหมวดหมู่หรือรายการเมนูที่ต้องการให้นำมาคิดในโปรโมชั่นนี้ (หากไม่เลือกเลย = ทุกเมนูในร้านจะเข้าโปรโมชั่นนี้)
                                </p>
                                <ProductCategoryPicker
                                    v-model:selected-products="form.trigger_products"
                                    v-model:selected-categories="form.trigger_categories"
                                    :products="products"
                                    :categories="categories"
                                    empty-hint="ไม่เลือก = ทุกเมนูในร้านเข้าโปรโมชั่น"
                                />
                            </div>

                            <!-- เมนูของแถม (เฉพาะกรณี reward เป็น free_item) -->
                            <div v-if="isFreeItem" class="pt-3 border-t space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-1.5">
                                        <Gift class="size-4 text-purple-600" />
                                        <Label class="text-xs font-semibold text-purple-900 dark:text-purple-300">
                                            เมนูที่ให้ลูกค้าเลือกรับเป็นของแถม <span class="text-destructive">*</span>
                                        </Label>
                                    </div>
                                    <span class="rounded bg-purple-500/10 px-2 py-0.5 text-[11px] font-medium text-purple-700 dark:text-purple-300">
                                        {{ rewardScopeText }}
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    ลูกค้าสามารถเลือกรับได้ 1 อย่างจากรายการที่กำหนด (ระบบจะคำนวณเปรียบเทียบโปรที่ดีที่สุดด้วยรายการที่มูลค่าสูงสุด)
                                </p>
                                <ProductCategoryPicker
                                    v-model:selected-products="form.reward_products"
                                    v-model:selected-categories="form.reward_categories"
                                    :products="products"
                                    :categories="categories"
                                    empty-hint="ต้องเลือกอย่างน้อย 1 เมนูหรือหมวดหมู่ของแถม"
                                />
                                <p v-if="form.errors.reward_products" class="text-xs text-[var(--status-critical)]">
                                    {{ form.errors.reward_products }}
                                </p>
                            </div>
                        </div>

                        <!-- ส่วนที่ 5: ข้อกำหนดเพิ่มเติมและระยะเวลา -->
                        <div class="rounded-xl border bg-card p-4 shadow-xs space-y-4">
                            <div class="flex items-center gap-2 border-b pb-2.5">
                                <SlidersHorizontal class="size-4 text-[var(--series-1)]" />
                                <h3 class="text-sm font-semibold text-foreground">5. ข้อกำหนดและระยะเวลาโปรโมชั่น</h3>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="space-y-1">
                                    <Label for="pmax" class="text-xs font-medium text-foreground">ลดสูงสุดต่อบิล (บาท)</Label>
                                    <Input
                                        id="pmax"
                                        v-model="form.max_discount"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        placeholder="ไม่จำกัดเพดาน"
                                        class="tabular"
                                    />
                                    <p class="text-[11px] text-muted-foreground">เว้นว่างไว้หากไม่ต้องการจำกัดเพดานส่วนลด</p>
                                </div>

                                <div v-if="hasCondition" class="space-y-1">
                                    <Label for="prounds" class="text-xs font-medium text-foreground">ใช้ซ้ำได้กี่รอบต่อบิล</Label>
                                    <Input
                                        id="prounds"
                                        v-model="form.max_rounds"
                                        type="number"
                                        min="1"
                                        max="50"
                                        class="tabular"
                                    />
                                    <p class="text-[11px] text-muted-foreground">เช่น ซื้อ 1 แถม 1 สั่ง 4 ชิ้น จะแถม 2 ชิ้น เมื่อตั้ง 2 รอบ</p>
                                </div>
                            </div>

                            <!-- ช่วงเวลาโปรโมชั่น -->
                            <div class="space-y-2 pt-2 border-t">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-1.5">
                                        <Calendar class="size-4 text-[var(--series-1)]" />
                                        <Label class="text-xs font-semibold text-foreground">กำหนดระยะเวลาการใช้งาน</Label>
                                    </div>

                                    <!-- Quick date presets -->
                                    <div class="flex flex-wrap gap-1">
                                        <button
                                            type="button"
                                            class="rounded border bg-card px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-accent hover:text-foreground transition-colors"
                                            @click="setDatePreset('always')"
                                        >
                                            ตลอดไป
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded border bg-card px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-accent hover:text-foreground transition-colors"
                                            @click="setDatePreset('7days')"
                                        >
                                            7 วัน
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded border bg-card px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-accent hover:text-foreground transition-colors"
                                            @click="setDatePreset('30days')"
                                        >
                                            30 วัน
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded border bg-card px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-accent hover:text-foreground transition-colors"
                                            @click="setDatePreset('monthEnd')"
                                        >
                                            สิ้นเดือนนี้
                                        </button>
                                    </div>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="space-y-1">
                                        <Label for="pstart" class="text-[11px] text-muted-foreground">วันและเวลาที่เริ่ม</Label>
                                        <Input id="pstart" v-model="form.starts_at" type="datetime-local" class="text-xs" />
                                    </div>
                                    <div class="space-y-1">
                                        <Label for="pend" class="text-[11px] text-muted-foreground">วันและเวลาที่สิ้นสุด</Label>
                                        <Input id="pend" v-model="form.ends_at" type="datetime-local" class="text-xs" />
                                        <p v-if="form.errors.ends_at" class="text-xs text-[var(--status-critical)]">
                                            {{ form.errors.ends_at }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- สวิตช์เปิดใช้งาน -->
                            <div class="flex items-center justify-between rounded-lg bg-secondary/50 p-3 border">
                                <div>
                                    <span class="text-xs font-semibold text-foreground">สถานะโปรโมชั่น</span>
                                    <p class="text-[11px] text-muted-foreground">
                                        {{ form.is_active ? 'เปิดใช้งานทันทีในระบบ POS หน้าร้าน' : 'ปิดการใช้งานชั่วคราว (จะไม่ถูกคำนวณในบิล)' }}
                                    </p>
                                </div>
                                <label class="relative inline-flex cursor-pointer items-center">
                                    <input v-model="form.is_active" type="checkbox" class="peer sr-only" />
                                    <div class="peer h-6 w-11 rounded-full bg-input transition-colors peer-checked:bg-[var(--series-1)] peer-focus-visible:ring-2 peer-focus-visible:ring-ring after:absolute after:left-[2px] after:top-[2px] after:size-5 after:rounded-full after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-full" />
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- ══ คอลัมน์ขวา: Live Interactive Preview & Simulation ══ -->
                    <div class="space-y-4 lg:sticky lg:top-4 lg:col-span-5">
                        <!-- บัตรโปรโมชั่น Live Ticket Preview -->
                        <div class="overflow-hidden rounded-2xl border-2 border-primary/20 bg-card shadow-md">
                            <!-- Ticket Header -->
                            <div class="bg-gradient-to-r from-[var(--series-1)] to-indigo-600 p-4 text-white">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-white/90">
                                        <Sparkles class="size-3.5" />
                                        <span>บัตรโปรโมชั่นจำลอง</span>
                                    </div>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        :class="form.is_active ? 'bg-emerald-400/30 text-emerald-100 border border-emerald-300/40' : 'bg-white/20 text-white/80'"
                                    >
                                        {{ form.is_active ? '● พร้อมใช้งาน' : '○ ปิดใช้งาน' }}
                                    </span>
                                </div>

                                <h4 class="mt-2 text-lg font-bold tracking-tight text-white leading-tight">
                                    {{ form.name || 'ตั้งชื่อโปรโมชั่น...' }}
                                </h4>
                                <p class="mt-0.5 text-xs text-white/80 line-clamp-2">
                                    {{ form.description || 'ยังไม่ได้ระบุคำอธิบายสำหรับลูกค้า' }}
                                </p>
                            </div>

                            <!-- Ticket Body & Rules -->
                            <div class="space-y-3 p-4 bg-card text-foreground">
                                <div class="grid grid-cols-2 gap-2 text-xs">
                                    <div class="rounded-lg bg-muted/50 p-2 border">
                                        <span class="block text-[10px] text-muted-foreground">เงื่อนไข</span>
                                        <span class="font-semibold text-foreground">
                                            {{ form.trigger_type === 'none' ? 'ไม่มีขั้นต่ำ' : `ซื้อครบ ${form.trigger_value || 0} ${triggerUnit}` }}
                                        </span>
                                    </div>
                                    <div class="rounded-lg bg-muted/50 p-2 border">
                                        <span class="block text-[10px] text-muted-foreground">สิทธิประโยชน์</span>
                                        <span class="font-semibold text-[var(--series-1)]">
                                            <template v-if="isFreeItem">แถมฟรี {{ form.free_qty }} ชิ้น</template>
                                            <template v-else-if="form.reward_type.endsWith('percent')">ลด {{ form.reward_value || 0 }}%</template>
                                            <template v-else-if="form.reward_type === 'item_fixed_price'">เหลือชิ้นละ {{ money(form.reward_value) }} ฿</template>
                                            <template v-else>ลด {{ money(form.reward_value) }} ฿</template>
                                        </span>
                                    </div>
                                </div>

                                <div class="space-y-1.5 text-xs text-muted-foreground">
                                    <div class="flex items-center gap-1.5">
                                        <Layers class="size-3.5 shrink-0 text-primary" />
                                        <span>ร่วมรายการ: <strong class="text-foreground">{{ triggerScopeText }}</strong></span>
                                    </div>
                                    <div v-if="isFreeItem" class="flex items-center gap-1.5">
                                        <Gift class="size-3.5 shrink-0 text-purple-500" />
                                        <span>เมนูของแถม: <strong class="text-foreground">{{ rewardScopeText }}</strong></span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <Calendar class="size-3.5 shrink-0 text-primary" />
                                        <span>
                                            ระยะเวลา:
                                            <strong class="text-foreground">
                                                {{ form.starts_at || form.ends_at ? `${form.starts_at ? date(form.starts_at) : 'เริ่มทันที'} - ${form.ends_at ? date(form.ends_at) : 'ไม่จำกัด'}` : 'ไม่จำกัดระยะเวลา' }}
                                            </strong>
                                        </span>
                                    </div>
                                    <div v-if="form.max_discount" class="flex items-center gap-1.5">
                                        <Info class="size-3.5 shrink-0 text-amber-500" />
                                        <span>ลดสูงสุดไม่เกิน: <strong class="text-foreground">{{ money(form.max_discount) }} ฿</strong></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Ticket Footer / Summary Bar -->
                            <div class="border-t bg-muted/40 px-4 py-2.5 text-xs font-medium text-muted-foreground">
                                <span class="text-foreground font-semibold">สรุป:</span> {{ previewText }}
                            </div>
                        </div>

                        <!-- กล่องจำลองการคำนวณจริง (Receipt Simulation) -->
                        <div v-if="simulation" class="rounded-xl border bg-gradient-to-br from-secondary/40 to-background p-4 shadow-2xs space-y-2.5">
                            <div class="flex items-center gap-2">
                                <Receipt class="size-4 text-emerald-600 dark:text-emerald-400" />
                                <h4 class="text-xs font-bold text-foreground uppercase tracking-wide">
                                    {{ simulation.title }}
                                </h4>
                            </div>

                            <div class="space-y-1.5 rounded-lg border bg-card p-3 text-xs shadow-2xs font-mono">
                                <div
                                    v-for="(step, idx) in simulation.steps"
                                    :key="idx"
                                    class="flex items-start gap-2 text-muted-foreground"
                                >
                                    <span class="text-primary font-bold">{{ idx + 1 }}.</span>
                                    <span>{{ step }}</span>
                                </div>
                                <div class="mt-2 border-t pt-2 font-sans font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                                    <CheckCircle2 class="size-4" />
                                    <span>{{ simulation.total }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- คำแนะนำการทำงาน -->
                        <div class="rounded-xl border border-dashed bg-muted/20 p-3.5 text-xs text-muted-foreground space-y-1.5">
                            <div class="flex items-center gap-1.5 font-semibold text-foreground">
                                <HelpCircle class="size-4 text-[var(--series-1)]" />
                                <span>ข้อแนะนำระบบโปรโมชั่น</span>
                            </div>
                            <ul class="list-disc pl-4 space-y-1 text-[11px] leading-relaxed">
                                <li>หากมีหลายโปรโมชั่นที่เข้าเงื่อนไขพร้อมกัน ระบบจะเลือกโปรโมชั่นที่ให้ส่วนลดสูงที่สุดให้ลูกค้าโดยอัตโนมัติ</li>
                                <li>เมนูที่ระบุเป็นของแถม ลูกค้าสามารถเลือกได้ตามความต้องการ ณ จุดขายหรือหน้าร้านสั่งเอง</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- ══ Action Bar ด้านล่าง ══ -->
                <div class="flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-xs text-muted-foreground truncate">
                        {{ editingId ? 'กำลังแก้ไขโปรโมชั่น ID: ' + editingId : 'พร้อมบันทึกโปรโมชั่นใหม่' }}
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button type="button" variant="outline" @click="showModal = false">
                            ยกเลิก
                        </Button>
                        <Button type="submit" variant="brand" :disabled="form.processing">
                            <Check class="size-4" />
                            {{ editingId ? 'บันทึกการแก้ไข' : 'สร้างโปรโมชั่น' }}
                        </Button>
                    </div>
                </div>
            </form>
        </Modal>

        <!-- ══ Modal ยืนยันลบ ══ -->
        <Modal :open="confirmingDelete !== null" title="ลบโปรโมชั่น" @update:open="confirmingDelete = null">
            <p class="text-sm">
                คุณแน่ใจหรือไม่ว่าต้องการลบโปรโมชั่น <span class="font-semibold text-foreground">"{{ confirmingDelete?.name }}"</span>
                <span class="mt-2 block text-xs text-muted-foreground">
                    การลบจะเป็นแบบ Soft Delete บิลย้อนหลังที่เคยใช้โปรโมชั่นนี้จะยังคงแสดงชื่อและส่วนลดได้ตามเดิม
                </span>
            </p>

            <div class="mt-5 flex justify-end gap-2">
                <Button type="button" variant="outline" @click="confirmingDelete = null">ยกเลิก</Button>
                <Button type="button" variant="destructive" @click="destroy">
                    <Trash2 class="size-4" />
                    ยืนยันการลบ
                </Button>
            </div>
        </Modal>
    </BackOfficeLayout>
</template>
