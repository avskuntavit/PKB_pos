<script setup lang="ts">
/**
 * ตะกร้า + ยืนยันออเดอร์ หน้าเดียวเลื่อนยาว
 *
 * รวมสองขั้นเดิมเข้าด้วยกัน เพราะการกด "ถัดไป" แล้วค่อยกรอกข้อมูล
 * ทำให้ลูกค้าไม่เห็นยอดรวมตอนเลือกวิธีจ่าย และเพิ่มจังหวะที่จะเปลี่ยนใจ
 *
 * ไม่มี OTP โดยตั้งใจ — เบอร์เป็นช่องทางติดต่อ ไม่ใช่การยืนยันตัวตน
 * ตัวจริงมาเจอพนักงานที่ร้านอยู่ดี
 */
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import {
    CalendarClock,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Minus,
    Plus,
    ShoppingBag,
    Store,
    Trash2,
    Utensils,
    Zap,
} from 'lucide-vue-next'
import CartLineBody from '@/components/storefront/CartLineBody.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { money } from '@/lib/format'
import type { CartLine } from '@/composables/useGuestCart'
import type { PageProps, Product } from '@/types'

interface PaymentOption {
    value: string
    label: string
    description: string
    is_government_scheme: boolean
}

const props = defineProps<{
    lines: CartLine[]
    total: number
    /** เมนูที่เอาไปเสนอเพิ่ม — หน้าแม่คัดมาให้แล้ว */
    suggestions: Product[]
    paymentIntents: PaymentOption[]
    earliestPickupAt: string
    prepMinutes: number
    isStaff: boolean
    branchName?: string | null
    /**
     * โต๊ะที่ลูกค้านั่งอยู่ — มาจาก session ฝั่งเซิร์ฟเวอร์ ไม่ใช่ค่าที่หน้าเว็บตั้งเอง
     * ใช้แสดงผลอย่างเดียว ตอนเช็คเอาต์เซิร์ฟเวอร์อ่าน session ซ้ำอยู่ดี
     */
    table: { name: string; seats: number } | null
    staffMode?: { user_name: string; tables: Array<{ id: number; name: string; seats: number; status: string }> } | null
    /** อัตราแต้ม มาจากเซิร์ฟเวอร์ ใช้ค่าเดียวกับตอนให้แต้มจริง */
    points: { baht_per_point: number; enabled: boolean }
    processing: boolean
    errors: Record<string, string>
}>()

const emit = defineEmits<{
    close: []
    submit: []
    setQty: [key: string, qty: number]
    pick: [product: Product]
}>()

/** 'now' = ใช้เวลาปัจจุบัน / 'scheduled' = ลูกค้าเลือกเวลาเอง */
const timing = defineModel<string>('timing', { default: 'now' })
const name = defineModel<string>('name', { default: '' })
const phone = defineModel<string>('phone', { default: '' })
const pickupAt = defineModel<string>('pickupAt', { default: '' })
const paymentIntent = defineModel<string>('paymentIntent', { default: 'pay_at_store' })
const note = defineModel<string>('note', { default: '' })
const staffTableId = defineModel<number | ''>('staffTableId', { default: '' })

const page = usePage<PageProps>()
const member = computed(() => page.props.customer)

/** แถวที่กด "แก้ไข" อยู่ — เปิดปุ่มเพิ่ม/ลด/ลบ ให้เฉพาะแถวนั้น */
const editing = ref<string | null>(null)

function toggleEdit(key: string) {
    editing.value = editing.value === key ? null : key
}

const currentTable = computed(() => {
    if (props.table) return props.table
    if (props.isStaff && staffTableId.value && props.staffMode?.tables) {
        const found = props.staffMode.tables.find((t) => t.id === staffTableId.value)
        if (found) return { name: found.name, seats: found.seats }
    }
    return null
})

const itemCount = computed(() => props.lines.reduce((sum, l) => sum + l.qty, 0))

/** ค่าเริ่มต้นของช่องเวลา — เร็วที่สุดเท่าที่ครัวทำทัน */
const earliestLocal = computed(() => toLocalInput(props.earliestPickupAt))

function toLocalInput(iso: string): string {
    const d = new Date(iso)
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

if (!pickupAt.value) {
    pickupAt.value = earliestLocal.value
}

// นั่งโต๊ะอยู่ไม่มีทางเลือกอื่น บังคับให้ตรงกับที่เซิร์ฟเวอร์จะทำ
// ไม่งั้น payload อาจค้างเป็น 'scheduled' จากที่ลูกค้าเผลอกดไว้ก่อนสแกน QR
if (currentTable.value) {
    timing.value = 'now'
}

/** เลือกเวลาได้ถึงสิ้นวันนี้เท่านั้น คิวกับสต๊อกรันเป็นรายวัน */
const latestLocal = computed(() => {
    const d = new Date(props.earliestPickupAt)
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T23:59`
})

const selectedPayment = computed(() => props.paymentIntents.find((p) => p.value === paymentIntent.value))

/**
 * แต้มที่จะได้ — ประมาณการเท่านั้น
 *
 * ของจริงคิดจาก grand_total ซึ่งรวม VAT/ค่าบริการ/ปัดเศษ ที่ยังไม่รู้ตอนนี้
 * ยอดจริงจึงมากกว่าหรือเท่ากับยอดตรงนี้เสมอ แต้มที่ได้จริงจะไม่น้อยกว่าที่โชว์
 */
const pointsEarned = computed(() =>
    props.points.enabled ? Math.floor(props.total / props.points.baht_per_point) : 0,
)

const canSubmit = computed(
    () => props.lines.length > 0
        && name.value.trim().length > 0
        && phone.value.trim().length >= 9
        && !props.processing,
)
</script>

<template>
    <div class="fixed inset-0 z-40 flex flex-col bg-background">
        <header class="flex h-14 shrink-0 items-center gap-1 border-b px-2">
            <button
                type="button"
                class="grid size-10 place-items-center rounded-full transition-colors hover:bg-accent"
                aria-label="ย้อนกลับ"
                @click="emit('close')"
            >
                <ChevronLeft class="size-5" />
            </button>
            <h2 class="text-lg font-bold">ตะกร้าของฉัน</h2>
        </header>

        <!-- ══ แถบสถานะประเภทการสั่ง (ชัดเจนทั้งทานที่ร้าน และ รับที่ร้าน) ══ -->
        <div
            v-if="currentTable"
            class="border-b bg-[var(--series-1)]/10 px-4 py-3"
            role="status"
        >
            <div class="mx-auto flex max-w-2xl items-center justify-between gap-3 lg:max-w-3xl">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-[var(--series-1)] text-white shadow-xs">
                        <Utensils class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full bg-[var(--series-1)]/20 px-2 py-0.5 text-xs font-bold text-[var(--series-1)]">
                                🍽️ ทานที่ร้าน
                            </span>
                            <span class="text-xs text-muted-foreground">เสิร์ฟถึงโต๊ะ</span>
                        </div>
                        <p class="truncate text-base font-bold text-foreground">
                            โต๊ะ {{ currentTable.name }}
                            <span v-if="currentTable.seats" class="text-xs font-normal text-muted-foreground">
                                ({{ currentTable.seats }} ที่นั่ง)
                            </span>
                        </p>
                    </div>
                </div>
                <span v-if="branchName" class="hidden text-xs text-muted-foreground sm:inline-block">
                    {{ branchName }}
                </span>
            </div>
        </div>

        <div
            v-else
            class="border-b bg-amber-500/10 px-4 py-3"
            role="status"
        >
            <div class="mx-auto flex max-w-2xl items-center justify-between gap-3 lg:max-w-3xl">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-600 text-white shadow-xs">
                        <ShoppingBag class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-500/20 px-2 py-0.5 text-xs font-bold text-amber-800 dark:text-amber-300">
                                🛍️ รับที่ร้าน / ซื้อกลับบ้าน
                            </span>
                            <span class="text-xs text-muted-foreground">Takeaway</span>
                        </div>
                        <p class="truncate text-sm font-semibold text-foreground">
                            รับอาหารที่เคาน์เตอร์ร้าน
                            <span v-if="branchName" class="text-xs font-normal text-muted-foreground">({{ branchName }})</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
            <div class="mx-auto max-w-2xl lg:max-w-3xl">
                <EmptyState
                    v-if="!lines.length"
                    title="ตะกร้าว่าง"
                    description="กลับไปเลือกเมนูที่ต้องการก่อนนะครับ"
                />

                <template v-else>
                    <!-- ══ รายการในตะกร้า ══ -->
                    <div class="flex items-center justify-between gap-3 px-4 pb-2 pt-4">
                        <h3 class="text-lg font-bold">ตะกร้าของฉัน ({{ lines.length }})</h3>
                        <button
                            type="button"
                            class="flex shrink-0 items-center text-sm font-medium text-[var(--series-1)] transition-colors hover:opacity-80"
                            @click="emit('close')"
                        >
                            สั่งเพิ่ม
                            <ChevronRight class="size-4" />
                        </button>
                    </div>

                    <ul class="divide-y border-y">
                        <li v-for="line in lines" :key="line.key" class="px-4 py-3">
                            <CartLineBody
                                :qty="line.qty"
                                :name="line.name"
                                :modifier-names="line.modifier_names"
                                :note="line.note"
                                :unit-price="line.unit_price"
                            >
                                <template #actions>
                                    <button
                                        type="button"
                                        class="mt-1.5 text-sm font-medium text-[var(--series-1)]"
                                        @click="toggleEdit(line.key)"
                                    >
                                        {{ editing === line.key ? 'เสร็จแล้ว' : 'แก้ไข' }}
                                    </button>
                                </template>
                            </CartLineBody>

                            <!-- ปุ่มปรับจำนวน โผล่เฉพาะแถวที่กดแก้ไข -->
                            <div v-if="editing === line.key" class="mt-2 flex items-center gap-1 ps-10">
                                <button
                                    type="button"
                                    class="grid size-9 place-items-center rounded-full bg-muted transition-transform active:scale-90"
                                    aria-label="ลดจำนวน"
                                    @click="emit('setQty', line.key, line.qty - 1)"
                                >
                                    <Minus class="size-4" />
                                </button>
                                <span class="tabular w-10 text-center text-base">{{ line.qty }}</span>
                                <button
                                    type="button"
                                    class="grid size-9 place-items-center rounded-full bg-muted transition-transform active:scale-90"
                                    aria-label="เพิ่มจำนวน"
                                    @click="emit('setQty', line.key, line.qty + 1)"
                                >
                                    <Plus class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="ms-auto grid size-9 place-items-center rounded-full text-[var(--status-critical)] transition-colors active:bg-accent"
                                    aria-label="ลบรายการนี้"
                                    @click="emit('setQty', line.key, 0)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                        </li>
                    </ul>

                    <!-- ══ เมนูแนะนำเพิ่ม ══ -->
                    <section v-if="suggestions.length" class="pt-4">
                        <h3 class="px-4 pb-2 text-lg font-bold">คุณอาจชอบเมนูเหล่านี้</h3>

                        <ul class="flex gap-3 overflow-x-auto px-4 pb-1">
                            <li v-for="s in suggestions" :key="s.id" class="w-32 shrink-0">
                                <button type="button" class="w-full text-left" @click="emit('pick', s)">
                                    <span class="relative block overflow-hidden rounded-xl">
                                        <img
                                            v-if="s.image_path"
                                            :src="s.image_path"
                                            :alt="s.name"
                                            class="aspect-square w-full object-cover"
                                            loading="lazy"
                                        />
                                        <span
                                            v-else
                                            class="grid aspect-square w-full place-items-center bg-muted text-2xl font-bold text-muted-foreground"
                                            aria-hidden="true"
                                        >
                                            {{ s.name.trim().charAt(0) }}
                                        </span>

                                        <span
                                            class="absolute bottom-1.5 right-1.5 grid size-8 place-items-center rounded-full bg-[var(--series-1)] text-white shadow-lg"
                                            aria-hidden="true"
                                        >
                                            <Plus class="size-4" />
                                        </span>
                                    </span>

                                    <span class="mt-1.5 line-clamp-2 block text-sm font-medium">{{ s.name }}</span>
                                    <span class="tabular block text-sm font-semibold">฿{{ money(s.price) }}</span>
                                </button>
                            </li>
                        </ul>
                    </section>

                    <!-- ══ ยอดรวม ══ -->
                    <div class="mt-4 flex items-center justify-between gap-3 border-y bg-card px-4 py-4">
                        <span class="text-lg font-bold">ยอดรวมทั้งหมด</span>
                        <span class="tabular text-lg font-bold">฿{{ money(total) }}</span>
                    </div>

                    <p class="flex items-center gap-2 px-4 py-3 text-sm text-muted-foreground">
                        <Clock class="size-4 shrink-0" />
                        ร้านจะใช้เวลาจัดเตรียมคำสั่งซื้อ <b class="text-foreground">~{{ prepMinutes }} นาที</b>
                    </p>

                    <!-- ══ สมาชิก + แต้ม ══ -->
                    <div v-if="member" class="border-y bg-card">
                        <div class="px-4 py-3">
                            <p class="text-base">
                                {{ member.name }}
                                <span class="text-muted-foreground">· {{ member.points }} แต้ม</span>
                            </p>
                        </div>
                        <p
                            v-if="points.enabled"
                            class="mx-4 mb-3 rounded-lg bg-[var(--series-1)]/10 px-3 py-2 text-sm text-[var(--series-1)]"
                        >
                            คุณจะได้รับอย่างน้อย {{ pointsEarned }} คะแนนจากคำสั่งซื้อนี้
                            <span class="block text-xs text-muted-foreground">
                                ทุก {{ points.baht_per_point }} บาท = 1 คะแนน · คิดจากยอดสุทธิตอนชำระเงิน
                            </span>
                        </p>
                    </div>

                    <div class="h-2 bg-muted/60" />

                    <!-- ══ รูปแบบการสั่งอาหาร ══ -->
                    <section class="px-4 pt-4">
                        <h3 class="pb-2 text-lg font-bold">รูปแบบการสั่งอาหาร</h3>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <!-- ทานที่ร้าน -->
                            <div
                                class="relative flex flex-col justify-between rounded-2xl border-2 p-3.5 transition-all"
                                :class="
                                    currentTable
                                        ? 'border-[var(--series-1)] bg-[var(--series-1)]/8 shadow-xs ring-1 ring-[var(--series-1)]/30'
                                        : 'border-border bg-card opacity-65'
                                "
                            >
                                <div class="flex items-start gap-3">
                                    <span
                                        class="grid size-9 shrink-0 place-items-center rounded-xl transition-colors"
                                        :class="
                                            currentTable
                                                ? 'bg-[var(--series-1)] text-white'
                                                : 'bg-muted text-muted-foreground'
                                        "
                                    >
                                        <Utensils class="size-4.5" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-1">
                                            <span class="text-base font-bold text-foreground">ทานที่ร้าน</span>
                                            <span
                                                v-if="currentTable"
                                                class="inline-flex items-center rounded-full bg-[var(--series-1)]/20 px-2 py-0.5 text-[11px] font-bold text-[var(--series-1)]"
                                            >
                                                ตัวเลือกปัจจุบัน
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs text-muted-foreground">
                                            <template v-if="currentTable">
                                                เสิร์ฟที่ <b class="text-foreground">โต๊ะ {{ currentTable.name }}</b>
                                                <span v-if="currentTable.seats"> ({{ currentTable.seats }} ที่นั่ง)</span>
                                            </template>
                                            <template v-else>
                                                สำหรับลูกค้านั่งทานในร้าน
                                            </template>
                                        </p>
                                    </div>
                                </div>

                                <!-- กรณีเป็นพนักงาน สามารถเลือกโต๊ะได้ในหน้านี้ -->
                                <div v-if="isStaff && staffMode?.tables?.length" class="mt-3 border-t border-border/50 pt-2">
                                    <label for="cart-staff-table" class="mb-1 block text-xs font-medium text-muted-foreground">
                                        เลือกโต๊ะสำหรับออเดอร์นี้:
                                    </label>
                                    <select
                                        id="cart-staff-table"
                                        v-model="staffTableId"
                                        class="h-9 w-full rounded-lg border bg-background px-2.5 text-xs font-medium text-foreground focus:outline-none focus:ring-1 focus:ring-[var(--series-1)]"
                                    >
                                        <option :value="''">-- ไม่ระบุโต๊ะ (สั่งกลับบ้าน) --</option>
                                        <option
                                            v-for="t in staffMode.tables"
                                            :key="t.id"
                                            :value="t.id"
                                        >
                                            โต๊ะ {{ t.name }} ({{ t.seats }} ที่นั่ง)
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <!-- รับที่ร้าน / กลับบ้าน -->
                            <div
                                class="relative flex flex-col justify-between rounded-2xl border-2 p-3.5 transition-all"
                                :class="
                                    !currentTable
                                        ? 'border-amber-500 bg-amber-500/8 shadow-xs ring-1 ring-amber-500/30'
                                        : 'border-border bg-card opacity-65'
                                "
                            >
                                <div class="flex items-start gap-3">
                                    <span
                                        class="grid size-9 shrink-0 place-items-center rounded-xl transition-colors"
                                        :class="
                                            !currentTable
                                                ? 'bg-amber-600 text-white'
                                                : 'bg-muted text-muted-foreground'
                                        "
                                    >
                                        <ShoppingBag class="size-4.5" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-1">
                                            <span class="text-base font-bold text-foreground">รับที่ร้าน / กลับบ้าน</span>
                                            <span
                                                v-if="!currentTable"
                                                class="inline-flex items-center rounded-full bg-amber-500/20 px-2 py-0.5 text-[11px] font-bold text-amber-800 dark:text-amber-300"
                                            >
                                                ตัวเลือกปัจจุบัน
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs text-muted-foreground">
                                            รับสินค้าที่เคาน์เตอร์ร้าน
                                        </p>
                                    </div>
                                </div>

                                <div v-if="isStaff && currentTable" class="mt-3 border-t border-border/50 pt-2">
                                    <button
                                        type="button"
                                        class="text-xs font-medium text-amber-700 hover:underline dark:text-amber-400"
                                        @click="staffTableId = ''"
                                    >
                                        เปลี่ยนเป็นสั่งกลับบ้าน (ไม่ผูกโต๊ะ)
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ══ เวลารับอาหาร ══ -->
                    <!-- นั่งอยู่ที่โต๊ะแล้ว ครัวทำทันทีและเสิร์ฟถึงโต๊ะ -->
                    <section v-if="currentTable" class="px-4 pt-4">
                        <h3 class="pb-2 text-lg font-bold">เวลาเสิร์ฟอาหาร</h3>

                        <div class="flex items-center gap-3 rounded-xl border-2 border-[var(--series-1)] bg-[var(--series-1)]/8 p-3">
                            <Utensils class="size-5 shrink-0 text-[var(--series-1)]" />
                            <p class="min-w-0 text-sm">
                                ยกไปเสิร์ฟที่ <span class="text-base font-bold">{{ currentTable.name }}</span>
                                <span class="block text-xs text-muted-foreground">
                                    ครัวเริ่มทำทันที พร้อมใน ~{{ prepMinutes }} นาที
                                </span>
                            </p>
                        </div>
                    </section>

                    <section v-else class="px-4 pt-4">
                        <h3 class="pb-2 text-lg font-bold">รับอาหารเมื่อไหร่</h3>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <button
                                type="button"
                                class="flex items-center gap-3 rounded-xl border-2 p-3 text-left transition-colors"
                                :class="
                                    timing === 'now'
                                        ? 'border-[var(--series-1)] bg-[var(--series-1)]/8'
                                        : 'border-border hover:bg-accent'
                                "
                                @click="timing = 'now'"
                            >
                                <Zap
                                    class="size-5 shrink-0"
                                    :class="timing === 'now' ? 'text-[var(--series-1)]' : 'text-muted-foreground'"
                                />
                                <span class="min-w-0">
                                    <span class="block text-base font-medium">สั่งตอนนี้</span>
                                    <span class="block text-xs text-muted-foreground">
                                        ครัวเริ่มทำทันที พร้อมใน ~{{ prepMinutes }} นาที
                                    </span>
                                </span>
                            </button>

                            <button
                                type="button"
                                class="flex items-center gap-3 rounded-xl border-2 p-3 text-left transition-colors"
                                :class="
                                    timing === 'scheduled'
                                        ? 'border-[var(--series-1)] bg-[var(--series-1)]/8'
                                        : 'border-border hover:bg-accent'
                                "
                                @click="timing = 'scheduled'"
                            >
                                <CalendarClock
                                    class="size-5 shrink-0"
                                    :class="timing === 'scheduled' ? 'text-[var(--series-1)]' : 'text-muted-foreground'"
                                />
                                <span class="min-w-0">
                                    <span class="block text-base font-medium">สั่งล่วงหน้า</span>
                                    <span class="block text-xs text-muted-foreground">เลือกเวลาที่จะมารับได้เอง</span>
                                </span>
                            </button>
                        </div>

                        <div v-if="timing === 'scheduled'" class="mt-3 space-y-1">
                            <Label for="pickup">เวลาที่จะมารับ</Label>
                            <Input
                                id="pickup"
                                v-model="pickupAt"
                                type="datetime-local"
                                class="h-12 text-base"
                                :min="earliestLocal"
                                :max="latestLocal"
                            />
                            <p v-if="errors.pickup_at" class="text-xs text-[var(--status-critical)]">
                                {{ errors.pickup_at }}
                            </p>
                            <p v-else class="text-xs text-muted-foreground">
                                เลือกได้เฉพาะภายในวันนี้ และไม่เร็วกว่าเวลาที่ครัวทำทัน
                            </p>
                        </div>

                        <p v-else class="mt-2 text-sm text-muted-foreground">
                            ระบบจะใช้เวลาปัจจุบันให้อัตโนมัติ
                        </p>
                    </section>

                    <!-- ══ ข้อมูลติดต่อ ══ -->
                    <section class="space-y-3 px-4 pt-5">
                        <h3 class="text-lg font-bold">
                            ข้อมูลติดต่อ<span class="text-[var(--status-critical)]">*</span>
                        </h3>

                        <div class="space-y-1">
                            <Label for="cname">ชื่อ</Label>
                            <Input id="cname" v-model="name" class="h-12 text-base" placeholder="ชื่อที่ให้เรียกตอนมารับ" />
                            <p v-if="errors.name" class="text-xs text-[var(--status-critical)]">{{ errors.name }}</p>
                        </div>

                        <div class="space-y-1">
                            <Label for="cphone">เบอร์โทรศัพท์</Label>
                            <Input
                                id="cphone"
                                v-model="phone"
                                type="tel"
                                inputmode="tel"
                                class="h-12 text-base"
                                placeholder="08xxxxxxxx"
                            />
                            <p v-if="errors.phone" class="text-xs text-[var(--status-critical)]">{{ errors.phone }}</p>
                            <p v-else class="text-xs text-muted-foreground">กรอกเบอร์โทรศัพท์เพื่อให้ร้านติดต่อคุณได้</p>
                        </div>

                        <div class="space-y-1">
                            <Label for="onote">หมายเหตุถึงร้าน</Label>
                            <Input id="onote" v-model="note" class="h-12 text-base" placeholder="เช่น ขอช้อนส้อมเพิ่ม 2 ชุด" />
                        </div>
                    </section>

                    <!-- ══ วิธีการชำระเงิน ══ -->
                    <section class="px-4 pb-4 pt-5">
                        <div v-if="currentTable" class="rounded-2xl border-2 border-[var(--series-1)]/30 bg-[var(--series-1)]/8 p-4">
                            <div class="flex items-start gap-3">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-[var(--series-1)] text-white shadow-xs">
                                    <Utensils class="size-5" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <h3 class="text-base font-bold text-foreground">ชำระเงินรวมทีเดียวเมื่อทานเสร็จ</h3>
                                        <span class="rounded-full bg-[var(--series-1)]/20 px-2 py-0.5 text-[11px] font-bold text-[var(--series-1)]">
                                            สั่งเพิ่มได้เรื่อยๆ
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs leading-relaxed text-muted-foreground">
                                        รายการที่สั่งรอบนี้จะถูกบันทึกสะสมรวมไว้ที่ <b class="text-foreground">โต๊ะ {{ currentTable.name }}</b> ท่านสามารถกลับไปเลือกสั่งอาหารและเครื่องดื่มเพิ่มได้ตลอดมื้อ โดยไม่ต้องทำรายการชำระเงินทีละรอบ
                                    </p>
                                    <p class="mt-2 flex items-center gap-1 text-xs font-semibold text-[var(--series-1)]">
                                        <CheckCircle2 class="size-3.5" />
                                        เรียกพนักงานเช็คบิล หรือชำระเงินที่เคาน์เตอร์รวมครั้งเดียวเมื่อทานเสร็จ
                                    </p>
                                </div>
                            </div>
                        </div>

                        <template v-else>
                            <h3 class="pb-2 text-lg font-bold">วิธีการชำระเงิน</h3>

                            <ul class="divide-y rounded-xl border">
                                <li v-for="option in paymentIntents" :key="option.value">
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-3 p-3.5 text-left transition-colors active:bg-accent"
                                        @click="paymentIntent = option.value"
                                    >
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-base font-medium">{{ option.label }}</span>
                                            <span class="block text-xs text-muted-foreground">{{ option.description }}</span>
                                        </span>

                                        <span
                                            class="grid size-6 shrink-0 place-items-center rounded-full border-2 transition-colors"
                                            :class="
                                                paymentIntent === option.value
                                                    ? 'border-[var(--series-1)] bg-[var(--series-1)]'
                                                    : 'border-input'
                                            "
                                        >
                                            <span
                                                v-if="paymentIntent === option.value"
                                                class="size-2.5 rounded-full bg-white"
                                            />
                                        </span>
                                    </button>
                                </li>
                            </ul>

                            <p
                                v-if="selectedPayment?.is_government_scheme"
                                class="mt-2 rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground"
                            >
                                ระบบจะแจ้งให้พนักงานเตรียมไว้ — เงื่อนไขและสิทธิ์ของโครงการเป็นไปตามที่แอปของโครงการกำหนด
                                ระบบนี้ไม่ได้ตรวจสิทธิ์ให้ และไม่ได้ตัดเงินจากโครงการเอง
                            </p>
                        </template>

                        <p
                            v-if="isStaff"
                            class="mt-3 rounded-lg border border-[var(--series-1)]/30 bg-[var(--series-1)]/10 px-3 py-2 text-xs"
                        >
                            คุณกำลังสั่งแทนลูกค้า — ออเดอร์นี้จะเข้าครัวทันทีโดยไม่ต้องรอร้านกดรับ
                        </p>
                    </section>
                </template>
            </div>
        </div>

        <!-- ══ ปุ่มกดยืนยันออเดอร์ ══ -->
        <footer
            v-if="lines.length"
            class="shrink-0 border-t bg-background px-4 pt-3"
            :style="{ paddingBottom: 'calc(0.5rem + env(safe-area-inset-bottom, 0px))' }"
        >
            <div class="mx-auto max-w-2xl lg:max-w-3xl">
                <button
                    type="button"
                    class="h-14 w-full rounded-full text-base font-semibold transition-colors shadow-sm"
                    :class="
                        canSubmit
                            ? 'bg-[var(--series-1)] text-white active:brightness-110'
                            : 'cursor-not-allowed bg-muted text-muted-foreground'
                    "
                    :disabled="!canSubmit"
                    @click="emit('submit')"
                >
                    <template v-if="processing">กำลังส่งรายการ…</template>
                    <template v-else-if="!name.trim() || phone.trim().length < 9">กรอกชื่อและเบอร์โทรก่อน</template>
                    <template v-else-if="currentTable">
                        ส่งรายการเข้าครัว (โต๊ะ {{ currentTable.name }}) · ฿{{ money(total) }}
                    </template>
                    <template v-else>
                        ยืนยันสั่งซื้อ (รับที่ร้าน) · ฿{{ money(total) }}
                    </template>
                </button>

                <p class="px-2 pb-1 pt-2 text-center text-xs text-muted-foreground">
                    <template v-if="currentTable">
                        ✨ สั่งเพิ่มได้ตลอดมื้อ · รายการจะรวมไว้ในบิลโต๊ะ {{ currentTable.name }} และชำระเงินทีเดียวเมื่อทานเสร็จ ({{ itemCount }} รายการ)
                    </template>
                    <template v-else>
                        เมื่อยืนยันแล้ว คำสั่งซื้อจะถูกส่งไปยังร้าน · {{ itemCount }} รายการ
                    </template>
                </p>
            </div>
        </footer>
    </div>
</template>
