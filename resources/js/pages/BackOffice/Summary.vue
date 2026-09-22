<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import FilterBar from '@/components/backoffice/FilterBar.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import StatCard from '@/components/backoffice/StatCard.vue'
import LineChart from '@/components/charts/LineChart.vue'
import BarChart from '@/components/charts/BarChart.vue'
import ShareBar from '@/components/charts/ShareBar.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Button from '@/components/ui/Button.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { date, duration, money, number, percent } from '@/lib/format'
import type { PageProps, SummaryReport } from '@/types'

const props = defineProps<{
    filters: { from: string; to: string; branch_ids: number[]; hour_basis: string }
    report: SummaryReport
}>()

const page = usePage<PageProps>()

/** สลับว่าจะดูยอดตามเวลาเปิดบิลหรือเวลาปิดบิล */
const hourBasis = ref(props.filters.hour_basis)

watch(hourBasis, (value) => {
    router.get(
        '/backoffice/summary',
        { ...props.filters, hour_basis: value },
        { preserveState: true, preserveScroll: true, replace: true },
    )
})

const sales = computed(() => props.report.sales)
const dailyLabels = computed(() => props.report.daily_sales.map((d) => date(d.date)))
const dailyValues = computed(() => props.report.daily_sales.map((d) => d.amount))
const hourLabels = computed(() => props.report.sales_by_hour.map((h) => h.label))
const hourValues = computed(() => props.report.sales_by_hour.map((h) => h.amount))
const weekdayLabels = computed(() => props.report.sales_by_weekday.map((d) => d.label))
const weekdayValues = computed(() => props.report.sales_by_weekday.map((d) => d.amount))

const rangeLabel = computed(() =>
    props.filters.from === props.filters.to
        ? date(props.filters.from)
        : `${date(props.filters.from)} – ${date(props.filters.to)}`,
)

const totalBills = computed(() =>
    props.report.bills_by_type.reduce((sum, t) => sum + t.bill_count, 0),
)
</script>

<template>
    <Head title="ภาพรวมร้านค้า" />

    <BackOfficeLayout title="ภาพรวมร้านค้า">
        <FilterBar :branches="page.props.branches" :filters="filters" action="/backoffice/summary" />

        <!-- ยอดขายสุทธิ + รายละเอียดยอด -->
        <div class="grid gap-4 lg:grid-cols-2">
            <SectionCard title="ยอดขายสุทธิ">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <p class="tabular text-4xl font-semibold text-[var(--series-1)]">
                            {{ money(sales.net_sales) }}
                            <span class="text-base font-normal text-muted-foreground">บาท</span>
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">{{ rangeLabel }}</p>

                        <div class="mt-4">
                            <ShareBar :items="report.payments" />
                        </div>
                    </div>

                    <dl class="space-y-1.5 text-sm sm:border-l sm:pl-5">
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ยอดขาย</dt>
                            <dd class="tabular">{{ money(sales.gross_sales) }} บาท</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ลดราคา</dt>
                            <dd class="tabular">-{{ money(sales.item_discount) }} บาท</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ลดท้ายบิล</dt>
                            <dd class="tabular">-{{ money(sales.bill_discount) }} บาท</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ค่าบริการ</dt>
                            <dd class="tabular">{{ money(sales.service_charge) }} บาท</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ค่าจัดส่ง</dt>
                            <dd class="tabular">{{ money(sales.delivery_fee) }} บาท</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ภาษี</dt>
                            <dd class="tabular">{{ money(sales.tax_amount) }} บาท</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ยอดปัดเศษ</dt>
                            <dd class="tabular">{{ money(sales.rounding) }} บาท</dd>
                        </div>
                        <div class="flex justify-between gap-2 border-t pt-1.5 font-medium">
                            <dt>รวมสุทธิ</dt>
                            <dd class="tabular">{{ money(sales.net_sales) }} บาท</dd>
                        </div>
                        <div class="flex justify-between gap-2 text-xs text-muted-foreground">
                            <dt>กำไรขั้นต้น (ประมาณ)</dt>
                            <dd class="tabular">{{ money(sales.gross_profit) }} บาท</dd>
                        </div>
                    </dl>
                </div>
            </SectionCard>

            <div class="grid gap-4 sm:grid-cols-2">
                <SectionCard title="บิลที่ปิดไปแล้ว" content-class="space-y-3">
                    <p class="tabular text-3xl font-semibold">
                        {{ number(totalBills) }}
                        <span class="text-sm font-normal text-muted-foreground">บิล</span>
                    </p>

                    <ul class="space-y-2 text-sm">
                        <li
                            v-for="(row, i) in report.bills_by_type"
                            :key="row.type"
                            class="flex items-center justify-between gap-2"
                        >
                            <span class="flex items-center gap-1.5 text-muted-foreground">
                                <span
                                    class="size-2 rounded-full"
                                    :style="{ background: `var(--series-${i + 1})` }"
                                />
                                {{ row.label }}
                            </span>
                            <span class="tabular text-right">
                                {{ number(row.bill_count) }} บิล
                                <span class="block text-xs text-muted-foreground">{{ money(row.amount) }} บาท</span>
                            </span>
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard title="บิลที่ยกเลิก" content-class="grid grid-cols-2 gap-3">
                    <StatCard
                        label="คืนเงิน"
                        :value="money(report.cancelled.refund.amount)"
                        unit="บาท"
                        :hint="`จำนวน ${number(report.cancelled.refund.count)} บิล`"
                        :tone="report.cancelled.refund.count > 0 ? 'warning' : 'default'"
                    />
                    <StatCard
                        label="ทำลายบิล"
                        :value="money(report.cancelled.void.amount)"
                        unit="บาท"
                        :hint="`จำนวน ${number(report.cancelled.void.count)} บิล`"
                        :tone="report.cancelled.void.count > 0 ? 'critical' : 'default'"
                    />
                    <div class="col-span-2 grid grid-cols-2 gap-3">
                        <StatCard
                            label="เฉลี่ยต่อบิล"
                            :value="money(sales.avg_per_bill)"
                            unit="บาท"
                        />
                        <StatCard
                            label="เฉลี่ยต่อคน"
                            :value="money(sales.avg_per_guest)"
                            unit="บาท"
                        />
                    </div>
                </SectionCard>
            </div>
        </div>

        <!-- กราฟยอดขาย -->
        <SectionCard title="ยอดขายรายวัน">
            <LineChart
                v-if="report.daily_sales.length"
                :labels="dailyLabels"
                :values="dailyValues"
                name="ยอดขายสุทธิ"
                :height="260"
            />
            <EmptyState v-else description="ยังไม่มียอดขายในช่วงเวลาที่เลือก" />
        </SectionCard>

        <div class="grid gap-4 lg:grid-cols-2">
            <SectionCard title="ยอดขายแยกตามช่วงเวลา">
                <template #actions>
                    <div class="flex gap-1">
                        <Button
                            :variant="hourBasis === 'opened_at' ? 'secondary' : 'ghost'"
                            size="sm"
                            @click="hourBasis = 'opened_at'"
                        >
                            เวลาเปิดบิล
                        </Button>
                        <Button
                            :variant="hourBasis === 'closed_at' ? 'secondary' : 'ghost'"
                            size="sm"
                            @click="hourBasis = 'closed_at'"
                        >
                            เวลาปิดบิล
                        </Button>
                    </div>
                </template>

                <BarChart :labels="hourLabels" :values="hourValues" name="ยอดขาย" :height="240" />
            </SectionCard>

            <SectionCard title="ยอดขายแยกตามวันในสัปดาห์">
                <BarChart
                    :labels="weekdayLabels"
                    :values="weekdayValues"
                    name="ยอดขาย"
                    :color-index="2"
                    :height="240"
                />
            </SectionCard>
        </div>

        <!-- สินค้า -->
        <div class="grid gap-4 lg:grid-cols-3">
            <SectionCard title="สินค้า" content-class="space-y-3">
                <StatCard
                    label="เมนูที่ขายได้"
                    :value="`${number(report.product_coverage.sold)}/${number(report.product_coverage.total)}`"
                    unit="เมนู"
                    :hint="`คิดเป็น ${percent(report.product_coverage.percent)}`"
                />
                <StatCard
                    v-if="report.top_products[0]"
                    label="สินค้าขายดี"
                    :value="report.top_products[0].name"
                    :hint="`${number(report.top_products[0].qty)} รายการ · ${money(report.top_products[0].amount)} บาท`"
                />
                <StatCard
                    v-if="report.top_categories[0]"
                    label="หมวดหมู่ขายดี"
                    :value="report.top_categories[0].name"
                    :hint="`คิดเป็น ${percent(report.top_categories[0].percent)}`"
                />
            </SectionCard>

            <SectionCard title="10 อันดับสินค้าขายดี" class="lg:col-span-2" content-class="p-0">
                <DataTable v-if="report.top_products.length">
                    <thead>
                        <tr>
                            <th class="w-10">#</th>
                            <th>สินค้า</th>
                            <th>หมวดหมู่</th>
                            <th class="text-right">จำนวน</th>
                            <th class="text-right">ยอดขาย</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in report.top_products" :key="`${row.product_id}-${i}`">
                            <td class="tabular text-muted-foreground">{{ i + 1 }}</td>
                            <td class="font-medium">{{ row.name }}</td>
                            <td class="text-muted-foreground">{{ row.category ?? '-' }}</td>
                            <td class="tabular text-right">{{ number(row.qty) }}</td>
                            <td class="tabular text-right">{{ money(row.amount) }}</td>
                        </tr>
                    </tbody>
                </DataTable>
                <EmptyState v-else description="ยังไม่มีข้อมูลการขายในช่วงเวลาที่เลือก" />
            </SectionCard>
        </div>

        <!-- คลัง / โปรฯ / ลูกค้า / โต๊ะ -->
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <SectionCard title="สินค้าคงคลัง" content-class="space-y-3">
                <StatCard
                    label="มูลค่าเติมสินค้า"
                    :value="money(report.inventory.purchase.amount)"
                    unit="บาท"
                    :hint="`จำนวน ${number(report.inventory.purchase.count)} รายการ`"
                />
                <StatCard
                    label="มูลค่าเสียหาย"
                    :value="money(report.inventory.waste.amount)"
                    unit="บาท"
                    :hint="`จำนวน ${number(report.inventory.waste.count)} รายการ`"
                    :tone="report.inventory.waste.amount > 0 ? 'warning' : 'default'"
                />
                <StatCard
                    label="วัตถุดิบต่ำกว่าจุดสั่งซื้อ"
                    :value="number(report.inventory.low_stock_count)"
                    unit="รายการ"
                    :tone="report.inventory.low_stock_count > 0 ? 'critical' : 'good'"
                />
            </SectionCard>

            <SectionCard title="โปรโมชั่น" content-class="space-y-3">
                <StatCard
                    label="ส่วนลดจากโปรโมชั่น"
                    :value="money(report.promotions.discount_amount)"
                    unit="บาท"
                />
                <StatCard
                    label="บิลที่ใช้โปรโมชั่น"
                    :value="percent(report.promotions.percent)"
                    :hint="`จำนวน ${number(report.promotions.promo_bill_count)}/${number(report.promotions.bill_count)} บิล`"
                />
            </SectionCard>

            <SectionCard title="ลูกค้า" content-class="space-y-3">
                <StatCard
                    label="ลูกค้าทั้งหมด"
                    :value="number(report.customers.total)"
                    unit="คน"
                    :hint="`เฉลี่ยต่อวัน ${number(report.customers.avg_per_day, 1)} คน`"
                />
                <StatCard
                    label="จ่ายเงินเฉลี่ย"
                    :value="money(report.customers.avg_spend)"
                    unit="บาท/คน"
                />
            </SectionCard>

            <SectionCard title="โต๊ะ" content-class="space-y-3">
                <StatCard
                    label="จำนวนการใช้โต๊ะ"
                    :value="number(report.tables.turnover_per_table_per_day, 2)"
                    unit="รอบ/โต๊ะ/วัน"
                />
                <StatCard
                    label="เวลานั่งเฉลี่ย"
                    :value="duration(report.tables.avg_minutes)"
                    :hint="`สูงสุด ${duration(report.tables.max_minutes)}`"
                />
                <StatCard
                    label="สั่งอาหารเฉลี่ย"
                    :value="number(report.tables.avg_items_per_bill, 1)"
                    unit="รายการ/บิล"
                    :hint="`ลูกค้านั่งเฉลี่ย ${number(report.tables.avg_guests, 1)} คน/โต๊ะ`"
                />
            </SectionCard>
        </div>

        <!-- พนักงาน -->
        <SectionCard title="พนักงาน">
            <template #actions>
                <span class="text-xs text-muted-foreground">รวม {{ number(report.staff.total) }} ครั้ง</span>
            </template>

            <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <li
                    v-for="row in report.staff.items"
                    :key="row.action"
                    class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                >
                    <span class="truncate text-muted-foreground">{{ row.label }}</span>
                    <span class="tabular font-medium">{{ number(row.count) }}</span>
                </li>
            </ul>
        </SectionCard>
    </BackOfficeLayout>
</template>
