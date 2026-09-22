<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import FilterBar from '@/components/backoffice/FilterBar.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Pagination from '@/components/ui/Pagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import { dateTime, money } from '@/lib/format'
import type { Option, PageProps, Paginated } from '@/types'

const props = defineProps<{
    filters: { from: string; to: string; branch_ids: number[]; status?: string; type?: string; search?: string }
    orders: Paginated<Record<string, any>>
    statuses: Option[]
}>()

const page = usePage<PageProps>()
const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

function reload() {
    router.get(
        '/backoffice/sales',
        { ...props.filters, search: search.value, status: status.value },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

const statusVariant: Record<string, any> = {
    paid: 'success',
    open: 'warning',
    void: 'danger',
    refunded: 'secondary',
}
</script>

<template>
    <Head title="การขาย" />

    <BackOfficeLayout title="การขาย">
        <FilterBar :branches="page.props.branches" :filters="filters" action="/backoffice/sales" />

        <SectionCard title="รายการบิล" content-class="p-0">
            <template #actions>
                <div class="flex gap-2">
                    <Input
                        v-model="search"
                        placeholder="ค้นหาเลขที่บิล/ใบเสร็จ"
                        class="w-48"
                        @keyup.enter="reload"
                    />
                    <Select v-model="status" class="w-36" @change="reload">
                        <option value="">ทุกสถานะ</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </Select>
                </div>
            </template>

            <DataTable v-if="orders.data.length">
                <thead>
                    <tr>
                        <th>เลขที่บิล</th>
                        <th>โต๊ะ</th>
                        <th>ประเภท</th>
                        <th>เปิดบิล</th>
                        <th>ปิดบิล</th>
                        <th>พนักงาน</th>
                        <th class="text-right">ยอดสุทธิ</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="order in orders.data" :key="order.id">
                        <td>
                            <Link
                                :href="`/backoffice/sales/${order.id}`"
                                class="font-medium text-[var(--series-1)] hover:underline"
                            >
                                {{ order.order_no }}
                            </Link>
                        </td>
                        <td class="text-muted-foreground">{{ order.dining_table?.name ?? '-' }}</td>
                        <td class="text-muted-foreground">{{ order.type }}</td>
                        <td class="text-muted-foreground">{{ dateTime(order.opened_at) }}</td>
                        <td class="text-muted-foreground">{{ dateTime(order.closed_at) }}</td>
                        <td class="text-muted-foreground">{{ order.closed_by?.name ?? '-' }}</td>
                        <td class="tabular text-right font-medium">{{ money(order.grand_total) }}</td>
                        <td>
                            <Badge :variant="statusVariant[order.status] ?? 'secondary'">
                                {{ statuses.find((s) => s.value === order.status)?.label ?? order.status }}
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else description="ไม่พบบิลตามเงื่อนไขที่เลือก" />

            <Pagination :links="orders.links" :total="orders.total" />
        </SectionCard>
    </BackOfficeLayout>
</template>
