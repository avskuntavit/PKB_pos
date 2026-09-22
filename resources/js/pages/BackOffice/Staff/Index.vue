<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import FilterBar from '@/components/backoffice/FilterBar.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Badge from '@/components/ui/Badge.vue'
import { number } from '@/lib/format'
import type { PageProps } from '@/types'

defineProps<{
    staff: Array<Record<string, any>>
    activity: Array<{ user_id: number; name: string; total: number; actions: Record<string, number> }>
    actionLabels: Record<string, string>
    filters: { from: string; to: string }
}>()

const page = usePage<PageProps>()

/** แสดงเฉพาะ action หลักที่ดูแล้วเข้าใจทันที ไม่ให้ตารางกว้างเกิน */
const mainActions = ['order.open', 'order.item_sent', 'order.pay', 'order.void', 'order.item_void']
</script>

<template>
    <Head title="พนักงาน" />

    <BackOfficeLayout title="พนักงาน">
        <FilterBar :branches="page.props.branches" :filters="filters" action="/backoffice/staff" />

        <SectionCard title="กิจกรรมของพนักงาน" content-class="p-0">
            <DataTable v-if="activity.length">
                <thead>
                    <tr>
                        <th>พนักงาน</th>
                        <th v-for="a in mainActions" :key="a" class="text-right">{{ actionLabels[a] }}</th>
                        <th class="text-right">รวม</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in activity" :key="row.user_id">
                        <td class="font-medium">{{ row.name }}</td>
                        <td v-for="a in mainActions" :key="a" class="tabular text-right">
                            {{ number(row.actions[a] ?? 0) }}
                        </td>
                        <td class="tabular text-right font-medium">{{ number(row.total) }}</td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else description="ยังไม่มีกิจกรรมในช่วงที่เลือก" />
        </SectionCard>

        <SectionCard title="รายชื่อพนักงาน" content-class="p-0">
            <DataTable>
                <thead>
                    <tr>
                        <th>ชื่อ</th>
                        <th>รหัสพนักงาน</th>
                        <th>อีเมล</th>
                        <th>ตำแหน่ง</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in staff" :key="s.id">
                        <td class="font-medium">{{ s.name }}</td>
                        <td class="tabular text-muted-foreground">{{ s.employee_code ?? '-' }}</td>
                        <td class="text-muted-foreground">{{ s.email }}</td>
                        <td>{{ s.role }}</td>
                        <td>
                            <Badge :variant="s.is_active ? 'success' : 'secondary'">
                                {{ s.is_active ? 'ทำงานอยู่' : 'ปิดใช้งาน' }}
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
        </SectionCard>
    </BackOfficeLayout>
</template>
