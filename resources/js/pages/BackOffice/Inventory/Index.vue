<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { PackagePlus, Pencil, Plus, Trash2 } from 'lucide-vue-next'
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
import { dateTime, money, number } from '@/lib/format'
import type { Option, Paginated } from '@/types'

interface UnitOption extends Option {
    group: string
    decimals: number
    suggested_purchase_unit: string | null
    suggested_purchase_factor: number
}

interface StockItemRow {
    id: number
    code: string | null
    name: string
    unit: string
    unit_label: string
    unit_decimals: number
    purchase_unit: string | null
    purchase_factor: number
    purchase_unit_label: string | null
    has_purchase_unit: boolean
    stock_qty: number
    cost_per_unit: number
    reorder_level: number
    stock_value: number
    /** ของกลาง — ทุกสาขาใช้ร่วมกัน */
    is_central: boolean
    /** สาขานี้เปิดใช้ของชิ้นนี้ไหม */
    used_here: boolean
    /** แก้ชื่อ/หน่วยของแม่แบบได้ไหม (ของกลางแก้ได้เฉพาะเจ้าของระบบ) */
    can_edit_master: boolean
    is_low: boolean
    is_active: boolean
    in_use: number
}

interface MovementRow {
    id: number
    stock_item_id: number
    stock_item: {
        id: number
        name: string
        unit: string
        unit_label: string
        unit_decimals: number
    } | null
    type: string
    type_label: string
    qty: number
    cost: number
    balance_after: number
    note: string | null
    business_date: string | null
    occurred_at: string | null
}

const props = defineProps<{
    stockItems: Paginated<StockItemRow>
    movements: MovementRow[]
    movementTypes: Option[]
    units: UnitOption[]
    canEditCentral: boolean
    filters: Record<string, any>
}>()

function movementBadgeVariant(type: string): 'default' | 'secondary' | 'outline' | 'danger' | 'success' | 'warning' {
    switch (type) {
        case 'purchase':
        case 'transfer_in':
            return 'success'
        case 'waste':
            return 'danger'
        case 'adjust':
            return 'warning'
        case 'usage':
        case 'transfer_out':
            return 'secondary'
        default:
            return 'default'
    }
}

/* ---------- บันทึกความเคลื่อนไหว ---------- */

const showModal = ref(false)

const form = useForm({
    stock_item_id: '' as number | string,
    type: 'purchase',
    qty: 0,
    use_purchase_unit: true,
    unit_cost: null as number | null,
    note: '',
})

/** วัตถุดิบที่กำลังเลือกในฟอร์มความเคลื่อนไหว */
const moveTarget = computed<StockItemRow | undefined>(() =>
    props.stockItems.data.find((i) => i.id === Number(form.stock_item_id)),
)

/** กรอกเป็นหน่วยซื้อได้เฉพาะตอนรับของ และของตัวนั้นตั้งหน่วยซื้อไว้ */
const canUsePurchaseUnit = computed(
    () => form.type === 'purchase' && Boolean(moveTarget.value?.has_purchase_unit),
)

/** จำนวนที่จะเข้าคลังจริงหลังแปลงหน่วย */
const convertedQty = computed(() => {
    if (!moveTarget.value) return 0
    const factor = canUsePurchaseUnit.value && form.use_purchase_unit ? moveTarget.value.purchase_factor : 1
    return Number(form.qty || 0) * factor
})

function submit() {
    form.post('/backoffice/inventory/move', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            showModal.value = false
        },
    })
}

/* ---------- เพิ่ม/แก้ไขวัตถุดิบ ---------- */

const showItem = ref(false)
const editing = ref<StockItemRow | null>(null)

const itemForm = useForm({
    code: '',
    name: '',
    unit: 'g',
    purchase_unit: '',
    purchase_factor: 1,
    cost_per_unit: 0,
    reorder_level: 0,
    opening_qty: 0,
    is_active: true,
    used_here: true,
    // สร้างเป็นของกลางไหม — เจ้าของระบบเท่านั้นที่ติ๊กได้
    is_central: false,
})

/** เปลี่ยนหน่วยฐานแล้วเติมหน่วยซื้อที่มักคู่กันให้ เช่น กรัม -> กก. ×1000 */
watch(
    () => itemForm.unit,
    (value, old) => {
        if (!old || editing.value) return

        const u = props.units.find((x) => x.value === value)
        if (!u) return

        itemForm.purchase_unit = u.suggested_purchase_unit ?? ''
        itemForm.purchase_factor = u.suggested_purchase_factor
    },
)

const formUnitLabel = computed(
    () => props.units.find((u) => u.value === itemForm.unit)?.label ?? '',
)

/** ของที่มีสูตรใช้อยู่ เปลี่ยนหน่วยฐานไม่ได้ ตัวเลขในสูตรจะเพี้ยน */
const unitLocked = computed(() => (editing.value?.in_use ?? 0) > 0)

function openCreate() {
    editing.value = null
    itemForm.reset()
    itemForm.clearErrors()
    showItem.value = true
}

function openEdit(row: StockItemRow) {
    editing.value = row
    itemForm.clearErrors()
    itemForm.defaults({
        code: row.code ?? '',
        name: row.name,
        unit: row.unit,
        purchase_unit: row.purchase_unit ?? '',
        purchase_factor: row.purchase_factor,
        cost_per_unit: row.cost_per_unit,
        reorder_level: row.reorder_level,
        opening_qty: 0,
        is_active: row.is_active,
        used_here: row.used_here,
        is_central: row.is_central,
    })
    itemForm.reset()
    showItem.value = true
}

function submitItem() {
    const done = { preserveScroll: true, onSuccess: () => (showItem.value = false) }

    if (editing.value) {
        itemForm.put(`/backoffice/inventory/items/${editing.value.id}`, done)
    } else {
        itemForm.post('/backoffice/inventory/items', done)
    }
}

function removeItem(row: StockItemRow) {
    router.delete(`/backoffice/inventory/items/${row.id}`, { preserveScroll: true })
}
</script>

<template>
    <Head title="สินค้าคงคลัง" />

    <BackOfficeLayout title="สินค้าคงคลัง">
        <div class="grid gap-4 xl:grid-cols-3">
            <SectionCard title="วัตถุดิบ" class="xl:col-span-2" content-class="p-0">
                <template #actions>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" @click="openCreate">
                            <Plus />
                            เพิ่มวัตถุดิบ
                        </Button>
                        <Button variant="brand" size="sm" @click="showModal = true">
                            <PackagePlus />
                            บันทึกสต๊อก
                        </Button>
                    </div>
                </template>

                <DataTable v-if="stockItems.data.length">
                    <thead>
                        <tr>
                            <th>วัตถุดิบ</th>
                            <th class="text-right">คงเหลือ</th>
                            <th class="text-right">จุดสั่งซื้อ</th>
                            <th class="text-right">ต้นทุน/หน่วย</th>
                            <th class="text-right">มูลค่าคงคลัง</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in stockItems.data" :key="i.id">
                            <td class="font-medium">
                                <span :class="!i.is_active && 'text-muted-foreground line-through'">{{ i.name }}</span>
                                <span v-if="i.code" class="ms-1.5 text-[11px] font-normal text-muted-foreground">
                                    {{ i.code }}
                                </span>
                                <span
                                    v-if="i.is_central"
                                    class="ms-1.5 rounded-full bg-muted px-1.5 py-0.5 text-[11px] font-normal text-muted-foreground"
                                    title="ของกลาง ทุกสาขาใช้ร่วมกัน — ยอดคงเหลือยังแยกของใครของมัน"
                                >
                                    ของกลาง
                                </span>
                                <p v-if="i.purchase_unit_label" class="text-[11px] font-normal text-muted-foreground">
                                    ซื้อเป็น {{ i.purchase_unit_label }}
                                </p>
                            </td>
                            <td class="tabular text-right">
                                {{ number(i.stock_qty, i.unit_decimals) }} {{ i.unit_label }}
                            </td>
                            <td class="tabular text-right text-muted-foreground">
                                {{ number(i.reorder_level, i.unit_decimals) }}
                            </td>
                            <td class="tabular text-right">{{ number(i.cost_per_unit, 4) }}</td>
                            <td class="tabular text-right font-medium">{{ money(i.stock_value) }}</td>
                            <td>
                                <Badge :variant="i.is_low ? 'danger' : 'success'">
                                    {{ i.is_low ? 'ต่ำกว่าจุดสั่งซื้อ' : 'ปกติ' }}
                                </Badge>
                            </td>
                            <td>
                                <div class="flex justify-end gap-1">
                                    <Button variant="ghost" size="icon" aria-label="แก้ไข" @click="openEdit(i)">
                                        <Pencil />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="i.in_use ? 'ลบไม่ได้ มีสูตรใช้อยู่' : 'ลบ'"
                                        :disabled="i.in_use > 0"
                                        :title="i.in_use ? `มีสูตร/ตัวเลือกใช้อยู่ ${i.in_use} รายการ` : 'ลบ'"
                                        @click="removeItem(i)"
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </DataTable>
                <EmptyState v-else description="ยังไม่มีวัตถุดิบในระบบ — กด เพิ่มวัตถุดิบ เพื่อเริ่มต้น" />

                <Pagination :links="stockItems.links" :total="stockItems.total" />
            </SectionCard>

            <SectionCard title="ความเคลื่อนไหวล่าสุด" content-class="p-0">
                <ul v-if="movements.length" class="divide-y text-sm">
                    <li v-for="m in movements" :key="m.id" class="flex items-start justify-between gap-3 px-4 py-3">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center gap-2">
                                <p class="truncate font-medium text-foreground">
                                    {{ m.stock_item?.name ?? 'ไม่พบของชิ้นนี้' }}
                                </p>
                                <Badge :variant="movementBadgeVariant(m.type)" class="text-[10px] px-1.5 py-0 shrink-0">
                                    {{ m.type_label }}
                                </Badge>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                <span>{{ dateTime(m.occurred_at) }}</span>
                                <span>·</span>
                                <span>คงเหลือ {{ number(m.balance_after, m.stock_item?.unit_decimals ?? 2) }} {{ m.stock_item?.unit_label }}</span>
                            </div>
                            <p v-if="m.note" class="text-xs text-muted-foreground italic truncate">
                                {{ m.note }}
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <span
                                class="tabular font-semibold block"
                                :class="Number(m.qty) >= 0 ? 'text-[var(--status-good)]' : 'text-[var(--status-critical)]'"
                            >
                                {{ Number(m.qty) >= 0 ? '+' : '' }}{{ number(m.qty, m.stock_item?.unit_decimals ?? 2) }}
                                <span class="text-xs font-normal text-muted-foreground">{{ m.stock_item?.unit_label }}</span>
                            </span>
                            <span v-if="m.cost && Number(m.cost) > 0" class="text-[11px] tabular text-muted-foreground block">
                                {{ money(m.cost) }}
                            </span>
                        </div>
                    </li>
                </ul>
                <EmptyState v-else description="ยังไม่มีความเคลื่อนไหวในช่วงนี้" />
            </SectionCard>
        </div>

        <!-- ══ เพิ่ม/แก้ไขวัตถุดิบ ══ -->
        <Modal
            v-model:open="showItem"
            :title="editing ? 'แก้ไขวัตถุดิบ' : 'เพิ่มวัตถุดิบ'"
            description="คลังเก็บได้ทุกอย่างที่ร้านใช้ — เนื้อสัตว์ ซอส น้ำแข็ง ถุงพลาสติก"
        >
            <form class="space-y-3" @submit.prevent="submitItem">
                <div class="grid grid-cols-3 gap-3">
                    <div class="space-y-1">
                        <Label for="i-code">รหัส</Label>
                        <Input id="i-code" v-model="itemForm.code" placeholder="ING001" />
                        <p v-if="itemForm.errors.code" class="text-xs text-destructive">
                            {{ itemForm.errors.code }}
                        </p>
                    </div>
                    <div class="col-span-2 space-y-1">
                        <Label for="i-name">ชื่อ</Label>
                        <Input id="i-name" v-model="itemForm.name" required placeholder="หมูหมัก" />
                        <p v-if="itemForm.errors.name" class="text-xs text-destructive">
                            {{ itemForm.errors.name }}
                        </p>
                    </div>
                </div>

                <div class="space-y-1">
                    <Label for="i-unit">หน่วยฐาน</Label>
                    <Select id="i-unit" v-model="itemForm.unit" :disabled="unitLocked">
                        <option v-for="u in units" :key="u.value" :value="u.value">
                            {{ u.label }} ({{ u.group }})
                        </option>
                    </Select>
                    <p v-if="unitLocked" class="text-xs text-[var(--status-warning)]">
                        เปลี่ยนไม่ได้ — มีสูตรใช้วัตถุดิบนี้อยู่ {{ editing?.in_use }} รายการ
                        เปลี่ยนแล้วตัวเลขในสูตรจะเพี้ยน
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        หน่วยที่ใช้เก็บสต๊อกและเขียนสูตร — เลือกหน่วยเล็กสุด เช่น กรัม แทน กิโลกรัม
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="i-punit">หน่วยตอนซื้อ</Label>
                        <Input id="i-punit" v-model="itemForm.purchase_unit" placeholder="กก." />
                    </div>
                    <div class="space-y-1">
                        <Label for="i-pfac">1 หน่วยซื้อ = กี่{{ formUnitLabel }}</Label>
                        <Input
                            id="i-pfac"
                            v-model="itemForm.purchase_factor"
                            type="number"
                            step="0.0001"
                            min="0.0001"
                            required
                            class="tabular"
                        />
                        <p v-if="itemForm.errors.purchase_factor" class="text-xs text-destructive">
                            {{ itemForm.errors.purchase_factor }}
                        </p>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    ตั้งไว้แล้วตอนรับของกรอกเป็นหน่วยซื้อได้เลย ระบบแปลงจำนวนและหารราคาให้เอง
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="i-cost">ต้นทุนต่อ{{ formUnitLabel }}</Label>
                        <Input
                            id="i-cost"
                            v-model="itemForm.cost_per_unit"
                            type="number"
                            step="0.0001"
                            min="0"
                            required
                            class="tabular"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="i-reorder">จุดสั่งซื้อ ({{ formUnitLabel }})</Label>
                        <Input
                            id="i-reorder"
                            v-model="itemForm.reorder_level"
                            type="number"
                            step="0.001"
                            min="0"
                            required
                            class="tabular"
                        />
                    </div>
                </div>

                <div v-if="!editing" class="space-y-1">
                    <Label for="i-opening">ยอดตั้งต้น ({{ formUnitLabel }})</Label>
                    <Input id="i-opening" v-model="itemForm.opening_qty" type="number" step="0.001" class="tabular" />
                    <p class="text-xs text-muted-foreground">
                        บันทึกเป็นรายการ "ปรับยอด" ให้อัตโนมัติ จะได้มีรอยในบัญชีความเคลื่อนไหว
                    </p>
                </div>

                <p v-else class="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                    ยอดคงเหลือแก้ที่นี่ไม่ได้ — ใช้ปุ่ม "บันทึกสต๊อก" เพื่อให้ทุกการเปลี่ยนแปลงมีรอยเสมอ
                </p>

                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input v-model="itemForm.is_active" type="checkbox" class="size-4 rounded border-input" />
                    ใช้งาน
                </label>

                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input v-model="itemForm.used_here" type="checkbox" class="size-4 rounded border-input" />
                    สาขานี้ใช้ของชิ้นนี้
                </label>

                <!--
                    ของกลางใช้ร่วมกันทุกสาขา แก้ชื่อทีเดียวเปลี่ยนหมด
                    จึงให้เฉพาะเจ้าของระบบสร้างได้ และเปลี่ยนทีหลังไม่ได้ —
                    ของที่มีสาขาอื่นใช้อยู่แล้ว ดึงกลับมาเป็นของสาขาเดียวไม่ได้
                -->
                <label
                    v-if="canEditCentral && ! editing"
                    class="flex cursor-pointer items-start gap-2 rounded-lg border border-dashed px-3 py-2 text-sm"
                >
                    <input v-model="itemForm.is_central" type="checkbox" class="mt-0.5 size-4 rounded border-input" />
                    <span>
                        สร้างเป็นของกลาง
                        <span class="block text-xs text-muted-foreground">
                            ทุกสาขาหยิบไปใช้ในสูตรได้ แต่ยอดคงเหลือและต้นทุนยังแยกของใครของมัน
                        </span>
                    </span>
                </label>

                <div class="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="outline" @click="showItem = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="itemForm.processing">บันทึก</Button>
                </div>
            </form>
        </Modal>

        <!-- ══ บันทึกความเคลื่อนไหว ══ -->
        <Modal v-model:open="showModal" title="บันทึกความเคลื่อนไหวสต๊อก">
            <form class="space-y-3" @submit.prevent="submit">
                <div class="space-y-1">
                    <Label for="ing">วัตถุดิบ</Label>
                    <Select id="ing" v-model="form.stock_item_id" required>
                        <option value="">เลือกวัตถุดิบ</option>
                        <option v-for="i in stockItems.data" :key="i.id" :value="i.id">
                            {{ i.name }} (คงเหลือ {{ number(i.stock_qty, i.unit_decimals) }} {{ i.unit_label }})
                        </option>
                    </Select>
                </div>

                <div class="space-y-1">
                    <Label for="type">ประเภท</Label>
                    <Select id="type" v-model="form.type">
                        <option value="purchase">เติมสินค้า</option>
                        <option value="waste">ของเสีย</option>
                        <option value="adjust">ปรับยอด</option>
                    </Select>
                </div>

                <label v-if="canUsePurchaseUnit" class="flex cursor-pointer items-center gap-2 text-sm">
                    <input v-model="form.use_purchase_unit" type="checkbox" class="size-4 rounded border-input" />
                    กรอกเป็นหน่วยซื้อ ({{ moveTarget?.purchase_unit }})
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="qty">จำนวน</Label>
                        <Input id="qty" v-model="form.qty" type="number" step="0.001" required class="tabular" />
                        <p v-if="canUsePurchaseUnit && form.use_purchase_unit" class="text-xs text-muted-foreground">
                            = {{ number(convertedQty, moveTarget?.unit_decimals ?? 2) }} {{ moveTarget?.unit_label }}
                        </p>
                        <p v-else-if="moveTarget" class="text-xs text-muted-foreground">
                            หน่วย: {{ moveTarget.unit_label }}
                        </p>
                        <p v-if="form.type === 'waste'" class="text-xs text-muted-foreground">
                            ระบบตัดออกให้เอง ไม่ต้องใส่เครื่องหมายลบ
                        </p>
                    </div>
                    <div class="space-y-1">
                        <Label for="cost">
                            ราคาต่อ{{
                                canUsePurchaseUnit && form.use_purchase_unit
                                    ? moveTarget?.purchase_unit
                                    : (moveTarget?.unit_label ?? 'หน่วย')
                            }}
                        </Label>
                        <Input
                            id="cost"
                            v-model="form.unit_cost"
                            type="number"
                            step="0.0001"
                            min="0"
                            class="tabular"
                            placeholder="เว้นว่าง = ใช้ต้นทุนเดิม"
                        />
                    </div>
                </div>

                <div class="space-y-1">
                    <Label for="note">หมายเหตุ</Label>
                    <Input id="note" v-model="form.note" />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="outline" @click="showModal = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="form.processing">บันทึก</Button>
                </div>
            </form>
        </Modal>
    </BackOfficeLayout>
</template>
