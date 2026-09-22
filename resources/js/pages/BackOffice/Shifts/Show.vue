<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ArrowLeft } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import StatCard from '@/components/backoffice/StatCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { dateTime, money, number } from '@/lib/format'

const props = defineProps<{
    shift: Record<string, any>
    byMethod: Array<{ method: string; label: string; count: number; amount: number }>
    totals: { orders: number; sales: number }
}>()

const diffTone = Number(props.shift.cash_diff) === 0 ? 'good' : 'critical'
</script>

<template>
    <Head :title="`รอบ ${shift.shift_no}`" />

    <BackOfficeLayout :title="`รอบการขาย ${shift.shift_no}`">
        <Link
            href="/backoffice/shifts"
            class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" />
            กลับไปรายการรอบ
        </Link>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="ยอดขายรวม" :value="money(totals.sales)" unit="บาท" :hint="`${number(totals.orders)} บิล`" />
            <StatCard label="เงินทอนตั้งต้น" :value="money(shift.opening_cash)" unit="บาท" />
            <StatCard label="เงินสดที่ควรมี" :value="money(shift.expected_cash)" unit="บาท" />
            <StatCard
                label="ขาด/เกิน"
                :value="money(shift.cash_diff)"
                unit="บาท"
                :tone="diffTone"
                :hint="`นับได้ ${money(shift.counted_cash)} บาท`"
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <SectionCard title="แยกตามช่องทางชำระเงิน" content-class="p-0">
                <DataTable v-if="byMethod.length">
                    <thead>
                        <tr>
                            <th>ช่องทาง</th>
                            <th class="text-right">จำนวน</th>
                            <th class="text-right">ยอด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in byMethod" :key="m.method">
                            <td>{{ m.label }}</td>
                            <td class="tabular text-right">{{ number(m.count) }}</td>
                            <td class="tabular text-right font-medium">{{ money(m.amount) }}</td>
                        </tr>
                    </tbody>
                </DataTable>
                <EmptyState v-else description="ยังไม่มีการชำระเงินในรอบนี้" />
            </SectionCard>

            <SectionCard title="เงินเข้า-ออกลิ้นชัก" content-class="p-0">
                <DataTable v-if="shift.cash_movements?.length">
                    <thead>
                        <tr>
                            <th>เวลา</th>
                            <th>ประเภท</th>
                            <th>พนักงาน</th>
                            <th>เหตุผล</th>
                            <th class="text-right">จำนวน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in shift.cash_movements" :key="m.id">
                            <td class="text-muted-foreground">{{ dateTime(m.created_at) }}</td>
                            <td>{{ m.type === 'in' ? 'นำเงินเข้า' : 'นำเงินออก' }}</td>
                            <td class="text-muted-foreground">{{ m.user?.name }}</td>
                            <td class="text-muted-foreground">{{ m.reason ?? '-' }}</td>
                            <td class="tabular text-right">{{ money(m.amount) }}</td>
                        </tr>
                    </tbody>
                </DataTable>
                <EmptyState v-else description="ไม่มีการนำเงินเข้า-ออกในรอบนี้" />
            </SectionCard>
        </div>
    </BackOfficeLayout>
</template>
