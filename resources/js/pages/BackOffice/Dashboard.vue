<script setup lang="ts">
/**
 * ภาพรวมผู้บริหาร
 *
 * จัดเรียงตามลำดับคำถามที่เจ้าของร้านถามจริง ไม่ใช่ตามความสวยของกริด:
 *   วันนี้เป็นไง -> เดือนนี้ยังตามแผนไหม -> ถ้าไม่ตาม ต้องไปแตะอะไร
 *
 * ทุกตัวเลขเป็น "ยอดสุทธิของบิลที่ปิดแล้ว" ตัวเดียวกับหน้ารายงานสรุป
 */
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ArrowDownRight, ArrowRight, ArrowUpRight, Target, TriangleAlert } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import StatCard from '@/components/backoffice/StatCard.vue'
import PlanChart from '@/components/charts/PlanChart.vue'
import BarChart from '@/components/charts/BarChart.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { money, moneyCompact, number } from '@/lib/format'

interface Change {
    base: number
    diff: number
    percent: number | null
}

interface DayRow {
    date: string
    day: number
    actual: number | null
    target: number
    cumulative: number | null
    cumulative_target: number
}

interface DashboardData {
    today: {
        date: string
        net_sales: number
        bill_count: number
        guest_count: number
        avg_per_bill: number
        cost_total: number
        gross_profit: number
        food_cost_percent: number | null
        target: number
        target_percent: number | null
        target_diff: number
        vs_yesterday: Change
        vs_last_week: Change
    }
    month: {
        label: string
        year: number
        month: number
        net_sales: number
        bill_count: number
        cost_total: number
        gross_profit: number
        food_cost_percent: number | null
        target: number
        target_to_date: number
        percent_of_target: number | null
        percent_of_pace: number | null
        pace_diff: number
        projection: number
        days_elapsed: number
        days_total: number
        daily: DayRow[]
    }
    hourly: Array<{ hour: number; label: string; amount: number; bill_count: number }>
    top_products: Array<{ name: string; category: string | null; qty: number; amount: number }>
    purchasing: {
        low_stock_count: number
        items: Array<{
            id: number
            name: string
            unit_label: string
            stock_qty: number
            reorder_level: number
            shortfall: number
            is_out: boolean
        }>
    }
    has_target: boolean
    food_cost_target: number | null
}

const props = defineProps<{
    data: DashboardData
    branches: Array<{ id: number; name: string }>
    filters: { branch_ids: number[] }
}>()

const cumulative = ref(false)

const today = computed(() => props.data.today)
const month = computed(() => props.data.month)

/** เข้าเป้าไหม — ใช้คุมสีการ์ดทั้งใบ ไม่ใช่แค่ตัวเลข */
function tone(percent: number | null): 'default' | 'good' | 'warning' | 'critical' {
    if (percent === null) return 'default'
    if (percent >= 100) return 'good'
    if (percent >= 85) return 'warning'

    return 'critical'
}

/** ต้นทุนวัตถุดิบยิ่งต่ำยิ่งดี เกณฑ์จึงกลับด้านกับยอดขาย */
const foodCostTone = computed<'default' | 'good' | 'warning' | 'critical'>(() => {
    const actual = month.value.food_cost_percent
    const limit = props.data.food_cost_target

    if (actual === null || limit === null) return 'default'
    if (actual <= limit) return 'good'
    if (actual <= limit + 3) return 'warning'

    return 'critical'
})

const chartLabels = computed(() => month.value.daily.map((d) => String(d.day)))

const chartActual = computed(() =>
    month.value.daily.map((d) => (cumulative.value ? d.cumulative : d.actual)),
)

const chartTarget = computed(() =>
    month.value.daily.map((d) => (cumulative.value ? d.cumulative_target : d.target)),
)

/** แถบความคืบหน้าของเป้าเดือน ตัดที่ 100% เพื่อไม่ให้แถบล้นกล่อง */
const monthProgress = computed(() => Math.min(100, month.value.percent_of_target ?? 0))

/** ตำแหน่งที่ "ควรจะอยู่" ตามแผน — ขีดอ้างอิงบนแถบเดียวกัน */
const paceMark = computed(() =>
    month.value.target > 0
        ? Math.min(100, (month.value.target_to_date / month.value.target) * 100)
        : 0,
)

const projectionTone = computed(() => {
    if (month.value.target <= 0) return 'default'

    return month.value.projection >= month.value.target ? 'good' : 'critical'
})

const hourLabels = computed(() => props.data.hourly.map((h) => h.label))
const hourValues = computed(() => props.data.hourly.map((h) => h.amount))

const hasHourlySales = computed(() => hourValues.value.some((v) => v > 0))

function switchBranch(event: Event) {
    const value = (event.target as HTMLSelectElement).value

    router.get(
        '/backoffice/dashboard',
        value === 'all' ? {} : { branch_ids: [Number(value)] },
        { preserveState: false, preserveScroll: true },
    )
}

const selectedBranch = computed(() =>
    props.filters.branch_ids.length === 1 ? String(props.filters.branch_ids[0]) : 'all',
)

/** เลขติดลบให้อ่านออกทันทีว่าขาดอยู่เท่าไหร่ */
function signed(value: number): string {
    return `${value >= 0 ? '+' : '-'}${money(Math.abs(value))}`
}
</script>

<template>
    <Head title="ภาพรวมผู้บริหาร" />

    <BackOfficeLayout title="ภาพรวมผู้บริหาร">
        <!-- ยังไม่ได้ตั้งเป้า — ทั้งหน้าแทบไม่มีความหมาย บอกให้ชัดตั้งแต่บรรทัดแรก -->
        <Link
            v-if="!data.has_target"
            href="/backoffice/settings/targets"
            class="flex items-center gap-3 rounded-lg border border-[var(--status-warning)]/40 bg-[var(--status-warning)]/10 px-4 py-3 text-sm transition-colors hover:bg-[var(--status-warning)]/15"
        >
            <Target class="size-5 shrink-0 text-[var(--status-warning)]" />
            <span class="min-w-0 flex-1">
                <span class="block font-medium">ยังไม่ได้ตั้งเป้ายอดขายของเดือนนี้</span>
                <span class="block text-xs text-muted-foreground">
                    ตั้งเป้ารายเดือนไว้ครั้งเดียว ระบบจะเกลี่ยเป็นเป้ารายวันให้เอง
                    แล้วหน้านี้จะบอกได้ว่าวันนี้และเดือนนี้ยังตามแผนอยู่ไหม
                </span>
            </span>
            <ArrowRight class="size-4 shrink-0 text-muted-foreground" />
        </Link>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-muted-foreground">
                วันขาย {{ today.date }} · {{ month.label }}
            </p>

            <select
                v-if="branches.length > 1"
                class="h-9 rounded-md border bg-card px-3 text-sm"
                :value="selectedBranch"
                aria-label="เลือกสาขา"
                @change="switchBranch"
            >
                <option value="all">ทุกสาขา</option>
                <option v-for="b in branches" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
            </select>
        </div>

        <!-- คำถามที่ 1: วันนี้เป็นไง -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="ยอดขายวันนี้"
                :value="money(today.net_sales)"
                unit="บาท"
                :tone="tone(today.target_percent)"
                :hint="
                    today.target > 0
                        ? `เป้าวันนี้ ${money(today.target)} · ${signed(today.target_diff)}`
                        : 'ยังไม่ได้ตั้งเป้า'
                "
            />
            <StatCard
                label="จำนวนบิลวันนี้"
                :value="number(today.bill_count)"
                unit="บิล"
                :hint="`เฉลี่ยบิลละ ${money(today.avg_per_bill)} บาท`"
            />
            <StatCard
                label="กำไรขั้นต้นวันนี้"
                :value="money(today.gross_profit)"
                unit="บาท"
                :hint="
                    today.food_cost_percent !== null
                        ? `ต้นทุนวัตถุดิบ ${today.food_cost_percent}% ของยอดขาย`
                        : 'ยังไม่มียอดขายวันนี้'
                "
            />
            <StatCard
                label="ยอดสะสมเดือนนี้"
                :value="money(month.net_sales)"
                unit="บาท"
                :tone="tone(month.percent_of_pace)"
                :hint="
                    month.target > 0
                        ? `${month.percent_of_target ?? 0}% ของเป้าเดือน (${moneyCompact(month.target)})`
                        : 'ยังไม่ได้ตั้งเป้าเดือนนี้'
                "
            />
        </div>

        <!-- เทียบวันก่อนหน้า -->
        <div class="grid gap-3 sm:grid-cols-2">
            <div
                v-for="cmp in [
                    { label: 'เทียบเมื่อวาน', data: today.vs_yesterday },
                    { label: 'เทียบวันเดียวกันสัปดาห์ก่อน', data: today.vs_last_week },
                ]"
                :key="cmp.label"
                class="flex items-center gap-3 rounded-lg border bg-card p-4"
            >
                <component
                    :is="cmp.data.diff >= 0 ? ArrowUpRight : ArrowDownRight"
                    class="size-8 shrink-0 rounded-full p-1.5"
                    :class="
                        cmp.data.diff >= 0
                            ? 'bg-[var(--status-good)]/12 text-[var(--status-good)]'
                            : 'bg-[var(--status-critical)]/12 text-[var(--status-critical)]'
                    "
                />
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">{{ cmp.label }}</p>
                    <p class="tabular text-lg font-semibold">
                        {{ signed(cmp.data.diff) }} บาท
                        <span
                            v-if="cmp.data.percent !== null"
                            class="text-sm font-normal text-muted-foreground"
                        >
                            ({{ cmp.data.percent > 0 ? '+' : '' }}{{ cmp.data.percent }}%)
                        </span>
                    </p>
                    <p class="text-xs text-muted-foreground">วันนั้นขายได้ {{ money(cmp.data.base) }} บาท</p>
                </div>
            </div>
        </div>

        <!-- คำถามที่ 2: เดือนนี้ยังตามแผนไหม -->
        <SectionCard :title="`เป้าเดือน${month.label}`">
            <template #actions>
                <Link href="/backoffice/settings/targets" class="text-xs text-[var(--series-1)] hover:underline">
                    แก้เป้า
                </Link>
            </template>

            <div v-if="month.target > 0" class="space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <p class="tabular text-3xl font-semibold">
                        {{ money(month.net_sales) }}
                        <span class="text-base font-normal text-muted-foreground">
                            / {{ money(month.target) }} บาท
                        </span>
                    </p>
                    <p
                        class="tabular text-sm font-medium"
                        :class="
                            month.pace_diff >= 0
                                ? 'text-[var(--status-good)]'
                                : 'text-[var(--status-critical)]'
                        "
                    >
                        {{ month.pace_diff >= 0 ? 'นำแผนอยู่' : 'ตามหลังแผน' }}
                        {{ money(Math.abs(month.pace_diff)) }} บาท
                    </p>
                </div>

                <!-- แถบความคืบหน้า พร้อมขีดบอกว่าตามแผนควรอยู่ตรงไหนแล้ว -->
                <div class="relative h-4 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full transition-all"
                        :class="
                            month.pace_diff >= 0
                                ? 'bg-[var(--status-good)]'
                                : 'bg-[var(--status-warning)]'
                        "
                        :style="{ width: `${monthProgress}%` }"
                    />
                    <div
                        class="absolute inset-y-0 w-0.5 bg-foreground/70"
                        :style="{ left: `${paceMark}%` }"
                        :title="`ตามแผนถึงวันนี้ควรได้ ${money(month.target_to_date)} บาท`"
                    />
                </div>

                <div class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <p class="text-xs text-muted-foreground">ตามแผนถึงวันนี้ควรได้</p>
                        <p class="tabular font-medium">{{ money(month.target_to_date) }} บาท</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            คาดว่าสิ้นเดือนจะได้ (ผ่านมา {{ month.days_elapsed }}/{{ month.days_total }} วัน)
                        </p>
                        <p
                            class="tabular font-medium"
                            :class="
                                projectionTone === 'good'
                                    ? 'text-[var(--status-good)]'
                                    : projectionTone === 'critical'
                                      ? 'text-[var(--status-critical)]'
                                      : ''
                            "
                        >
                            {{ money(month.projection) }} บาท
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            ต้นทุนวัตถุดิบเดือนนี้
                            <span v-if="data.food_cost_target !== null">
                                (เป้า ≤ {{ data.food_cost_target }}%)
                            </span>
                        </p>
                        <p
                            class="tabular font-medium"
                            :class="{
                                'text-[var(--status-good)]': foodCostTone === 'good',
                                'text-[var(--status-warning)]': foodCostTone === 'warning',
                                'text-[var(--status-critical)]': foodCostTone === 'critical',
                            }"
                        >
                            {{ month.food_cost_percent !== null ? `${month.food_cost_percent}%` : '—' }}
                            <span class="text-xs font-normal text-muted-foreground">
                                · กำไรขั้นต้น {{ moneyCompact(month.gross_profit) }}
                            </span>
                        </p>
                    </div>
                </div>
            </div>

            <EmptyState v-else title="ยังไม่ได้ตั้งเป้าของเดือนนี้ จึงยังเทียบแผนไม่ได้" />
        </SectionCard>

        <!-- กราฟจริงเทียบเป้า -->
        <SectionCard title="ยอดขายรายวันเทียบเป้า">
            <template #actions>
                <div class="flex rounded-md border p-0.5 text-xs">
                    <button
                        type="button"
                        class="rounded px-2 py-1 transition-colors"
                        :class="!cumulative ? 'bg-[var(--series-1)] text-white' : 'text-muted-foreground'"
                        @click="cumulative = false"
                    >
                        รายวัน
                    </button>
                    <button
                        type="button"
                        class="rounded px-2 py-1 transition-colors"
                        :class="cumulative ? 'bg-[var(--series-1)] text-white' : 'text-muted-foreground'"
                        @click="cumulative = true"
                    >
                        สะสม
                    </button>
                </div>
            </template>

            <PlanChart
                :labels="chartLabels"
                :actual="chartActual"
                :target="chartTarget"
                :actual-name="cumulative ? 'ยอดสะสมจริง' : 'ยอดขายจริง'"
                :target-name="cumulative ? 'เป้าสะสม' : 'เป้ารายวัน'"
                :height="280"
            />
            <p class="mt-2 text-xs text-muted-foreground">
                เป้ารายวันไม่ได้เอาเป้าเดือนหาร 30 — ระบบถ่วงน้ำหนักตามวันในสัปดาห์จากยอดขายจริงย้อนหลัง
                วันที่ปกติขายดีจะได้เป้าสูงกว่า
            </p>
        </SectionCard>

        <!-- คำถามที่ 3: ต้องไปแตะอะไร -->
        <div class="grid gap-4 lg:grid-cols-2">
            <SectionCard title="เมนูขายดีวันนี้">
                <ul v-if="data.top_products.length" class="divide-y">
                    <li
                        v-for="(p, index) in data.top_products"
                        :key="p.name"
                        class="flex items-center gap-3 py-2 first:pt-0 last:pb-0"
                    >
                        <span class="tabular w-5 shrink-0 text-sm text-muted-foreground">{{ index + 1 }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ p.name }}</span>
                            <span class="block truncate text-xs text-muted-foreground">
                                {{ p.category ?? 'ไม่ระบุหมวดหมู่' }} · {{ number(p.qty) }} จาน
                            </span>
                        </span>
                        <span class="tabular shrink-0 text-sm font-medium">{{ money(p.amount) }}</span>
                    </li>
                </ul>
                <EmptyState v-else title="วันนี้ยังไม่มีบิลที่ปิดแล้ว" />
            </SectionCard>

            <SectionCard title="ยอดขายรายชั่วโมงวันนี้">
                <BarChart
                    v-if="hasHourlySales"
                    :labels="hourLabels"
                    :values="hourValues"
                    name="ยอดขาย"
                    :height="240"
                />
                <EmptyState v-else title="วันนี้ยังไม่มีบิลที่ปิดแล้ว" />
            </SectionCard>
        </div>

        <!-- สะพานไปงานจัดซื้อ -->
        <SectionCard title="ของที่ถึงจุดสั่งซื้อแล้ว">
            <template #actions>
                <Link href="/backoffice/inventory?low_stock=1" class="text-xs text-[var(--series-1)] hover:underline">
                    ดูคลังทั้งหมด
                </Link>
            </template>

            <div v-if="data.purchasing.items.length" class="space-y-2">
                <p class="text-sm text-muted-foreground">
                    มี {{ number(data.purchasing.low_stock_count) }} รายการที่เหลือน้อยกว่าจุดสั่งซื้อ
                    เรียงตามความเร่งด่วน
                </p>

                <ul class="divide-y">
                    <li
                        v-for="item in data.purchasing.items"
                        :key="item.id"
                        class="flex items-center gap-3 py-2 first:pt-0 last:pb-0"
                    >
                        <TriangleAlert
                            class="size-4 shrink-0"
                            :class="
                                item.is_out
                                    ? 'text-[var(--status-critical)]'
                                    : 'text-[var(--status-warning)]'
                            "
                        />
                        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ item.name }}</span>
                        <span class="tabular shrink-0 text-sm">
                            เหลือ {{ number(item.stock_qty, 2) }}
                            <span class="text-xs text-muted-foreground">
                                / จุดสั่ง {{ number(item.reorder_level, 2) }} {{ item.unit_label }}
                            </span>
                        </span>
                    </li>
                </ul>
            </div>

            <EmptyState v-else title="ยังไม่มีของที่ต่ำกว่าจุดสั่งซื้อ" />
        </SectionCard>
    </BackOfficeLayout>
</template>
