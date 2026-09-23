<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import {
    Activity,
    ArrowLeftRight,
    BadgeCheck,
    Banknote,
    Boxes,
    Building2,
    CalendarCheck,
    ChartPie,
    ChefHat,
    Clock,
    FileSpreadsheet,
    HandCoins,
    Landmark,
    LayoutDashboard,
    LayoutGrid,
    LogOut,
    Menu,
    Percent,
    Printer,
    Receipt,
    Settings,
    ShieldCheck,
    SlidersHorizontal,
    Smartphone,
    Star,
    Store,
    Target,
    Ticket,
    TrendingUp,
    Upload,
    UserRound,
    Users,
    UtensilsCrossed,
    X,
} from 'lucide-vue-next'
import BrandMark from '@/components/ui/BrandMark.vue'
import Button from '@/components/ui/Button.vue'
import StationSwitcher from '@/components/backoffice/StationSwitcher.vue'
import type { PageProps } from '@/types'

defineProps<{ title?: string }>()

const page = usePage<PageProps>()
const sidebarOpen = ref(false)

const user = computed(() => page.props.auth.user)
const currentBranch = computed(() => page.props.currentBranch)

/*
| แถบเตือนสุขภาพระบบ
|
| เป็น null เมื่อคนนี้ไม่มีสิทธิ์ดู — แถบและตัวเลขบนเมนูจะไม่ขึ้นเลย
| ตัวเลขมาจากฝั่งเซิร์ฟเวอร์ (แคช 60 วินาที) ไม่ได้คำนวณใหม่ทุกครั้งที่เปลี่ยนหน้า
*/
const alerts = computed(() => page.props.systemAlerts)

const alertCount = computed(() => {
    const a = alerts.value
    if (!a) return 0
    return a.backup_stale + a.open_errors + (a.scheduler_down ? 1 : 0)
})

/** ข้อความบนแถบ — เรียงจากเรื่องที่เสียหายมากที่สุดก่อน */
const alertMessages = computed(() => {
    const a = alerts.value
    if (!a) return []

    const out: string[] = []

    if (a.scheduler_down) {
        out.push('ตัวตั้งเวลาหยุดทำงาน — งานพิมพ์ที่ค้าง การส่งข้อมูลบัญชี และการสำรองข้อมูล หยุดทั้งหมด')
    }
    if (a.backup_stale > 0) {
        out.push(`ขาดการสำรองข้อมูล ${a.backup_stale} รายการ`)
    }
    if (a.open_errors > 0) {
        out.push(`มีข้อผิดพลาดที่ยังไม่ได้ตรวจ ${a.open_errors} เรื่อง`)
    }

    return out
})
const stationColor = computed(() => currentBranch.value?.theme_color || '#2a78d6')

interface NavItem {
    name: string
    icon: any
    href: string
    /** เห็นเฉพาะเจ้าของระบบ — ด่านจริงอยู่ที่ route ตรงนี้แค่ไม่โชว์ลิงก์ที่กดแล้วเด้ง 403 */
    ownerOnly?: boolean
}

interface NavSection {
    title: string
    items: NavItem[]
}

const navSections: NavSection[] = [
    {
        title: 'ภาพรวมและรายงาน',
        items: [
            { name: 'ภาพรวมผู้บริหาร', icon: LayoutDashboard, href: '/backoffice/dashboard' },
            { name: 'สรุปยอดขาย', icon: ChartPie, href: '/backoffice/summary' },
            { name: 'ประวัติการขาย', icon: Receipt, href: '/backoffice/sales' },
            { name: 'รายงานภาษี', icon: FileSpreadsheet, href: '/backoffice/tax' },
            { name: 'กระทบยอดเงินกับธนาคาร', icon: Landmark, href: '/backoffice/bank-reconciliation' },
            { name: 'ส่งข้อมูลให้ระบบบัญชี', icon: Upload, href: '/backoffice/sales-export' },
            { name: 'ปิดงวดบัญชี', icon: CalendarCheck, href: '/backoffice/periods' },
            { name: 'รายงานต้นทุนและกำไร', icon: TrendingUp, href: '/backoffice/reports/profit' },
        ],
    },
    {
        title: 'เมนูและคลังสินค้า',
        items: [
            { name: 'รายการอาหารและสินค้า', icon: UtensilsCrossed, href: '/backoffice/products' },
            { name: 'เซ็ตตัวเลือก (Modifiers)', icon: SlidersHorizontal, href: '/backoffice/modifiers' },
            { name: 'สูตรอาหารและวัตถุดิบ', icon: ChefHat, href: '/backoffice/recipes' },
            { name: 'สินค้าคงคลังและสต็อก', icon: Boxes, href: '/backoffice/inventory' },
            { name: 'โอนของข้ามสถานี', icon: ArrowLeftRight, href: '/backoffice/stock-transfers' },
        ],
    },
    {
        title: 'จัดการหน้าร้าน',
        items: [
            { name: 'ผังโต๊ะและ QR สั่งอาหาร', icon: LayoutGrid, href: '/backoffice/tables' },
            { name: 'รอบการขายและเปิดกะ', icon: Clock, href: '/backoffice/shifts' },
            { name: 'นำส่งเงินสดประจำวัน', icon: Banknote, href: '/backoffice/cash-settlements' },
            { name: 'รีวิวและความพึงพอใจ', icon: Star, href: '/backoffice/reviews' },
            { name: 'เครื่องพิมพ์และคิวงานพิมพ์', icon: Printer, href: '/backoffice/printers' },
        ],
    },
    {
        title: 'การตลาดและลูกค้า',
        items: [
            { name: 'ข้อมูลลูกค้าและสมาชิก', icon: Users, href: '/backoffice/customers' },
            { name: 'โปรโมชั่นและส่วนลด', icon: Percent, href: '/backoffice/promotions' },
            { name: 'บัตรกำนัลและ Voucher', icon: Ticket, href: '/backoffice/vouchers' },
        ],
    },
    {
        title: 'บุคลากรและสวัสดิการ',
        items: [
            { name: 'รายชื่อพนักงาน', icon: UserRound, href: '/backoffice/staff' },
            { name: 'สิทธิ์พนักงานองค์กร', icon: BadgeCheck, href: '/backoffice/employees' },
            { name: 'รายงานสวัสดิการพนักงาน', icon: HandCoins, href: '/backoffice/staff-benefit' },
        ],
    },
    {
        title: 'ตั้งค่าระบบ',
        items: [
            { name: 'สถานีทั้งหมด', icon: Building2, href: '/backoffice/stations', ownerOnly: true },
            { name: 'สุขภาพระบบและสำรองข้อมูล', icon: Activity, href: '/backoffice/health', ownerOnly: true },
            { name: 'ข้อมูลและตั้งค่าสาขา', icon: Settings, href: '/backoffice/settings/branch' },
            { name: 'เป้ายอดขายรายเดือน', icon: Target, href: '/backoffice/settings/targets' },
            { name: 'สิทธิ์การใช้งานพนักงาน', icon: ShieldCheck, href: '/backoffice/settings/permissions' },
        ],
    },
]

/*
| ซ่อนเมนูที่คนนี้กดไปก็เจอ 403
|
| ไม่ใช่ด่านความปลอดภัย — ด่านจริงคือ middleware permission ที่ route
| ตรงนี้มีไว้ไม่ให้พนักงานเห็นเมนูที่ใช้ไม่ได้เต็มแถบข้าง
*/
const visibleSections = computed(() =>
    navSections
        .map((section) => ({
            ...section,
            items: section.items.filter((item) => !item.ownerOnly || user.value?.role === 'owner'),
        }))
        .filter((section) => section.items.length > 0),
)

function isActive(href: string): boolean {
    return page.url.startsWith(href)
}

function logout() {
    router.post('/logout')
}
</script>

<template>
    <div
        class="min-h-dvh bg-muted/40"
        :style="{ '--station-color': stationColor, '--station-color-10': stationColor + '1a', '--station-color-20': stationColor + '33' }"
    >
        <!-- แถบสีประจำสถานี (Top Station Color Strip) - แสดงเฉพาะหลังบ้าน -->
        <div
            class="sticky top-0 z-50 h-1 w-full shrink-0 shadow-2xs"
            :style="{ backgroundColor: stationColor }"
        />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 w-72 max-w-[85vw] -translate-x-full border-r bg-card transition-transform sm:w-64 lg:translate-x-0 flex flex-col"
            :class="{ 'translate-x-0': sidebarOpen }"
        >
            <!-- Logo Header -->
            <div class="flex h-14 shrink-0 items-center justify-between border-b px-4 md:h-16">
                <Link href="/backoffice/dashboard" class="flex items-center" aria-label="ภาพรวมผู้บริหาร">
                    <BrandMark :height="26" />
                </Link>
                <button
                    class="-me-2 grid size-11 place-items-center rounded-md transition-colors hover:bg-accent lg:hidden"
                    aria-label="ปิดเมนู"
                    @click="sidebarOpen = false"
                >
                    <X class="size-5" />
                </button>
            </div>

            <!-- ส่วนข้อมูลสถานี & ผู้ใช้ปัจจุบันใน Sidebar พร้อมโลโก้สถานี -->
            <div class="shrink-0 border-b px-4 py-3 bg-muted/20">
                <div class="flex items-center gap-2.5">
                    <img
                        v-if="currentBranch?.logo_path"
                        :src="currentBranch.logo_path"
                        class="size-8 rounded-lg object-cover border bg-card shrink-0 shadow-2xs"
                        :alt="currentBranch.name"
                    />
                    <div
                        v-else
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold text-white shadow-2xs"
                        :style="{ backgroundColor: stationColor }"
                    >
                        {{ currentBranch?.name?.charAt(0) || 'S' }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-bold text-foreground">{{ currentBranch?.name || 'สถานี' }}</p>
                        <p class="truncate text-[11px] text-muted-foreground">{{ user?.name }} ({{ user?.role_label }})</p>
                    </div>
                </div>
            </div>

            <!-- เมนูหลักแยกตามหมวดหมู่ -->
            <nav class="flex-1 space-y-4 overflow-y-auto overscroll-contain p-2.5">
                <div
                    v-for="(section, idx) in visibleSections"
                    :key="section.title"
                    :class="{ 'border-t pt-3': idx > 0 }"
                >
                    <p class="px-2.5 pb-1.5 text-[11px] font-semibold text-muted-foreground/75 tracking-wider">
                        {{ section.title }}
                    </p>
                    <div class="space-y-0.5">
                        <Link
                            v-for="item in section.items"
                            :key="item.href"
                            :href="item.href"
                            class="group flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition-all"
                            :class="
                                isActive(item.href)
                                    ? 'font-semibold shadow-2xs'
                                    : 'text-muted-foreground hover:bg-accent/70 hover:text-foreground'
                            "
                            :style="
                                isActive(item.href)
                                    ? { color: stationColor, backgroundColor: stationColor + '18' }
                                    : {}
                            "
                        >
                            <component
                                :is="item.icon"
                                class="size-4 shrink-0 transition-transform group-hover:scale-110"
                                :style="isActive(item.href) ? { color: stationColor } : {}"
                            />
                            <span class="truncate">{{ item.name }}</span>
                            <span
                                v-if="item.href === '/backoffice/health' && alertCount > 0"
                                class="ms-auto shrink-0 rounded-full bg-[var(--status-critical)] px-1.5 py-0.5 text-[10px] font-bold leading-none text-white"
                            >
                                {{ alertCount }}
                            </span>
                        </Link>
                    </div>
                </div>

                <!-- ลิงก์หน้าร้าน / การเข้าสู่ระบบ -->
                <div class="border-t pt-3 pb-2 space-y-0.5">
                    <p class="px-2.5 pb-1.5 text-[11px] font-semibold text-muted-foreground/75 tracking-wider">
                        ทางลัดหน้าร้าน
                    </p>
                    <Link
                        href="/pos"
                        class="flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    >
                        <Store class="size-4 shrink-0" />
                        <span class="truncate">เปิดหน้าขาย (POS)</span>
                    </Link>
                    <a
                        href="/order"
                        class="flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    >
                        <Smartphone class="size-4 shrink-0" />
                        <span class="truncate">หน้าร้านสั่งอาหารออนไลน์</span>
                    </a>
                    <button
                        class="flex min-h-10 w-full items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                        @click="logout"
                    >
                        <LogOut class="size-4 shrink-0" />
                        <span class="truncate">ออกจากระบบ</span>
                    </button>
                </div>
            </nav>
        </aside>

        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Content -->
        <div class="lg:pl-64">
            <header class="sticky top-0 z-20 flex h-14 items-center gap-2 border-b bg-background/95 px-3 backdrop-blur sm:gap-3 sm:px-4 md:h-16">
                <Button variant="ghost" size="icon" class="lg:hidden" aria-label="เปิดเมนู" @click="sidebarOpen = true">
                    <Menu />
                </Button>
                <h1 class="min-w-0 flex-1 truncate text-base font-semibold sm:text-lg">{{ title }}</h1>

                <!-- สถานีที่กำลังดู — อยู่บนหัวทุกหน้า เพราะทุกหน้าหลังบ้านเป็นข้อมูลรายสถานี พร้อมแสดงโลโก้ -->
                <StationSwitcher />
            </header>

            <main class="mx-auto w-full max-w-[1400px] space-y-4 p-3 sm:p-4 xl:p-6 2xl:max-w-[1600px]">
                <!--
                    แถบเตือนสุขภาพระบบ — ขึ้นทุกหน้าหลังบ้านจนกว่าจะแก้
                    ตั้งใจให้กวนใจ เพราะของที่เตือนอยู่คือของที่เงียบจนถึงวันที่สายเกินไป
                -->
                <Link
                    v-if="alertMessages.length"
                    href="/backoffice/health"
                    class="flex items-start gap-2.5 rounded-lg border border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 px-4 py-2.5 text-sm text-[var(--status-critical)] transition-colors hover:bg-[var(--status-critical)]/15"
                >
                    <Activity class="mt-0.5 size-4 shrink-0" />
                    <span class="min-w-0 flex-1">
                        <span v-for="(msg, i) in alertMessages" :key="i" class="block">{{ msg }}</span>
                    </span>
                    <span class="shrink-0 text-xs font-semibold underline">ดูรายละเอียด</span>
                </Link>

                <div
                    v-if="page.props.flash.success"
                    class="rounded-lg border border-[var(--status-good)]/30 bg-[var(--status-good)]/10 px-4 py-2 text-sm text-[var(--status-good)]"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash.error"
                    class="rounded-lg border border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 px-4 py-2 text-sm text-[var(--status-critical)]"
                >
                    {{ page.props.flash.error }}
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
