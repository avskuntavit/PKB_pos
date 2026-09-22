<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { BellRing, ChevronRight, Minus, Plus, ReceiptText, Search, ShoppingBag, Trash2, Utensils } from 'lucide-vue-next'
import GuestLayout from '@/layouts/GuestLayout.vue'
import ProductSheet from '@/components/guest/ProductSheet.vue'
import ItemStageBadge from '@/components/guest/ItemStageBadge.vue'
import Modal from '@/components/ui/Modal.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Badge from '@/components/ui/Badge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useGuestCart } from '@/composables/useGuestCart'
import { usePolling } from '@/composables/usePolling'
import { money, number } from '@/lib/format'
import type { Category, Option, Product } from '@/types'

interface StatusPayload {
    order_no: string | null
    items: Array<{
        id: number
        name: string
        qty: number
        line_total: number
        modifiers: string[]
        note: string | null
        mine: boolean
        stage: string
    }>
    totals: {
        subtotal: number
        discount?: number
        service_charge?: number
        tax_amount?: number
        grand_total: number
    }
    pending_calls: Array<{ type: string; label: string; status: string }>
}

const props = defineProps<{
    qrToken: string
    branch: { name: string; vat_rate: number; vat_included: boolean; service_charge_rate: number }
    table: { name: string; zone: string | null }
    categories: Category[]
    products: Product[]
    status: StatusPayload
    callTypes: Option[]
}>()

const cart = useGuestCart(props.qrToken)

// สถานะออเดอร์อัพเดตเองทุก 8 วินาที ลูกค้าไม่ต้องรีเฟรชหน้า
const { data: live, fetchNow } = usePolling<StatusPayload>(`/t/${props.qrToken}/status`, 8000)
const status = computed<StatusPayload>(() => live.value ?? props.status)

const activeCategory = ref<number | 'all'>('all')
const search = ref('')
const sheetProduct = ref<Product | null>(null)
const showCart = ref(false)
const showOrders = ref(false)
const showCallMenu = ref(false)
const submitting = ref(false)

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase()

    return props.products.filter((p) => {
        const byCategory = activeCategory.value === 'all' || p.category_id === activeCategory.value
        return byCategory && (!term || p.name.toLowerCase().includes(term))
    })
})

const grouped = computed(() =>
    props.categories
        .map((c) => ({ category: c, items: filtered.value.filter((p) => p.category_id === c.id) }))
        .filter((g) => g.items.length > 0),
)

const waitingApproval = computed(
    () => status.value.items.filter((i) => i.stage === 'waiting_approval').length,
)

const billCallPending = computed(() =>
    status.value.pending_calls.some((c) => c.type === 'bill'),
)

function onAdd(
    product: Product,
    qty: number,
    modifierIds: number[],
    modifierNames: string[],
    note: string | null,
    unitPrice: number,
) {
    cart.add(product, qty, modifierIds, modifierNames, note, unitPrice)
    sheetProduct.value = null
}

function submitOrder() {
    if (cart.payload.value.length === 0) return

    submitting.value = true

    router.post(
        `/t/${props.qrToken}/orders`,
        { lines: cart.payload.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                cart.clear()
                showCart.value = false
                showOrders.value = true
                fetchNow()
            },
            onFinish: () => (submitting.value = false),
        },
    )
}

function callStaff(type: string) {
    router.post(
        `/t/${props.qrToken}/call`,
        { type },
        {
            preserveScroll: true,
            onSuccess: () => {
                showCallMenu.value = false
                fetchNow()
            },
        },
    )
}

// เปิดตะกร้าค้างไว้แล้วของหมดตะกร้า ให้ปิดแผ่นเอง
watch(
    () => cart.count.value,
    (count) => {
        if (count === 0) showCart.value = false
    },
)
</script>

<template>
    <Head :title="`สั่งอาหาร · โต๊ะ ${table.name}`" />

    <GuestLayout :branch-name="branch.name" :table-name="table.name" :zone-name="table.zone">
        <template #header-action>
            <Button variant="outline" size="sm" @click="showOrders = true">
                <ReceiptText />
                รายการที่สั่ง
                <Badge v-if="status.items.length" variant="secondary">{{ status.items.length }}</Badge>
            </Button>
        </template>

        <!-- แจ้งให้รู้ตั้งแต่ต้นว่าสั่งเองไม่ได้แต้ม -->
        <div class="px-4 pt-3">
            <div class="rounded-lg border border-dashed bg-card px-3 py-2 text-xs text-muted-foreground">
                สั่งผ่าน QR ไม่ต้องล็อกอิน สะดวกและเร็ว — แต่จะ<span class="font-medium text-foreground">ไม่ได้แต้มสมาชิกและใช้โปรโมชั่นเฉพาะสมาชิกไม่ได้</span>
                หากต้องการ กรุณาแจ้งพนักงานตอนชำระเงิน
            </div>
        </div>

        <div class="sticky top-[57px] z-20 space-y-2 bg-muted/40 px-4 py-3 backdrop-blur">
            <div class="relative">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="search" placeholder="ค้นหาเมนู" class="h-11 pl-9" />
            </div>

            <div class="flex gap-1.5 overflow-x-auto pb-1">
                <button
                    class="shrink-0 rounded-full border px-3 py-1.5 text-sm transition-colors"
                    :class="activeCategory === 'all' ? 'border-[var(--series-1)] bg-[var(--series-1)] text-white' : 'bg-card'"
                    @click="activeCategory = 'all'"
                >
                    ทั้งหมด
                </button>
                <button
                    v-for="c in categories"
                    :key="c.id"
                    class="shrink-0 rounded-full border px-3 py-1.5 text-sm transition-colors"
                    :class="activeCategory === c.id ? 'border-[var(--series-1)] bg-[var(--series-1)] text-white' : 'bg-card'"
                    @click="activeCategory = c.id"
                >
                    {{ c.name }}
                </button>
            </div>
        </div>

        <!-- รายการเมนู -->
        <div v-if="grouped.length" class="space-y-5 px-4 pt-1">
            <section v-for="group in grouped" :key="group.category.id">
                <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold">
                    <span class="size-2 rounded-full" :style="{ background: group.category.color }" />
                    {{ group.category.name }}
                </h2>

                <ul class="space-y-2">
                    <li v-for="p in group.items" :key="p.id">
                        <button
                            class="flex w-full items-center gap-3 rounded-xl border bg-card p-3 text-left transition-colors active:bg-accent"
                            @click="sheetProduct = p"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium">{{ p.name }}</span>
                                <span v-if="p.description" class="block truncate text-xs text-muted-foreground">
                                    {{ p.description }}
                                </span>
                                <span class="tabular mt-0.5 block text-sm font-semibold">{{ money(p.price) }} ฿</span>
                            </span>
                            <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
                        </button>
                    </li>
                </ul>
            </section>
        </div>
        <EmptyState v-else title="ไม่พบเมนู" description="ลองค้นหาด้วยคำอื่น หรือเลือกหมวดหมู่อื่น" />

        <!-- แถบล่าง: ตะกร้า + เรียกพนักงาน -->
        <template #bottom-bar>
            <div class="fixed inset-x-0 bottom-0 z-30 border-t bg-card/95 backdrop-blur">
                <div
                    class="mx-auto flex max-w-2xl items-center gap-2 px-4 py-3"
                    :style="{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom, 0px))' }"
                >
                    <Button variant="outline" size="lg" class="shrink-0" @click="showCallMenu = true">
                        <BellRing />
                        <span class="sr-only sm:not-sr-only">เรียกพนักงาน</span>
                    </Button>

                    <Button
                        variant="brand"
                        size="lg"
                        class="flex-1"
                        :disabled="cart.count.value === 0"
                        @click="showCart = true"
                    >
                        <ShoppingBag />
                        ตะกร้า {{ number(cart.count.value) }} รายการ · {{ money(cart.total.value) }} ฿
                    </Button>
                </div>
            </div>
        </template>

        <!-- แผ่นเลือกตัวเลือกสินค้า -->
        <ProductSheet
            v-if="sheetProduct"
            :product="sheetProduct"
            @close="sheetProduct = null"
            @add="onAdd"
        />

        <!-- ตะกร้า -->
        <Modal v-model:open="showCart" title="ตะกร้าของคุณ" description="ตรวจสอบก่อนส่งให้พนักงาน">
            <!-- ป้ายระบุรูปแบบการสั่งปัจจุบัน -->
            <div class="mb-3 flex items-center justify-between gap-2 rounded-xl border border-[var(--series-1)]/30 bg-[var(--series-1)]/10 px-3 py-2 text-xs">
                <div class="flex items-center gap-2">
                    <span class="grid size-7 place-items-center rounded-lg bg-[var(--series-1)] text-white shadow-xs">
                        <Utensils class="size-3.5" />
                    </span>
                    <div>
                        <span class="font-bold text-[var(--series-1)]">รูปแบบ: ทานที่ร้าน (เสิร์ฟถึงโต๊ะ)</span>
                        <p class="font-semibold text-foreground">โต๊ะ {{ table.name }} <span v-if="table.zone" class="font-normal text-muted-foreground">({{ table.zone }})</span></p>
                    </div>
                </div>
                <span class="text-xs text-muted-foreground">{{ branch.name }}</span>
            </div>

            <ul v-if="cart.lines.value.length" class="max-h-[45vh] space-y-2 overflow-y-auto">
                <li v-for="line in cart.lines.value" :key="line.key" class="rounded-lg border p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ line.name }}</p>
                            <p v-if="line.modifier_names.length" class="truncate text-xs text-muted-foreground">
                                {{ line.modifier_names.join(', ') }}
                            </p>
                            <p v-if="line.note" class="truncate text-xs text-muted-foreground">
                                หมายเหตุ: {{ line.note }}
                            </p>
                        </div>
                        <span class="tabular shrink-0 text-sm font-semibold">
                            {{ money(line.unit_price * line.qty) }}
                        </span>
                    </div>

                    <div class="mt-2 flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-9"
                            aria-label="ลดจำนวน"
                            @click="cart.setQty(line.key, line.qty - 1)"
                        >
                            <Minus />
                        </Button>
                        <span class="tabular w-10 text-center text-sm">{{ line.qty }}</span>
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-9"
                            aria-label="เพิ่มจำนวน"
                            @click="cart.setQty(line.key, line.qty + 1)"
                        >
                            <Plus />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="ml-auto size-9 text-[var(--status-critical)]"
                            aria-label="ลบรายการ"
                            @click="cart.setQty(line.key, 0)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </li>
            </ul>
            <EmptyState v-else title="ตะกร้าว่าง" description="เลือกเมนูที่ต้องการก่อนนะครับ" />

            <div v-if="cart.lines.value.length" class="space-y-3 border-t pt-3">
                <div class="flex items-baseline justify-between">
                    <span class="text-sm text-muted-foreground">ยอดรวมรอบนี้</span>
                    <span class="tabular text-xl font-semibold">{{ money(cart.total.value) }} ฿</span>
                </div>
                <p class="text-xs text-muted-foreground">
                    กดยืนยันแล้วรายการจะถูกส่งให้พนักงานตรวจก่อนเข้าครัว — แก้ไขเองภายหลังไม่ได้
                </p>
                <Button
                    variant="brand"
                    size="xl"
                    class="w-full"
                    :disabled="submitting"
                    @click="submitOrder"
                >
                    {{ submitting ? 'กำลังส่ง...' : 'ยืนยันสั่งอาหาร' }}
                </Button>
            </div>
        </Modal>

        <!-- รายการที่สั่งแล้ว -->
        <Modal
            v-model:open="showOrders"
            title="รายการที่สั่งแล้ว"
            :description="status.order_no ? `บิล ${status.order_no}` : undefined"
        >
            <ul v-if="status.items.length" class="max-h-[50vh] space-y-2 overflow-y-auto">
                <li v-for="item in status.items" :key="item.id" class="rounded-lg border p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ item.name }}
                                <span class="tabular text-muted-foreground">×{{ number(item.qty) }}</span>
                            </p>
                            <p v-if="item.modifiers.length" class="truncate text-xs text-muted-foreground">
                                {{ item.modifiers.join(', ') }}
                            </p>
                            <p v-if="!item.mine" class="text-xs text-muted-foreground">
                                สั่งโดยพนักงาน / อุปกรณ์เครื่องอื่น
                            </p>
                            <ItemStageBadge :stage="item.stage" class="mt-1" />
                        </div>
                        <span class="tabular shrink-0 text-sm">{{ money(item.line_total) }}</span>
                    </div>
                </li>
            </ul>
            <EmptyState v-else title="ยังไม่มีรายการ" description="สั่งอาหารจากเมนูได้เลย" />

            <div v-if="status.items.length" class="space-y-3 border-t pt-3">
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">ยอดรวม</dt>
                        <dd class="tabular">{{ money(status.totals.subtotal) }} ฿</dd>
                    </div>
                    <div v-if="status.totals.service_charge" class="flex justify-between">
                        <dt class="text-muted-foreground">ค่าบริการ</dt>
                        <dd class="tabular">{{ money(status.totals.service_charge) }} ฿</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-t pt-1.5">
                        <dt class="font-medium">ยอดที่ต้องชำระ</dt>
                        <dd class="tabular text-xl font-semibold">{{ money(status.totals.grand_total) }} ฿</dd>
                    </div>
                </dl>

                <p v-if="waitingApproval" class="text-xs text-[var(--status-warning)]">
                    มี {{ waitingApproval }} รายการรอพนักงานยืนยัน ยอดอาจเปลี่ยนได้
                </p>

                <Button
                    variant="brand"
                    size="xl"
                    class="w-full"
                    :disabled="billCallPending"
                    @click="callStaff('bill')"
                >
                    {{ billCallPending ? 'แจ้งเรียกเก็บเงินแล้ว รอพนักงาน' : 'เรียกพนักงานเก็บเงิน' }}
                </Button>
                <p class="text-center text-xs text-muted-foreground">
                    ชำระเงินที่โต๊ะกับพนักงาน — หลังปิดบิลจะสั่งเพิ่มไม่ได้
                </p>
            </div>
        </Modal>

        <!-- เรียกพนักงาน -->
        <Modal v-model:open="showCallMenu" title="ต้องการให้ช่วยอะไรครับ">
            <div class="grid gap-2">
                <Button
                    v-for="type in callTypes"
                    :key="type.value"
                    variant="outline"
                    size="xl"
                    class="justify-start"
                    :disabled="status.pending_calls.some((c) => c.type === type.value)"
                    @click="callStaff(type.value)"
                >
                    <BellRing />
                    {{ type.label }}
                    <span
                        v-if="status.pending_calls.some((c) => c.type === type.value)"
                        class="ml-auto text-xs text-muted-foreground"
                    >
                        แจ้งแล้ว
                    </span>
                </Button>
            </div>
        </Modal>
    </GuestLayout>
</template>
