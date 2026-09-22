<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { BadgeCheck, BellRing, Check, ChevronRight, Clock, MapPin, Phone, Plus, Receipt, ShoppingBag, Store, UserRound, Users, Utensils } from 'lucide-vue-next'
import ProductSheet from '@/components/guest/ProductSheet.vue'
import ProductThumb from '@/components/storefront/ProductThumb.vue'
import DietBadges from '@/components/storefront/DietBadges.vue'
import NicknameSheet from '@/components/storefront/NicknameSheet.vue'
import StaffBar from '@/components/storefront/StaffBar.vue'
import CartSheet from '@/components/storefront/CartSheet.vue'
import CategoryTabs from '@/components/storefront/CategoryTabs.vue'
import MemberSheet from '@/components/storefront/MemberSheet.vue'
import TableBanner from '@/components/storefront/TableBanner.vue'
import TableBillSheet from '@/components/storefront/TableBillSheet.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useGuestCart } from '@/composables/useGuestCart'
import { usePolling } from '@/composables/usePolling'
import { money, number } from '@/lib/format'
import { useIdempotencyKey } from '@/lib/idempotency'
import type { Category, PageProps, Product, TableBill } from '@/types'

interface BranchInfo {
    code: string
    name: string
    intro: string | null
    phone: string | null
    address: string | null
    cover_path: string | null
    logo_path: string | null
    open_time: string
    close_time: string
    is_open_now: boolean
    is_taking_orders: boolean
    prep_minutes: number
    earliest_pickup_at: string
}

/** กลุ่มโปรโมทที่ร้านตั้งไว้ในหลังบ้าน */
interface Promo {
    title: string
    featured: Product | null
    items: Product[]
}

const props = defineProps<{
    branch: BranchInfo
    /** โต๊ะที่ลูกค้าสแกน QR มา — null = สั่งกลับบ้าน/มารับเอง */
    table: { name: string; seats: number } | null
    /** สแกน QR โต๊ะมาแล้วแต่โต๊ะยังไม่ถูกเปิด — เก็บแค่ชื่อไว้บอกลูกค้า */
    pendingTable: string | null
    /** บิลที่โต๊ะนี้เปิดค้างอยู่ — null = ยังไม่ได้สั่งอะไร หรือไม่ได้นั่งโต๊ะ */
    tableBill: TableBill | null
    /** เคยกดเรียกพนักงานมาเปิดโต๊ะไปแล้วหรือยัง */
    openRequested: boolean
    /** ชื่อเล่นที่ลูกค้าตั้งไว้ — null = ยังไม่เคยตั้ง */
    guestName: string | null
    /** จำนวนร้านที่เปิดอยู่ มีร้านเดียวก็ไม่ต้องโชว์ปุ่มเปลี่ยนร้าน */
    stationCount: number
    categories: Category[]
    products: Product[]
    queue: { count: number; label: string }
    points: { baht_per_point: number; enabled: boolean }
    promo: Promo
    paymentIntents: Array<{ value: string; label: string; description: string; is_government_scheme: boolean }>
    staffMode: { user_name: string; tables: Array<{ id: number; name: string; seats: number; status: string }> } | null
    benefit: {
        enabled: boolean
        cap: number
        used: number
        remaining: number | null
        unlimited: boolean
        period: string
        excludes_alcohol: boolean
    } | null
}>()

const page = usePage<PageProps>()
const member = computed(() => page.props.customer)

const cart = useGuestCart(`shop.${props.branch.code}`)

const search = ref('')
const activeCategory = ref<number | null>(null)
const showMember = ref(false)
const showBill = ref(false)
const callingBill = ref(false)
const requestingOpen = ref(false)
const askNickname = ref(false)

/*
| ถามชื่อเล่นครั้งเดียวต่อการเปิดหน้า
|
| ถ้าถามซ้ำทุกครั้งที่หยิบของ คนที่ตั้งใจข้ามจะโดนถามรัวจนรำคาญ
| ธงนี้จึงจำว่า "เคยถามไปแล้ว" แยกจาก "ตั้งชื่อแล้ว"
*/
const nicknameAsked = ref(props.guestName !== null)

/*
| เคยกดเรียกไปแล้วไหม
|
| เริ่มจากค่าที่เซิร์ฟเวอร์ส่งมา แล้วอัปเดตเองตอนกดสำเร็จ เพื่อให้ปุ่มเปลี่ยนทันที
| โดยไม่ต้องรอโหลดหน้าใหม่ — ลูกค้าที่กดแล้วไม่เห็นอะไรเปลี่ยนจะกดซ้ำแน่นอน
*/
const openRequestSent = ref(props.openRequested)

// สั่งซ้ำเพราะเน็ตมือถือสะดุด = อาหารซ้ำทั้งรอบ กันด้วยคีย์เดียวกับฝั่งพนักงาน
const { rotate: rotateOrderKey, headers: orderHeaders } = useIdempotencyKey()

/*
| บิลของโต๊ะต้องสดกว่าหน้าอื่น เพราะสถานะเปลี่ยนที่ครัว ไม่ได้เปลี่ยนที่มือลูกค้า
| ลูกค้าจึงไม่มีทางรู้ว่าต้องรีเฟรชเมื่อไหร่ ดึงเองทุก 15 วินาทีพอ
| (ถี่กว่านี้ไม่ได้ช่วยอะไร อาหารไม่ได้เสร็จทุก 5 วินาที แต่เปลืองแบตลูกค้า)
*/
const { data: liveBill, fetchNow: refreshBill } = usePolling<{ bill: TableBill | null }>(
    () => '/order/bill',
    15000,
    () => props.table !== null,
)

const bill = computed<TableBill | null>(() =>
    liveBill.value ? liveBill.value.bill : props.tableBill,
)

/** แยกเป็น computed เพื่อให้ v-if แคบชนิดให้ ไม่ต้องเช็ค null ซ้ำในเทมเพลต */
const memberSheet = computed(() => (showMember.value ? member.value : null))

/** id ของ element หมวดบนหน้า — แถบหมวดใช้ตัวนี้หาเป้าหมายตอนเลื่อน */
const sectionId = (categoryId: number) => `cat-${categoryId}`
const sheetProduct = ref<Product | null>(null)
const step = ref<'menu' | 'cart'>('menu')
const processing = ref(false)
const errors = ref<Record<string, string>>({})

// ฟอร์มเช็คเอาต์
const timing = ref<'now' | 'scheduled'>('now')
const name = ref('')
const phone = ref('')
const pickupAt = ref('')
const paymentIntent = ref('pay_at_store')
const note = ref('')
const staffTableId = ref<number | ''>('')

const isStaff = computed(() => props.staffMode !== null)

// พนักงานสั่งแทนได้แม้นอกเวลาทำการ เพราะเขายืนอยู่หน้าร้านจริง
const canOrder = computed(() => props.branch.is_taking_orders || isStaff.value)

/** กำลังค้นหาอยู่ — ตอนนั้นซ่อนกลุ่มโปรโมทไว้ก่อน ไม่งั้นรกและหาไม่เจอ */
const isBrowsing = computed(() => search.value.trim() !== '')

/** แยกเป็น computed เพื่อให้ v-if แคบชนิดให้ ไม่ต้องเช็ค null ซ้ำในเทมเพลต */
const featured = computed(() => (isBrowsing.value ? null : props.promo.featured))
const promoItems = computed(() => (isBrowsing.value ? [] : props.promo.items))

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase()
    if (!term) return props.products

    return props.products.filter((p) => p.name.toLowerCase().includes(term))
})

const grouped = computed(() =>
    props.categories
        .map((c) => ({ category: c, items: filtered.value.filter((p) => p.category_id === c.id) }))
        .filter((g) => g.items.length > 0),
)

/** ราคาที่ลูกค้าจ่ายจริง — พนักงานที่ได้สิทธิ์เห็นราคาพนักงาน */
function effectivePrice(p: Product): number {
    return p.staff_price != null && p.staff_price < p.price ? Number(p.staff_price) : Number(p.price)
}

function hasStaffPrice(p: Product): boolean {
    return p.staff_price != null && p.staff_price < p.price
}

/** 0908256043 -> 090***043 ปิดเลขกลางไว้ เผื่อมีคนยืนดูจอข้าง ๆ */
function maskPhone(phone: string | null): string {
    if (!phone) return ''
    const digits = phone.replace(/\D/g, '')
    return digits.length < 9 ? digits : `${digits.slice(0, 3)}***${digits.slice(-3)}`
}

/**
 * เมนูที่เอาไปเสนอเพิ่มในตะกร้า
 *
 * เอาที่ร้านโปรโมทไว้ก่อน แล้วค่อยไล่จากของถูกไปแพง เพราะของแถมท้ายบิล
 * อย่างน้ำเปล่า/ข้าวเปล่า คนกดเพิ่มง่ายกว่าเมนูจานหลักอีกจาน
 */
const suggestions = computed(() => {
    const inCart = new Set(cart.lines.value.map((l) => l.product_id))
    const promoIds = new Set(props.promo.items.map((p) => p.id))

    return props.products
        .filter((p) => !inCart.has(p.id))
        .sort((a, b) => {
            const promo = Number(promoIds.has(b.id)) - Number(promoIds.has(a.id))
            return promo !== 0 ? promo : Number(a.price) - Number(b.price)
        })
        .slice(0, 8)
})

function openProduct(p: Product) {
    if (!canOrder.value) return
    sheetProduct.value = p
}

function onAdd(
    product: Product,
    qty: number,
    modifierIds: number[],
    modifierNames: string[],
    itemNote: string | null,
    unitPrice: number,
) {
    cart.add(product, qty, modifierIds, modifierNames, itemNote, unitPrice)
    sheetProduct.value = null

    // ถามหลังหยิบของชิ้นแรก ตอนที่เหตุผลของคำถามชัดแล้ว
    // และถามเฉพาะคนที่นั่งโต๊ะ คนสั่งกลับบ้านไม่ต้องใช้ชื่อเล่น
    if (props.table && ! nicknameAsked.value) {
        nicknameAsked.value = true
        askNickname.value = true
    }
}

function requestOpenTable() {
    if (requestingOpen.value || openRequestSent.value) return

    requestingOpen.value = true

    router.post(
        '/order/table/open-request',
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (openRequestSent.value = true),
            onFinish: () => (requestingOpen.value = false),
        },
    )
}

function callForBill() {
    callingBill.value = true

    router.post(
        '/order/bill/call',
        {},
        {
            preserveScroll: true,
            preserveState: true,
            // props ใหม่มาจาก Inertia ก็จริง แต่ค่าที่ poll ไว้ยังเป็นของเก่า
            // ถ้าไม่ดึงซ้ำ ปุ่มจะไม่เปลี่ยนเป็น "แจ้งแล้ว" จนกว่าจะครบรอบถัดไป
            onSuccess: () => refreshBill(),
            onFinish: () => (callingBill.value = false),
        },
    )
}

function submit() {
    processing.value = true
    errors.value = {}

    router.post(
        '/order/checkout',
        {
            branch_code: props.branch.code,
            timing: timing.value,
            name: name.value,
            phone: phone.value,
            pickup_at: timing.value === 'scheduled' ? pickupAt.value || null : null,
            payment_intent: paymentIntent.value,
            note: note.value || null,
            dining_table_id: isStaff.value && staffTableId.value ? staffTableId.value : null,
            lines: cart.payload.value,
        },
        {
            headers: orderHeaders(),
            onSuccess: () => {
                cart.clear()
                rotateOrderKey()
            },
            onError: (formErrors) => {
                errors.value = formErrors as Record<string, string>
                step.value = 'cart'
            },
            onFinish: () => (processing.value = false),
        },
    )
}
</script>

<template>
    <Head :title="branch.name" />

    <div class="min-h-dvh bg-muted/40" :class="bill ? 'pb-48' : 'pb-28'">
        <StaffBar
            v-if="staffMode"
            v-model:table-id="staffTableId"
            :user-name="staffMode.user_name"
            :tables="staffMode.tables"
        />

        <!-- ══ แถบบอกว่ากำลังสั่งจากร้านไหน ══ -->
        <!-- ต้องเห็นตลอด ไม่ใช่แค่ตอนเลือก ไม่งั้นลูกค้าสั่งผิดร้านแล้วไปรู้ตอนไปรับ -->
        <div v-if="stationCount > 1" class="border-b bg-card/80 backdrop-blur">
            <div class="mx-auto flex max-w-2xl items-center gap-2 px-4 py-2 text-sm lg:max-w-5xl">
                <Store class="size-4 shrink-0 text-muted-foreground" />

                <span class="min-w-0 flex-1 truncate font-semibold">{{ branch.name }}</span>

                <Link
                    href="/order/stations"
                    class="shrink-0 rounded-md px-2 py-1 text-xs text-[var(--series-1)] transition-colors hover:bg-accent"
                >
                    เปลี่ยนร้าน
                </Link>
            </div>
        </div>

        <!-- ══ โต๊ะที่นั่งอยู่ ══ -->
        <!-- แยกออกมาเป็นการ์ดของตัวเอง ไม่ปนกับชื่อร้าน เพราะเป็นคนละเรื่องกัน -->
        <div v-if="table || pendingTable" class="mx-auto max-w-2xl px-4 pt-3 lg:max-w-5xl">
            <TableBanner
                v-if="table"
                :table-name="table.name"
                :seats="table.seats"
                :branch-name="branch.name"
            />
            <TableBanner v-else :table-name="pendingTable!" variant="blocked">
                <template #action>
                    <!-- แจ้งแล้วไม่ต้องให้กดได้อีก กดซ้ำไม่ได้ทำให้พนักงานมาเร็วขึ้น -->
                    <Button
                        v-if="openRequestSent"
                        variant="outline"
                        size="lg"
                        class="w-full"
                        disabled
                    >
                        <Check />
                        แจ้งพนักงานแล้ว กำลังมาเปิดโต๊ะให้
                    </Button>
                    <Button
                        v-else
                        variant="brand"
                        size="lg"
                        class="w-full"
                        :disabled="requestingOpen"
                        :aria-busy="requestingOpen"
                        @click="requestOpenTable"
                    >
                        <Spinner v-if="requestingOpen" class="size-4" />
                        <BellRing v-else />
                        {{ requestingOpen ? 'กำลังแจ้ง…' : 'เรียกพนักงานมาเปิดโต๊ะ' }}
                    </Button>
                </template>
            </TableBanner>
        </div>

        <!-- ══ หัวร้าน ══ -->
        <header class="bg-card">
            <div
                class="aspect-[16/7] w-full bg-cover bg-center sm:aspect-[16/5] lg:aspect-[16/4] xl:aspect-[21/5]"
                :style="
                    branch.cover_path
                        ? { backgroundImage: `url(${branch.cover_path})` }
                        : { background: 'linear-gradient(135deg, var(--series-1), var(--series-7))' }
                "
            />

            <div class="mx-auto max-w-2xl px-4 pb-3 lg:max-w-5xl">
                <!-- โลโก้วงกลมคร่อมขอบล่างของรูปปก -->
                <div class="flex justify-center">
                    <img
                        v-if="branch.logo_path"
                        :src="branch.logo_path"
                        :alt="branch.name"
                        class="-mt-12 size-24 rounded-full border-4 border-card bg-card object-cover shadow-md"
                    />
                    <div
                        v-else
                        class="-mt-12 grid size-24 place-items-center rounded-full border-4 border-card bg-muted text-3xl font-bold text-muted-foreground shadow-md"
                        aria-hidden="true"
                    >
                        {{ branch.name.trim().charAt(0) }}
                    </div>
                </div>

                <h1 class="mt-2 text-center text-2xl font-bold">{{ branch.name }}</h1>
                <p v-if="branch.intro" class="mt-1 text-center text-sm text-muted-foreground">{{ branch.intro }}</p>

                <!-- ป้ายสถานะความพร้อม -->
                <div class="mt-2 flex justify-center">
                    <span
                        class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-base font-medium"
                        :class="
                            branch.is_open_now
                                ? 'text-foreground'
                                : 'border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 text-[var(--status-critical)]'
                        "
                    >
                        <Users class="size-4 shrink-0" />
                        {{ branch.is_open_now ? queue.label : 'ร้านปิดอยู่' }}
                    </span>
                </div>

                <ul class="mt-3 space-y-1 text-xs text-muted-foreground">
                    <li class="flex items-center gap-1.5">
                        <Clock class="size-3.5 shrink-0" />
                        เปิด {{ branch.open_time }} – {{ branch.close_time }} · ครัวใช้เวลาราว {{ branch.prep_minutes }} นาที
                    </li>
                    <li v-if="branch.address" class="flex items-center gap-1.5">
                        <MapPin class="size-3.5 shrink-0" />
                        {{ branch.address }}
                    </li>
                    <li v-if="branch.phone" class="flex items-center gap-1.5">
                        <Phone class="size-3.5 shrink-0" />
                        <a :href="`tel:${branch.phone}`" class="underline-offset-2 hover:underline">{{ branch.phone }}</a>
                    </li>
                </ul>

                <p
                    v-if="!canOrder"
                    class="mt-3 rounded-lg border border-[var(--status-warning)]/30 bg-[var(--status-warning)]/10 px-3 py-2 text-xs text-[var(--status-warning)]"
                >
                    ตอนนี้ร้านปิดรับออเดอร์ล่วงหน้า ดูเมนูได้ แต่ยังสั่งไม่ได้ครับ
                </p>

                <!-- วงเงินสวัสดิการคงเหลือ -->
                <p
                    v-if="benefit && !benefit.unlimited"
                    class="mt-2 rounded-lg border border-[var(--series-1)]/30 bg-[var(--series-1)]/10 px-3 py-2 text-xs text-[var(--series-1)]"
                >
                    สิทธิ์พนักงานเดือน {{ benefit.period }} — เหลือ {{ money(benefit.remaining ?? 0) }} บาท
                    <span v-if="benefit.excludes_alcohol" class="block text-muted-foreground">
                        (ไม่รวมเครื่องดื่มแอลกอฮอล์)
                    </span>
                </p>
            </div>
        </header>

        <!-- ══ แถบสมาชิก ══ -->
        <button
            v-if="member"
            type="button"
            class="flex w-full items-center gap-2 border-y bg-card px-4 py-3 text-left transition-colors active:bg-accent"
            @click="showMember = true"
        >
            <span class="min-w-0 flex-1 truncate text-base">
                {{ member.name }}
                <span class="tabular text-muted-foreground">({{ maskPhone(member.phone) }})</span>
            </span>
            <BadgeCheck class="size-5 shrink-0 text-[var(--status-good)]" aria-hidden="true" />
            <span class="flex shrink-0 items-center text-sm text-muted-foreground">
                รายละเอียด
                <ChevronRight class="size-4" />
            </span>
        </button>

        <div v-else class="flex items-center gap-2 border-y bg-card px-4 py-3">
            <UserRound class="size-5 shrink-0 text-muted-foreground" />
            <span class="min-w-0 flex-1 text-sm text-muted-foreground">
                เข้าสู่ระบบเพื่อสะสมแต้มและใช้สิทธิ์พนักงานองค์กร
            </span>
            <Link href="/order/login" class="shrink-0 text-sm font-medium text-[var(--series-1)] hover:underline">
                เข้าสู่ระบบ
            </Link>
        </div>

        <!-- ══ แถบหมวดหมู่ เลื่อนตามอัตโนมัติ ══ -->
        <CategoryTabs
            v-model:active="activeCategory"
            v-model:search="search"
            :categories="grouped.map((g) => g.category)"
            :section-id="sectionId"
        >
            <!-- เลื่อนดูเมนูไปไกลแค่ไหน เลขโต๊ะก็ยังค้างอยู่บนจอ -->
            <template v-if="table" #lead>
                <TableBanner :table-name="table.name" variant="strip" />
            </template>
        </CategoryTabs>

        <div class="mx-auto max-w-2xl lg:max-w-5xl">
            <!-- ══ สินค้าเด่น ══ -->
            <button
                v-if="featured"
                type="button"
                class="relative block w-full overflow-hidden text-left disabled:opacity-60"
                :disabled="!canOrder"
                @click="openProduct(featured)"
            >
                <img
                    v-if="featured.image_path"
                    :src="featured.image_path"
                    :alt="featured.name"
                    class="h-52 w-full object-cover sm:h-64 lg:h-80"
                />
                <div
                    v-else
                    class="h-52 w-full sm:h-64 lg:h-80"
                    :style="{ background: 'linear-gradient(135deg, var(--series-1), var(--series-7))' }"
                />

                <!-- ไล่เฉดดำเพื่อให้ตัวหนังสือบนรูปอ่านออกทุกรูป -->
                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-4 pt-12">
                    <span
                        v-if="featured.promo_label"
                        class="mb-1 inline-block rounded-full bg-[var(--status-good)] px-2.5 py-0.5 text-xs font-medium text-white"
                    >
                        {{ featured.promo_label }}
                    </span>
                    <p class="truncate text-lg font-bold text-white">{{ featured.name }}</p>
                    <p class="tabular text-base font-semibold text-white">
                        {{ money(effectivePrice(featured)) }} ฿
                        <span
                            v-if="hasStaffPrice(featured)"
                            class="ms-1 text-sm font-normal text-white/70 line-through"
                        >
                            {{ money(featured.price) }}
                        </span>
                    </p>
                </div>
            </button>

            <!-- ══ ตะแกรงโปรโมท 2 คอลัมน์ ══ -->
            <section v-if="promoItems.length" class="px-4 pt-5">
                <h2 class="mb-3 text-xl font-bold">{{ promo.title }}</h2>

                <ul class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <li v-for="p in promoItems" :key="p.id">
                        <button
                            type="button"
                            class="w-full text-left disabled:opacity-60"
                            :disabled="!canOrder"
                            @click="openProduct(p)"
                        >
                            <div class="relative overflow-hidden rounded-xl">
                                <img
                                    v-if="p.image_path"
                                    :src="p.image_path"
                                    :alt="p.name"
                                    class="aspect-square w-full object-cover"
                                    loading="lazy"
                                />
                                <div
                                    v-else
                                    class="grid aspect-square w-full place-items-center bg-muted text-3xl font-bold text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    {{ p.name.trim().charAt(0) }}
                                </div>

                                <span
                                    v-if="p.promo_label"
                                    class="absolute left-2 top-2 rounded-full bg-[var(--status-good)] px-2 py-0.5 text-[11px] font-medium text-white"
                                >
                                    {{ p.promo_label }}
                                </span>

                                <span
                                    class="absolute bottom-2 right-2 grid size-9 place-items-center rounded-full bg-[var(--series-1)] text-white shadow-lg"
                                    aria-hidden="true"
                                >
                                    <Plus class="size-5" />
                                </span>
                            </div>

                            <p class="mt-1.5 line-clamp-2 text-sm font-medium">{{ p.name }}</p>
                            <DietBadges :tags="p.diet_tags" :best-seller="p.is_best_seller" :limit="2" />
                            <p class="tabular text-sm font-semibold">
                                {{ money(effectivePrice(p)) }} ฿
                            </p>
                        </button>
                    </li>
                </ul>
            </section>

            <!-- ══ เมนูทั้งหมด แยกตามหมวด ══ -->
            <div v-if="grouped.length" class="space-y-6 pt-5">
                <section
                    v-for="group in grouped"
                    :id="sectionId(group.category.id)"
                    :key="group.category.id"
                    :data-category-id="group.category.id"
                    class="scroll-mt-28"
                >
                    <h2 class="px-4 pb-1 text-xl font-bold lg:pb-2 lg:text-2xl">{{ group.category.name }}</h2>

                    <ul class="divide-y border-y bg-card md:grid md:grid-cols-2 md:divide-y-0 md:gap-px md:bg-border">
                        <li v-for="p in group.items" :key="p.id" class="bg-card">
                            <button
                                class="flex h-full w-full items-center gap-3 px-4 py-3 text-left transition-colors active:bg-accent disabled:opacity-60"
                                :disabled="!canOrder"
                                @click="openProduct(p)"
                            >
                                <ProductThumb :name="p.name" :src="p.image_path" size="xl" />

                                <span class="min-w-0 flex-1 self-start py-1">
                                    <span v-if="p.promo_label" class="mb-0.5 block text-xs font-medium text-[var(--status-good)]">
                                        {{ p.promo_label }}
                                    </span>
                                    <span class="block text-base font-medium">{{ p.name }}</span>
                                    <DietBadges
                                        :tags="p.diet_tags"
                                        :best-seller="p.is_best_seller"
                                        class="mt-1"
                                    />
                                    <span v-if="p.description" class="mt-0.5 line-clamp-2 block text-xs text-muted-foreground">
                                        {{ p.description }}
                                    </span>
                                    <span class="tabular mt-1.5 block text-lg font-semibold">
                                        {{ money(effectivePrice(p)) }}
                                        <template v-if="hasStaffPrice(p)">
                                            <span class="ms-1 text-sm font-normal text-muted-foreground line-through">
                                                {{ money(p.price) }}
                                            </span>
                                            <span class="ms-1 text-xs font-normal text-[var(--series-1)]">ราคาพนักงาน</span>
                                        </template>
                                    </span>
                                </span>

                                <span
                                    class="grid size-11 shrink-0 place-items-center self-center rounded-full bg-[var(--series-1)] text-white"
                                    aria-hidden="true"
                                >
                                    <Plus class="size-5" />
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>
            </div>

            <EmptyState v-else title="ไม่พบเมนู" description="ลองค้นหาด้วยคำอื่น หรือเลือกหมวดหมู่อื่น" />
        </div>

        <!--
            แถบลอยด้านล่าง — บิลของโต๊ะกับตะกร้าอยู่ในกล่องเดียวกัน

            ถ้าแยกเป็นสอง fixed ทั้งคู่จะทับกันที่ขอบล่าง และบนจอมือถือเตี้ย ๆ
            ปุ่มตะกร้าจะถูกแถบบิลบัง ซึ่งเป็นปุ่มที่ลูกค้าต้องกดมากที่สุด
        -->
        <div
            v-if="step === 'menu' && (bill || cart.count.value > 0)"
            class="fixed inset-x-0 bottom-0 z-30 border-t bg-card/95 backdrop-blur"
        >
            <div
                class="mx-auto max-w-2xl px-4 py-3 lg:max-w-5xl"
                :style="{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom, 0px))' }"
            >
                <!-- สั่งไปแล้วเท่าไหร่ — กดเพื่อเปิดบิลเต็ม -->
                <button
                    v-if="bill"
                    type="button"
                    class="mb-2 flex w-full items-center gap-2 rounded-xl border bg-background px-3 py-2.5 text-left transition-colors hover:bg-accent"
                    @click="showBill = true"
                >
                    <Receipt class="size-4 shrink-0 text-[var(--series-1)]" />

                    <span class="min-w-0 flex-1 text-sm">
                        <span class="font-semibold">สั่งไปแล้ว {{ number(bill.item_count) }} รายการ</span>
                        <span class="tabular text-muted-foreground"> · {{ money(bill.totals.grand_total) }} ฿</span>
                    </span>

                    <span
                        v-if="bill.waiting_approval > 0"
                        class="shrink-0 rounded-full bg-[var(--status-warning)]/15 px-2 py-0.5 text-[11px] text-[var(--status-warning)]"
                    >
                        รอยืนยัน {{ number(bill.waiting_approval) }}
                    </span>

                    <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
                </button>

                <template v-if="cart.count.value > 0">
                <!-- ป้ายระบุรูปแบบการสั่งปัจจุบัน (ทานที่ร้าน vs รับที่ร้าน) -->
                <div class="mb-2 flex items-center justify-center">
                    <p
                        v-if="table || (isStaff && staffTableId)"
                        class="inline-flex items-center gap-1.5 rounded-full bg-[var(--series-1)]/10 px-3 py-0.5 text-xs font-medium text-[var(--series-1)]"
                    >
                        <Utensils class="size-3.5 shrink-0" />
                        <span>รูปแบบ: <b>ทานที่ร้าน</b> (เสิร์ฟที่โต๊ะ {{ table?.name || staffMode?.tables.find(t => t.id === staffTableId)?.name }})</span>
                    </p>
                    <p
                        v-else
                        class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 px-3 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-300"
                    >
                        <ShoppingBag class="size-3.5 shrink-0" />
                        <span>รูปแบบ: <b>รับที่ร้าน / สั่งกลับบ้าน (Takeaway)</b></span>
                    </p>
                </div>

                <Button variant="brand" size="xl" class="w-full shadow-lg" @click="step = 'cart'">
                    <ShoppingBag />
                    ดูตะกร้า · {{ number(cart.count.value) }} รายการ · {{ money(cart.total.value) }} ฿
                </Button>
                </template>
            </div>
        </div>

        <TableBillSheet
            v-if="bill && showBill"
            :bill="bill"
            :calling="callingBill"
            @close="showBill = false"
            @call-bill="callForBill"
        />

        <NicknameSheet
            v-if="askNickname"
            :table-name="table?.name ?? null"
            @done="askNickname = false"
        />

        <MemberSheet v-if="memberSheet" :member="memberSheet" @close="showMember = false" />

        <ProductSheet
            v-if="sheetProduct"
            :product="sheetProduct"
            @close="sheetProduct = null"
            @add="onAdd"
        />

        <CartSheet
            v-if="step === 'cart'"
            v-model:timing="timing"
            v-model:name="name"
            v-model:phone="phone"
            v-model:pickup-at="pickupAt"
            v-model:payment-intent="paymentIntent"
            v-model:note="note"
            v-model:staff-table-id="staffTableId"
            :lines="cart.lines.value"
            :total="cart.total.value"
            :suggestions="suggestions"
            :payment-intents="paymentIntents"
            :earliest-pickup-at="branch.earliest_pickup_at"
            :prep-minutes="branch.prep_minutes"
            :is-staff="isStaff"
            :branch-name="branch.name"
            :table="table"
            :staff-mode="staffMode"
            :points="points"
            :processing="processing"
            :errors="errors"
            @close="step = 'menu'"
            @submit="submit"
            @set-qty="cart.setQty"
            @pick="openProduct"
        />
    </div>
</template>
