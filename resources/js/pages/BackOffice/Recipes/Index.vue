<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { Calculator, ChefHat, Plus, RefreshCw, Trash2 } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Pagination from '@/components/ui/Pagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import { money, number, percent } from '@/lib/format'
import type { Paginated } from '@/types'

interface RecipeLine {
    id?: number
    stock_item_id: number
    name?: string | null
    unit?: string | null
    qty: number
    cost_per_unit?: number
    line_cost?: number
}

interface ProductRow {
    id: number
    name: string
    category: string | null
    price: number
    cost: number
    recipe_cost: number
    track_stock: boolean
    has_recipe: boolean
    margin: number
    items: RecipeLine[]
}

const props = defineProps<{
    products: Paginated<ProductRow>
    stockItems: Array<{ id: number; name: string; unit: string; cost_per_unit: string | number }>
    filters: { search?: string }
}>()

const search = ref(props.filters.search ?? '')
const editing = ref<ProductRow | null>(null)
const lines = ref<RecipeLine[]>([])
const trackStock = ref(false)
const syncCost = ref(true)
const saving = ref(false)

/** ต้นทุนตามสูตรที่กำลังแก้อยู่ — คำนวณสดให้เห็นผลทันทีขณะพิมพ์ */
const draftCost = computed(() =>
    lines.value.reduce((sum, line) => {
        const stockItem = props.stockItems.find((i) => i.id === Number(line.stock_item_id))
        return sum + Number(line.qty || 0) * Number(stockItem?.cost_per_unit ?? 0)
    }, 0),
)

const draftMargin = computed(() => {
    const price = Number(editing.value?.price ?? 0)
    return price > 0 ? ((price - draftCost.value) / price) * 100 : 0
})

function reload() {
    router.get('/backoffice/recipes', { search: search.value }, { preserveState: true, replace: true })
}

function open(product: ProductRow) {
    editing.value = product
    lines.value = product.items.map((i) => ({ ...i }))
    trackStock.value = product.track_stock
    syncCost.value = true
}

function addLine() {
    const first = props.stockItems[0]
    if (!first) return

    lines.value.push({ stock_item_id: first.id, qty: 0.1 })
}

function save() {
    if (!editing.value) return

    saving.value = true

    router.put(
        `/backoffice/recipes/${editing.value.id}`,
        {
            items: lines.value
                .filter((l) => Number(l.qty) > 0)
                .map((l) => ({ stock_item_id: Number(l.stock_item_id), qty: Number(l.qty) })),
            track_stock: trackStock.value,
            sync_cost: syncCost.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
            onFinish: () => (saving.value = false),
        },
    )
}

function syncAll() {
    if (!confirm('อัปเดตต้นทุนของทุกเมนูที่มีสูตร ให้ตรงกับราคาวัตถุดิบปัจจุบัน?')) return

    router.post('/backoffice/recipes/sync-costs', {}, { preserveScroll: true })
}
</script>

<template>
    <Head title="สูตรและส่วนผสม" />

    <BackOfficeLayout title="สูตรและส่วนผสม">
        <SectionCard title="เมนูทั้งหมด" content-class="p-0">
            <template #actions>
                <div class="flex flex-wrap gap-2">
                    <Input v-model="search" placeholder="ค้นหาเมนู" class="w-44" @keyup.enter="reload" />
                    <Button variant="outline" size="sm" @click="syncAll">
                        <RefreshCw />
                        ซิงก์ต้นทุนทุกเมนู
                    </Button>
                </div>
            </template>

            <p class="border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
                สูตรทำสองอย่าง: ตัดสต๊อกวัตถุดิบอัตโนมัติเมื่อขาย และคำนวณต้นทุนจริงแทนการเดา
            </p>

            <DataTable v-if="products.data.length">
                <thead>
                    <tr>
                        <th>เมนู</th>
                        <th>หมวดหมู่</th>
                        <th class="text-right">ราคาขาย</th>
                        <th class="text-right">ต้นทุนที่บันทึก</th>
                        <th class="text-right">ต้นทุนตามสูตร</th>
                        <th class="text-right">อัตรากำไร</th>
                        <th>สถานะ</th>
                        <th class="text-right">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id">
                        <td class="font-medium">{{ p.name }}</td>
                        <td class="text-muted-foreground">{{ p.category ?? '-' }}</td>
                        <td class="tabular text-right">{{ money(p.price) }}</td>
                        <td class="tabular text-right">{{ money(p.cost) }}</td>
                        <td class="tabular text-right">
                            <span v-if="p.has_recipe" :class="Math.abs(p.recipe_cost - p.cost) > 0.01 ? 'text-[var(--status-warning)]' : ''">
                                {{ money(p.recipe_cost) }}
                            </span>
                            <span v-else class="text-muted-foreground">-</span>
                        </td>
                        <td class="tabular text-right">{{ percent(p.margin, 1) }}</td>
                        <td>
                            <Badge v-if="p.track_stock" variant="success">ตัดสต๊อก</Badge>
                            <Badge v-else-if="p.has_recipe" variant="outline">มีสูตร</Badge>
                            <Badge v-else variant="secondary">ยังไม่มีสูตร</Badge>
                        </td>
                        <td class="text-right">
                            <Button variant="outline" size="sm" @click="open(p)">
                                <ChefHat />
                                แก้สูตร
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else title="ไม่พบเมนู" />

            <Pagination :links="products.links" :total="products.total" />
        </SectionCard>

        <Modal
            :open="editing !== null"
            :title="editing ? `สูตรของ ${editing.name}` : ''"
            class="max-w-2xl"
            @update:open="(v) => !v && (editing = null)"
        >
            <div class="space-y-4">
                <ul v-if="lines.length" class="max-h-[40vh] space-y-2 overflow-y-auto">
                    <li v-for="(line, i) in lines" :key="i" class="flex flex-wrap items-end gap-2 rounded-lg border p-3">
                        <div class="min-w-[180px] flex-1 space-y-1">
                            <label :for="`ing-${i}`" class="text-xs text-muted-foreground">วัตถุดิบ</label>
                            <Select :id="`ing-${i}`" v-model.number="line.stock_item_id">
                                <option v-for="ing in stockItems" :key="ing.id" :value="ing.id">
                                    {{ ing.name }} ({{ money(ing.cost_per_unit) }}/{{ ing.unit }})
                                </option>
                            </Select>
                        </div>

                        <div class="w-28 space-y-1">
                            <label :for="`qty-${i}`" class="text-xs text-muted-foreground">ใช้ต่อ 1 จาน</label>
                            <Input :id="`qty-${i}`" v-model.number="line.qty" type="number" step="0.0001" min="0" />
                        </div>

                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="text-[var(--status-critical)]"
                            aria-label="ลบส่วนผสม"
                            @click="lines.splice(i, 1)"
                        >
                            <Trash2 />
                        </Button>
                    </li>
                </ul>
                <EmptyState v-else title="ยังไม่มีส่วนผสม" description="เพิ่มวัตถุดิบเพื่อคำนวณต้นทุนอัตโนมัติ" />

                <Button type="button" variant="outline" size="sm" :disabled="stockItems.length === 0" @click="addLine">
                    <Plus />
                    เพิ่มส่วนผสม
                </Button>

                <div class="rounded-lg border bg-muted/40 p-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="flex items-center gap-1.5 text-muted-foreground">
                            <Calculator class="size-4" />
                            ต้นทุนตามสูตร
                        </span>
                        <span class="tabular text-lg font-semibold">{{ money(draftCost) }} ฿</span>
                    </div>
                    <div class="mt-1 flex items-center justify-between text-xs text-muted-foreground">
                        <span>ราคาขาย {{ money(editing?.price ?? 0) }} ฿</span>
                        <span :class="draftMargin < 30 ? 'text-[var(--status-warning)]' : ''">
                            อัตรากำไร {{ percent(draftMargin, 1) }}
                        </span>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="trackStock" type="checkbox" class="mt-0.5 size-4" />
                        <span>
                            ตัดสต๊อกวัตถุดิบอัตโนมัติเมื่อขายเมนูนี้
                            <span class="block text-xs text-muted-foreground">ต้องมีส่วนผสมอย่างน้อย 1 อย่าง</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="syncCost" type="checkbox" class="mt-0.5 size-4" />
                        <span>
                            อัปเดตต้นทุนของเมนูให้เท่ากับต้นทุนตามสูตร
                            <span class="block text-xs text-muted-foreground">
                                ไม่ติ๊กถ้าอยากกรอกต้นทุนเองเพื่อเผื่อของเสีย
                            </span>
                        </span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 border-t pt-3">
                    <Button variant="outline" @click="editing = null">ยกเลิก</Button>
                    <Button variant="brand" :disabled="saving" @click="save">บันทึกสูตร</Button>
                </div>
            </div>
        </Modal>
    </BackOfficeLayout>
</template>
