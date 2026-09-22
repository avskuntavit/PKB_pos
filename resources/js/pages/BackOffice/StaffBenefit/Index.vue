<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { Download } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import StatCard from '@/components/backoffice/StatCard.vue'
import BarChart from '@/components/charts/BarChart.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Button from '@/components/ui/Button.vue'
import Select from '@/components/ui/Select.vue'
import { money, number } from '@/lib/format'

const props = defineProps<{
    filters: { period: string; branch_ids: number[] }
    periods: string[]
    summary: {
        order_count: number
        employee_count: number
        discount_total: number
        paid_total: number
        avg_per_employee: number
    }
    rows: Array<{
        customer_id: number
        employee_code: string
        name: string
        phone: string
        order_count: number
        discount_total: number
        paid_total: number
    }>
    monthly: Array<{ period: string; amount: number }>
}>()

const period = ref(props.filters.period)

watch(period, (value) => {
    router.get('/backoffice/staff-benefit', { period: value }, { preserveState: true, replace: true })
})
</script>

<template>
    <Head title="รายงานสวัสดิการพนักงาน" />

    <BackOfficeLayout title="รายงานสวัสดิการพนักงาน">
        <div class="rounded-xl border bg-card p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="min-w-[180px] space-y-1">
                    <label for="period" class="text-xs font-medium text-muted-foreground">รอบเดือน</label>
                    <Select id="period" v-model="period">
                        <option v-for="p in periods" :key="p" :value="p">{{ p }}</option>
                    </Select>
                </div>

                <Button variant="outline" as="a" :href="`/backoffice/staff-benefit/export?period=${period}`">
                    <Download />
                    ดาวน์โหลด CSV
                </Button>
            </div>

            <p class="mt-3 border-t pt-3 text-xs text-muted-foreground">
                ตัวเลข "มูลค่าส่วนลด" คือส่วนที่บริษัทออกให้พนักงาน — บัญชีนำไปตั้งเป็นค่าใช้จ่ายสวัสดิการต่อ
                ส่วน "ยอดที่จ่ายเอง" คือเงินที่พนักงานจ่ายจริงที่ร้าน
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="มูลค่าส่วนลด (บริษัทออกให้)"
                :value="money(summary.discount_total)"
                unit="บาท"
                tone="warning"
                :hint="`รอบ ${filters.period}`"
            />
            <StatCard
                label="พนักงานที่ใช้สิทธิ์"
                :value="number(summary.employee_count)"
                unit="คน"
                :hint="`เฉลี่ย ${money(summary.avg_per_employee)} บาท/คน`"
            />
            <StatCard label="จำนวนบิล" :value="number(summary.order_count)" unit="บิล" />
            <StatCard label="ยอดที่พนักงานจ่ายเอง" :value="money(summary.paid_total)" unit="บาท" />
        </div>

        <SectionCard v-if="monthly.length > 1" title="แนวโน้มงบสวัสดิการย้อนหลัง">
            <BarChart
                :labels="monthly.map((m) => m.period)"
                :values="monthly.map((m) => m.amount)"
                name="มูลค่าส่วนลด"
                :color-index="3"
                :height="220"
            />
        </SectionCard>

        <SectionCard title="แยกรายคน" content-class="p-0">
            <DataTable v-if="rows.length">
                <thead>
                    <tr>
                        <th>รหัสพนักงาน</th>
                        <th>ชื่อ</th>
                        <th>เบอร์โทร</th>
                        <th class="text-right">จำนวนบิล</th>
                        <th class="text-right">ยอดที่จ่ายเอง</th>
                        <th class="text-right">มูลค่าส่วนลด</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.customer_id">
                        <td class="tabular font-medium">{{ row.employee_code }}</td>
                        <td>{{ row.name }}</td>
                        <td class="tabular text-muted-foreground">{{ row.phone }}</td>
                        <td class="tabular text-right">{{ number(row.order_count) }}</td>
                        <td class="tabular text-right text-muted-foreground">{{ money(row.paid_total) }}</td>
                        <td class="tabular text-right font-semibold">{{ money(row.discount_total) }}</td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else title="ยังไม่มีการใช้สิทธิ์ในรอบนี้" />
        </SectionCard>
    </BackOfficeLayout>
</template>
