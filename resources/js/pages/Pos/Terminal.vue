<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ArrowLeft, ChefHat, Minus, Percent, Plus, Search, Trash2 } from 'lucide-vue-next'
import PosLayout from '@/layouts/PosLayout.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Modal from '@/components/ui/Modal.vue'
import Badge from '@/components/ui/Badge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaymentDialog from '@/components/pos/PaymentDialog.vue'
import ModifierDialog from '@/components/pos/ModifierDialog.vue'
import ApprovalPanel from '@/components/pos/ApprovalPanel.vue'
import { money, number } from '@/lib/format'
import { useIdempotencyKey } from '@/lib/idempotency'
import type { Category, Option, Order, OrderItem, Product } from '@/types'

const props = defineProps<{
    categories: Category[]
    products: Product[]
    order: Order | null
    tables: Array<{ id: number; name: string; status: string; seats: number }>
    orderTypes: Option[]
    paymentMethods: Option[]
    openOrders: Array<Record<string, any>>
    kitchenTickets: Array<Record<string, any>>
    courses: Array<{ value: number; label: string; color: string }>
}>()

const activeCategory = ref<number | 'all'>('all')
const search = ref('')
const showPayment = ref(false)
const showDiscount = ref(false)
const modifierProduct = ref<Product | null>(null)

const discountForm = useForm({ amount: 0, percent: null as number | null })

// ส่งครัวซ้ำ = ใบสั่งซ้ำบนโต๊ะครัว ครัวทำสองจาน กันไว้ด้วยคีย์เดียวกับฝั่งชำระเงิน
const { rotate: rotateSendKey, headers: sendHeaders } = useIdempotencyKey()

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase()

    return props.products.filter((p) => {
        const byCategory = activeCategory.value === 'all' || p.category_id === activeCategory.value
        const byTerm = !term || p.name.toLowerCase().includes(term)
        return byCategory && byTerm
    })
})

const activeItems = computed<OrderItem[]>(
    () => props.order?.items?.filter((i) => i.status !== 'void') ?? [],
)

/** รายการที่ลูกค้าสแกนสั่งเองและยังไม่ถูกยืนยัน — ต้องเคลียร์ก่อนจึงจะชำระเงินได้ */
const pendingApproval = computed<OrderItem[]>(
    () => activeItems.value.filter((i) => i.approval_status === 'pending'),
)

const itemCount = computed(() =>
    activeItems.value.reduce((sum, i) => sum + Number(i.qty), 0),
)

/** รายการที่กดส่งครัวได้ตอนนี้ — ยังไม่ส่ง และไม่ติดค้างรอยืนยัน */
const sendable = computed<OrderItem[]>(() =>
    activeItems.value.filter((i) => i.status === 'pending' && i.approval_status !== 'pending'),
)

const courseOf = (item: OrderItem) => Number(item.course ?? 0)

const courseLabel = (value: number) =>
    props.courses.find((c) => c.value === value)?.label ?? 'ไม่จัดคอร์ส'

const courseColor = (value: number) =>
    props.courses.find((c) => c.value === value)?.color ?? null

/**
 * กองที่รอส่งครัว เรียงตามลำดับคอร์ส
 *
 * ของที่ไม่ได้จัดคอร์สไปอยู่ท้ายสุด เพราะถ้าพนักงานตั้งใจจัดคอร์สไว้แล้ว
 * ของที่ยังไม่ได้จัดมักเป็นของที่เพิ่งกดเพิ่มและยังไม่ได้ตัดสินใจ
 */
const courseGroups = computed(() => {
    const groups = new Map<number, OrderItem[]>()

    for (const item of sendable.value) {
        const key = courseOf(item)
        if (!groups.has(key)) groups.set(key, [])
        groups.get(key)!.push(item)
    }

    return [...groups.entries()]
        .sort(([a], [b]) => (a || 99) - (b || 99))
        .map(([key, items]) => ({ key, label: courseLabel(key), items }))
})

function pickProduct(product: Product) {
    if (!props.order) return

    // มีตัวเลือกหรือเป็นราคาเปิด ต้องถามก่อน ไม่งั้นใส่ลงบิลได้เลย
    if (product.modifier_groups.length > 0 || product.is_open_price) {
        modifierProduct.value = product
        return
    }

    addItem(product.id, 1, [], null, null)
}

function addItem(
    productId: number,
    qty: number,
    modifierIds: number[],
    note: string | null,
    openPrice: number | null,
) {
    if (!props.order) return

    router.post(
        `/pos/orders/${props.order.id}/items`,
        { product_id: productId, qty, modifier_ids: modifierIds, note, open_price: openPrice },
        { preserveScroll: true, preserveState: true },
    )

    modifierProduct.value = null
}

function changeQty(item: OrderItem, delta: number) {
    const qty = Number(item.qty) + delta

    if (qty <= 0) {
        router.delete(`/pos/items/${item.id}`, { preserveScroll: true, preserveState: true })
        return
    }

    router.put(`/pos/items/${item.id}`, { qty }, { preserveScroll: true, preserveState: true })
}

function removeItem(item: OrderItem) {
    router.delete(`/pos/items/${item.id}`, { preserveScroll: true, preserveState: true })
}

/** ไม่ส่ง itemIds = ส่งทุกอย่างที่ค้าง, ส่งมา = ส่งเฉพาะกองนั้น */
function sendToKitchen(itemIds?: number[]) {
    if (!props.order) return

    router.post(
        `/pos/orders/${props.order.id}/send`,
        itemIds ? { items: itemIds } : {},
        {
            preserveScroll: true,
            preserveState: true,
            headers: sendHeaders(),
            // ส่งสำเร็จแล้ว รอบหน้าคือของใหม่จริง ๆ ต้องได้คีย์ใหม่
            onSuccess: () => rotateSendKey(),
        },
    )
}

function setCourse(item: OrderItem, value: string) {
    router.put(
        `/pos/items/${item.id}/course`,
        { course: value === '' ? null : Number(value) },
        { preserveScroll: true, preserveState: true },
    )
}

function applyDiscount() {
    if (!props.order) return

    discountForm.post(`/pos/orders/${props.order.id}/discount`, {
        preserveScroll: true,
        onSuccess: () => (showDiscount.value = false),
    })
}

/** รอบล่าสุดที่ส่งครัว — ให้พนักงานตอบลูกค้าได้ว่าอาหารถึงไหนแล้ว */
const latestTicket = computed(() => props.kitchenTickets?.[0] ?? null)

const ticketStatusLabel: Record<string, string> = {
    queued: 'ครัวรับคิวแล้ว',
    preparing: 'ครัวกำลังทำ',
    ready: 'พร้อมเสิร์ฟ',
    served: 'เสิร์ฟครบแล้ว',
    cancelled: 'ยกเลิก',
}

const categoryColor = (id: number | null) =>
    props.categories.find((c) => c.id === id)?.color ?? 'var(--series-1)'
</script>

<template>
    <Head title="หน้าขาย" />

    <PosLayout title="หน้าขาย">
        <div class="flex h-full">
            <!-- ซ้าย: เมนู -->
            <section class="flex min-w-0 flex-1 flex-col border-r">
                <div class="flex items-center gap-2 border-b bg-card px-3 py-2">
                    <Link href="/pos" class="rounded-md p-2 text-muted-foreground hover:bg-accent" aria-label="กลับผังโต๊ะ">
                        <ArrowLeft class="size-4" />
                    </Link>
                    <div class="relative flex-1">
                        <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="ค้นหาเมนู" class="pl-8" />
                    </div>
                </div>

                <div class="flex gap-1 overflow-x-auto border-b bg-card px-3 py-2">
                    <Button
                        :variant="activeCategory === 'all' ? 'secondary' : 'ghost'"
                        size="sm"
                        @click="activeCategory = 'all'"
                    >
                        ทั้งหมด
                    </Button>
                    <Button
                        v-for="c in categories"
                        :key="c.id"
                        :variant="activeCategory === c.id ? 'secondary' : 'ghost'"
                        size="sm"
                        class="shrink-0"
                        @click="activeCategory = c.id"
                    >
                        <span class="size-2 rounded-full" :style="{ background: c.color }" />
                        {{ c.name }}
                    </Button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-3">
                    <div
                        v-if="filtered.length"
                        class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
                    >
                        <button
                            v-for="p in filtered"
                            :key="p.id"
                            class="flex h-24 flex-col justify-between rounded-xl border bg-card p-3 text-left transition-colors hover:border-[var(--series-1)] disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!order"
                            @click="pickProduct(p)"
                        >
                            <span class="line-clamp-2 text-sm font-medium">{{ p.name }}</span>
                            <span class="flex items-center justify-between gap-1">
                                <span
                                    class="size-2 rounded-full"
                                    :style="{ background: categoryColor(p.category_id) }"
                                />
                                <span class="tabular text-sm font-semibold">{{ money(p.price) }}</span>
                            </span>
                        </button>
                    </div>
                    <EmptyState v-else description="ไม่พบเมนูที่ค้นหา" />
                </div>
            </section>

            <!-- ขวา: ตะกร้า -->
            <aside class="flex w-[340px] shrink-0 flex-col bg-card xl:w-[400px]">
                <template v-if="order">
                    <div class="border-b px-4 py-3">
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold">
                                    {{ order.dining_table?.name ? `โต๊ะ ${order.dining_table.name}` : 'ซื้อกลับบ้าน' }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    บิล {{ order.order_no }} · {{ order.guest_count }} คน
                                </p>
                                <p v-if="latestTicket" class="text-xs text-muted-foreground">
                                    ครัวรอบที่ {{ latestTicket.round }} ·
                                    {{ ticketStatusLabel[latestTicket.status] ?? latestTicket.status }}
                                </p>
                            </div>
                            <Badge variant="outline">{{ number(itemCount) }} รายการ</Badge>
                        </div>
                    </div>

                    <ApprovalPanel
                        v-if="pendingApproval.length"
                        :order-id="order.id"
                        :items="pendingApproval"
                    />

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <ul v-if="activeItems.length" class="divide-y">
                            <li v-for="item in activeItems" :key="item.id" class="px-4 py-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-1.5 truncate text-sm font-medium">
                                            <span
                                                v-if="courseColor(courseOf(item))"
                                                class="size-2 shrink-0 rounded-full"
                                                :style="{ background: courseColor(courseOf(item)) as string }"
                                                :title="courseLabel(courseOf(item))"
                                            />
                                            <span class="truncate">{{ item.product_name }}</span>
                                        </p>
                                        <p v-if="item.modifiers.length" class="truncate text-xs text-muted-foreground">
                                            {{ item.modifiers.map((m) => m.name).join(', ') }}
                                        </p>
                                        <p v-if="item.note" class="truncate text-xs text-muted-foreground">
                                            หมายเหตุ: {{ item.note }}
                                        </p>
                                        <Badge
                                            v-if="item.approval_status === 'pending'"
                                            variant="warning"
                                            class="mt-1"
                                        >
                                            ลูกค้าสั่ง · รอยืนยัน
                                        </Badge>
                                        <Badge
                                            v-else-if="item.status === 'pending'"
                                            variant="outline"
                                            class="mt-1"
                                        >
                                            ยังไม่ส่งครัว
                                        </Badge>
                                    </div>
                                    <span class="tabular shrink-0 text-sm font-semibold">
                                        {{ money(item.line_total) }}
                                    </span>
                                </div>

                                <div class="mt-2 flex items-center gap-1">
                                    <Button variant="outline" size="icon" class="size-7" @click="changeQty(item, -1)">
                                        <Minus />
                                    </Button>
                                    <span class="tabular w-9 text-center text-sm">{{ number(item.qty) }}</span>
                                    <Button variant="outline" size="icon" class="size-7" @click="changeQty(item, 1)">
                                        <Plus />
                                    </Button>
                                    <div class="ml-auto flex items-center gap-1">
                                        <!-- เลือกคอร์สได้เฉพาะก่อนส่งครัว พอใบออกไปแล้วเปลี่ยนที่นี่ก็ไม่ถึงครัว -->
                                        <select
                                            v-if="
                                                courses.length &&
                                                item.status === 'pending' &&
                                                item.approval_status !== 'pending'
                                            "
                                            class="h-7 rounded-md border bg-card px-1 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            :value="item.course ?? ''"
                                            aria-label="คอร์ส"
                                            @change="setCourse(item, ($event.target as HTMLSelectElement).value)"
                                        >
                                            <option value="">ไม่จัดคอร์ส</option>
                                            <option v-for="c in courses" :key="c.value" :value="c.value">
                                                {{ c.label }}
                                            </option>
                                        </select>

                                        <Button
                                            v-if="item.approval_status !== 'pending'"
                                            variant="ghost"
                                            size="icon"
                                            class="size-7 text-[var(--status-critical)]"
                                            aria-label="ยกเลิกรายการ"
                                            @click="removeItem(item)"
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </div>
                            </li>
                        </ul>
                        <EmptyState v-else title="บิลยังว่าง" description="เลือกเมนูจากด้านซ้ายเพื่อเริ่มสั่ง" />
                    </div>

                    <div class="space-y-3 border-t px-4 py-3">
                        <dl class="space-y-1 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">ยอดขาย</dt>
                                <dd class="tabular">{{ money(order.subtotal) }}</dd>
                            </div>
                            <div v-if="Number(order.bill_discount) > 0" class="flex justify-between">
                                <dt class="text-muted-foreground">ลดท้ายบิล</dt>
                                <dd class="tabular">-{{ money(order.bill_discount) }}</dd>
                            </div>
                            <div v-if="Number(order.service_charge) > 0" class="flex justify-between">
                                <dt class="text-muted-foreground">ค่าบริการ</dt>
                                <dd class="tabular">{{ money(order.service_charge) }}</dd>
                            </div>
                            <div class="flex justify-between text-xs text-muted-foreground">
                                <dt>ภาษีในราคา</dt>
                                <dd class="tabular">{{ money(order.tax_amount) }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between border-t pt-1.5">
                                <dt class="font-medium">รวมสุทธิ</dt>
                                <dd class="tabular text-xl font-semibold">{{ money(order.grand_total) }}</dd>
                            </div>
                        </dl>

                        <div class="space-y-2">
                            <!-- ปุ่มรายคอร์ส โผล่เมื่อบิลนี้มีของค้างมากกว่าหนึ่งกองจริง ๆ -->
                            <div v-if="courseGroups.length > 1" class="grid grid-cols-2 gap-2">
                                <Button
                                    v-for="g in courseGroups"
                                    :key="g.key"
                                    variant="outline"
                                    size="sm"
                                    class="justify-start"
                                    @click="sendToKitchen(g.items.map((i) => i.id))"
                                >
                                    <span
                                        v-if="courseColor(g.key)"
                                        class="size-2 shrink-0 rounded-full"
                                        :style="{ background: courseColor(g.key) as string }"
                                    />
                                    <span class="truncate">{{ g.label }}</span>
                                    <span class="tabular ml-auto text-muted-foreground">{{ g.items.length }}</span>
                                </Button>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <Button variant="outline" :disabled="!sendable.length" @click="sendToKitchen()">
                                    <ChefHat />
                                    {{ courseGroups.length > 1 ? 'ส่งทั้งหมด' : 'ส่งครัว' }}
                                </Button>
                                <Button variant="outline" @click="showDiscount = true">
                                    <Percent />
                                    ส่วนลด
                                </Button>
                            </div>
                        </div>

                        <p v-if="pendingApproval.length" class="text-center text-xs text-[var(--status-warning)]">
                            ยังมี {{ pendingApproval.length }} รายการที่ลูกค้าสั่งรอยืนยัน — เคลียร์ก่อนจึงจะปิดบิลได้
                        </p>

                        <Button
                            variant="brand"
                            size="xl"
                            class="w-full"
                            :disabled="activeItems.length === 0 || pendingApproval.length > 0"
                            @click="showPayment = true"
                        >
                            ชำระเงิน {{ money(order.grand_total) }} บาท
                        </Button>
                    </div>
                </template>

                <!-- ยังไม่ได้เลือกบิล -->
                <template v-else>
                    <div class="border-b px-4 py-3">
                        <p class="text-sm font-semibold">บิลที่เปิดอยู่</p>
                    </div>
                    <ul v-if="openOrders.length" class="min-h-0 flex-1 divide-y overflow-y-auto">
                        <li v-for="o in openOrders" :key="o.id">
                            <Link
                                :href="`/pos/terminal/${o.id}`"
                                class="flex items-center justify-between gap-2 px-4 py-3 hover:bg-accent"
                            >
                                <span>
                                    <span class="block text-sm font-medium">
                                        {{ o.dining_table?.name ? `โต๊ะ ${o.dining_table.name}` : 'ซื้อกลับบ้าน' }}
                                    </span>
                                    <span class="block text-xs text-muted-foreground">{{ o.order_no }}</span>
                                </span>
                                <span class="tabular text-sm font-semibold">{{ money(o.grand_total) }}</span>
                            </Link>
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        title="ยังไม่มีบิลที่เปิดอยู่"
                        description="กลับไปที่ผังโต๊ะเพื่อเปิดบิลใหม่"
                    />
                </template>
            </aside>
        </div>

        <ModifierDialog
            v-if="modifierProduct"
            :product="modifierProduct"
            @close="modifierProduct = null"
            @confirm="addItem"
        />

        <PaymentDialog
            v-if="order"
            v-model:open="showPayment"
            :order="order"
            :methods="paymentMethods"
        />

        <Modal v-model:open="showDiscount" title="ส่วนลดท้ายบิล">
            <form class="space-y-3" @submit.prevent="applyDiscount">
                <div class="space-y-1">
                    <Label for="damount">ลดเป็นจำนวนเงิน (บาท)</Label>
                    <Input id="damount" v-model="discountForm.amount" type="number" step="0.01" min="0" />
                </div>
                <div class="space-y-1">
                    <Label for="dpercent">หรือลดเป็นเปอร์เซ็นต์</Label>
                    <Input id="dpercent" v-model="discountForm.percent" type="number" step="0.01" min="0" max="100" />
                    <p class="text-xs text-muted-foreground">ถ้ากรอกเปอร์เซ็นต์ ระบบจะใช้ค่านี้แทนจำนวนเงิน</p>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" @click="showDiscount = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="discountForm.processing">ใช้ส่วนลด</Button>
                </div>
            </form>
        </Modal>
    </PosLayout>
</template>
