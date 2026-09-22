<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import FilterBar from '@/components/backoffice/FilterBar.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import StatCard from '@/components/backoffice/StatCard.vue'
import BarChart from '@/components/charts/BarChart.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { money, number, percent } from '@/lib/format'
import type { PageProps } from '@/types'

const props = defineProps<{
    filters: { from: string; to: string; branch_ids: number[] }
    rows: Array<{
        product_id: number | null
        name: string
        category: string
        qty: number
        revenue: number
        cost: number
        profit: number
        margin: number
    }>
    byCategory: Array<{ name: string; revenue: number; cost: number; profit: number }>
    totals: { revenue: number; cost: number; profit: number; margin: number }
}>()

const page = usePage<PageProps>()

/** เมนูที่อัตรากำไรต่ำกว่า 30% — มักเป็นตัวที่ตั้งราคาผิดหรือต้นทุนวิ่งขึ้น */
const lowMargin = props.rows.filter((r) => r.margin < 30 && r.revenue > 0).slice(0, 5)
</script>

<template>
    <Head title="ต้นทุนและกำไรรายเมนู" />

    <BackOfficeLayout title="ต้นทุนและกำไรรายเมนู">
        <FilterBar :branches="page.props.branches" :filters="filters" action="/backoffice/reports/profit" />

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="ยอดขาย" :value="money(totals.revenue)" unit="บาท" />
            <StatCard label="ต้นทุนขาย" :value="money(totals.cost)" unit="บาท" />
            <StatCard
                label="กำไรขั้นต้น"
                :value="money(totals.profit)"
                unit="บาท"
                :tone="totals.profit > 0 ? 'good' : 'critical'"
            />
            <StatCard label="อัตรากำไร" :value="percent(totals.margin, 1)" />
        </div>

        <p class="rounded-lg border border-dashed bg-card px-4 py-2 text-xs text-muted-foreground">
            ต้นทุนใช้ค่าที่บันทึกไว้ตอนขายจริง ไม่ใช่ต้นทุนวันนี้ —
            กำไรของเดือนที่ผ่านมาจึงไม่เปลี่ยนตามราคาวัตถุดิบที่ขยับภายหลัง
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <SectionCard title="กำไรแยกตามหมวดหมู่">
                <BarChart
                    v-if="byCategory.length"
                    :labels="byCategory.map((c) => c.name)"
                    :values="byCategory.map((c) => c.profit)"
                    name="กำไรขั้นต้น"
                    :color-index="2"
                    :height="260"
                    horizontal
                />
                <EmptyState v-else description="ยังไม่มียอดขายในช่วงที่เลือก" />
            </SectionCard>

            <SectionCard title="เมนูที่อัตรากำไรต่ำ" content-class="p-0">
                <DataTable v-if="lowMargin.length">
                    <thead>
                        <tr>
                            <th>เมนู</th>
                            <th class="text-right">ยอดขาย</th>
                            <th class="text-right">อัตรากำไร</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in lowMargin" :key="row.name">
                            <td class="font-medium">{{ row.name }}</td>
                            <td class="tabular text-right">{{ money(row.revenue) }}</td>
                            <td class="tabular text-right text-[var(--status-warning)]">
                                {{ percent(row.margin, 1) }}
                            </td>
                        </tr>
                    </tbody>
                </DataTable>
                <EmptyState v-else title="ไม่มีเมนูที่น่าห่วง" description="ทุกเมนูมีอัตรากำไรเกิน 30%" />
            </SectionCard>
        </div>

        <SectionCard title="รายเมนูทั้งหมด" content-class="p-0">
            <DataTable v-if="rows.length">
                <thead>
                    <tr>
                        <th>เมนู</th>
                        <th>หมวดหมู่</th>
                        <th class="text-right">จำนวน</th>
                        <th class="text-right">ยอดขาย</th>
                        <th class="text-right">ต้นทุน</th>
                        <th class="text-right">กำไร</th>
                        <th class="text-right">อัตรากำไร</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="`${row.product_id}-${row.name}`">
                        <td class="font-medium">{{ row.name }}</td>
                        <td class="text-muted-foreground">{{ row.category }}</td>
                        <td class="tabular text-right">{{ number(row.qty) }}</td>
                        <td class="tabular text-right">{{ money(row.revenue) }}</td>
                        <td class="tabular text-right text-muted-foreground">{{ money(row.cost) }}</td>
                        <td class="tabular text-right font-medium">{{ money(row.profit) }}</td>
                        <td
                            class="tabular text-right"
                            :class="row.margin < 30 ? 'text-[var(--status-warning)]' : ''"
                        >
                            {{ percent(row.margin, 1) }}
                        </td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else description="ยังไม่มียอดขายในช่วงที่เลือก" />
        </SectionCard>
    </BackOfficeLayout>
</template>
