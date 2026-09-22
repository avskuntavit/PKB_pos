<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import FilterBar from '@/components/backoffice/FilterBar.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Pagination from '@/components/ui/Pagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Badge from '@/components/ui/Badge.vue'
import { dateTime, money, number } from '@/lib/format'
import type { PageProps, Paginated } from '@/types'

defineProps<{
    shifts: Paginated<Record<string, any>>
    filters: { from: string; to: string }
}>()

const page = usePage<PageProps>()
</script>

<template>
    <Head title="รอบการขาย" />

    <BackOfficeLayout title="รอบการขาย">
        <FilterBar :branches="page.props.branches" :filters="filters" action="/backoffice/shifts" />

        <SectionCard title="รอบการขาย" content-class="p-0">
            <DataTable v-if="shifts.data.length">
                <thead>
                    <tr>
                        <th>รอบ</th>
                        <th>เปิดรอบ</th>
                        <th>ปิดรอบ</th>
                        <th class="text-right">บิล</th>
                        <th class="text-right">เงินสดที่ควรมี</th>
                        <th class="text-right">นับได้</th>
                        <th class="text-right">ขาด/เกิน</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in shifts.data" :key="s.id">
                        <td>
                            <Link
                                :href="`/backoffice/shifts/${s.id}`"
                                class="font-medium text-[var(--series-1)] hover:underline"
                            >
                                {{ s.shift_no }}
                            </Link>
                        </td>
                        <td class="text-muted-foreground">
                            {{ dateTime(s.opened_at) }}
                            <span class="block text-xs">{{ s.opened_by?.name }}</span>
                        </td>
                        <td class="text-muted-foreground">
                            {{ dateTime(s.closed_at) }}
                            <span class="block text-xs">{{ s.closed_by?.name }}</span>
                        </td>
                        <td class="tabular text-right">{{ number(s.orders_count) }}</td>
                        <td class="tabular text-right">{{ money(s.expected_cash) }}</td>
                        <td class="tabular text-right">{{ money(s.counted_cash) }}</td>
                        <td
                            class="tabular text-right font-medium"
                            :class="
                                Number(s.cash_diff) === 0
                                    ? ''
                                    : Number(s.cash_diff) > 0
                                      ? 'text-[var(--status-good)]'
                                      : 'text-[var(--status-critical)]'
                            "
                        >
                            {{ money(s.cash_diff) }}
                        </td>
                        <td>
                            <Badge :variant="s.status === 'open' ? 'warning' : 'secondary'">
                                {{ s.status === 'open' ? 'เปิดอยู่' : 'ปิดแล้ว' }}
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else description="ยังไม่มีรอบการขายในช่วงนี้" />

            <Pagination :links="shifts.links" :total="shifts.total" />
        </SectionCard>
    </BackOfficeLayout>
</template>
