<script setup lang="ts">
/**
 * รายงานภาษีขาย
 *
 * ── สามส่วน ต่างคนต่างใช้ ──────────────────────────────────
 *   สรุปรายวัน   ตัวเลขที่เอาไปกรอก ภ.พ.30
 *   รายใบกำกับ   รายงานที่ต้องเก็บไว้ให้ตรวจ ดาวน์โหลดได้
 *   เลขที่ขาดหาย ถ้าเลขไม่ต่อเนื่อง ต้องรู้ตั้งแต่ตอนปิดเดือน ไม่ใช่ตอนถูกตรวจ
 *
 * กล่องเลขขาดหายอยู่บนสุดเมื่อมีปัญหา เพราะเป็นเรื่องเดียวในหน้านี้
 * ที่ต้องแก้ก่อนยื่น ส่วนตัวเลขที่เหลือแค่อ่านแล้วกรอกตาม
 */
import { computed, ref } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { AlertTriangle, Download } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import FilterBar from '@/components/backoffice/FilterBar.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import StatCard from '@/components/backoffice/StatCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { date, money, number } from '@/lib/format'
import type { PageProps } from '@/types'

interface InvoiceRow {
    business_date: string
    receipt_no: string
    order_no: string
    branch: string | null
    customer: string | null
    net_amount: number
    tax_amount: number
    rounding: number
    total_amount: number
    status: string
    status_label: string
    counts_as_sale: boolean
}

const props = defineProps<{
    rows: Array<Record<string, any>>
    totals: {
        bill_count: number
        net_amount: number
        tax_amount: number
        rounding: number
        total_amount: number
    }
    invoices: InvoiceRow[]
    gaps: Array<{ branch_id: number; business_date: string; missing: string[] }>
    filters: { from: string; to: string; branch_ids: number[] }
}>()

const page = usePage<PageProps>()

const tab = ref<'daily' | 'invoices'>('daily')

const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: props.filters.from, to: props.filters.to })
    props.filters.branch_ids.forEach((id) => params.append('branch_ids[]', String(id)))

    return `/backoffice/tax/export?${params.toString()}`
})

const missingCount = computed(() => props.gaps.reduce((sum, g) => sum + g.missing.length, 0))

/** ใบที่ยกเลิกแล้วยังอยู่ในรายงาน แต่ไม่นับเป็นยอดขาย */
const cancelled = computed(() => props.invoices.filter((i) => ! i.counts_as_sale))
</script>

<template>
    <Head title="รายงานภาษีขาย" />

    <BackOfficeLayout title="รายงานภาษีขาย">
        <FilterBar :branches="page.props.branches" :filters="filters" action="/backoffice/tax" />

        <!-- เลขใบกำกับไม่ต่อเนื่อง -->
        <div
            v-if="gaps.length"
            class="rounded-xl border-2 border-[var(--status-critical)] bg-[var(--status-critical)]/8 p-4"
            role="alert"
        >
            <div class="flex items-start gap-2.5">
                <AlertTriangle class="mt-0.5 size-5 shrink-0 text-[var(--status-critical)]" />
                <div class="min-w-0">
                    <p class="font-semibold text-[var(--status-critical)]">
                        เลขที่ใบกำกับไม่ต่อเนื่อง — ขาดไป {{ number(missingCount) }} เลข
                    </p>
                    <p class="mt-0.5 text-sm">
                        ต้องอธิบายได้ว่าเลขเหล่านี้หายไปไหนก่อนยื่นภาษี
                        ใบที่ยกเลิกแล้วจะไม่ขึ้นตรงนี้ เพราะยังกินเลขของมันอยู่
                    </p>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li v-for="g in gaps" :key="`${g.branch_id}-${g.business_date}`">
                            {{ date(g.business_date) }} ·
                            <span class="font-mono">{{ g.missing.join(', ') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <StatCard label="จำนวนใบกำกับ" :value="number(totals.bill_count)" unit="ใบ" />
            <StatCard label="มูลค่าก่อนภาษี" :value="money(totals.net_amount)" unit="บาท" />
            <StatCard label="ภาษีมูลค่าเพิ่ม" :value="money(totals.tax_amount)" unit="บาท" />
            <StatCard label="ปัดเศษ" :value="money(totals.rounding)" unit="บาท" />
            <StatCard label="รวมทั้งสิ้น" :value="money(totals.total_amount)" unit="บาท" />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="inline-flex rounded-lg border bg-muted/40 p-0.5 text-sm">
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 font-medium transition-colors"
                    :class="tab === 'daily' ? 'bg-card shadow-xs' : 'text-muted-foreground'"
                    @click="tab = 'daily'"
                >
                    สรุปรายวัน
                </button>
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 font-medium transition-colors"
                    :class="tab === 'invoices' ? 'bg-card shadow-xs' : 'text-muted-foreground'"
                    @click="tab = 'invoices'"
                >
                    รายใบกำกับ ({{ number(invoices.length) }})
                </button>
            </div>

            <a
                :href="exportUrl"
                class="inline-flex items-center gap-1.5 rounded-md border px-3 py-2 text-sm hover:bg-accent"
            >
                <Download class="size-4" />
                ดาวน์โหลด CSV
            </a>
        </div>

        <SectionCard v-if="tab === 'daily'" title="สรุปรายวัน" content-class="p-0">
            <DataTable v-if="rows.length">
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th class="text-right">จำนวนใบ</th>
                        <th class="text-right">มูลค่าก่อนภาษี</th>
                        <th class="text-right">ภาษี</th>
                        <th class="text-right">ปัดเศษ</th>
                        <th class="text-right">รวม</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.business_date">
                        <td>{{ date(row.business_date) }}</td>
                        <td class="tabular text-right">{{ number(row.bill_count) }}</td>
                        <td class="tabular text-right">{{ money(row.net_amount) }}</td>
                        <td class="tabular text-right">{{ money(row.tax_amount) }}</td>
                        <td class="tabular text-right">{{ money(row.rounding) }}</td>
                        <td class="tabular text-right font-medium">{{ money(row.total_amount) }}</td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else description="ไม่มียอดขายในช่วงที่เลือก" />
        </SectionCard>

        <SectionCard v-else title="รายใบกำกับ" content-class="p-0">
            <p v-if="cancelled.length" class="border-b px-4 py-2 text-xs text-muted-foreground">
                มีใบที่ยกเลิก {{ number(cancelled.length) }} ใบ แสดงไว้ด้วยเพราะเลขที่ออกไปแล้วต้องอธิบายได้ทุกเลข
                แต่ไม่ถูกนับในยอดรวมด้านบน
            </p>

            <DataTable v-if="invoices.length">
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th>เลขที่ใบกำกับ</th>
                        <th>ผู้ซื้อ</th>
                        <th class="text-right">มูลค่าก่อนภาษี</th>
                        <th class="text-right">ภาษี</th>
                        <th class="text-right">รวม</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="inv in invoices"
                        :key="inv.receipt_no"
                        :class="! inv.counts_as_sale && 'text-muted-foreground line-through'"
                    >
                        <td>{{ date(inv.business_date) }}</td>
                        <td class="font-mono text-xs">{{ inv.receipt_no }}</td>
                        <td>{{ inv.customer ?? '-' }}</td>
                        <td class="tabular text-right">{{ money(inv.net_amount) }}</td>
                        <td class="tabular text-right">{{ money(inv.tax_amount) }}</td>
                        <td class="tabular text-right font-medium">{{ money(inv.total_amount) }}</td>
                        <td class="text-xs">{{ inv.status_label }}</td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else description="ไม่มีใบกำกับในช่วงที่เลือก" />
        </SectionCard>
    </BackOfficeLayout>
</template>
