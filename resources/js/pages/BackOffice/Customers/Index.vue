<script setup lang="ts">
import { ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { UserPlus } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Pagination from '@/components/ui/Pagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import { date, money, number } from '@/lib/format'
import type { Paginated } from '@/types'

const props = defineProps<{
    customers: Paginated<Record<string, any>>
    filters: { search?: string }
}>()

const search = ref(props.filters.search ?? '')
const showModal = ref(false)

const form = useForm({ name: '', phone: '', email: '', birthdate: '', note: '' })

function reload() {
    router.get('/backoffice/customers', { search: search.value }, { preserveState: true, replace: true })
}

function submit() {
    form.post('/backoffice/customers', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            showModal.value = false
        },
    })
}

const tierVariant: Record<string, any> = { gold: 'warning', silver: 'secondary', platinum: 'default', regular: 'outline' }
</script>

<template>
    <Head title="ลูกค้า" />

    <BackOfficeLayout title="ลูกค้า">
        <SectionCard title="รายชื่อลูกค้า" content-class="p-0">
            <template #actions>
                <div class="flex gap-2">
                    <Input v-model="search" placeholder="ค้นหาชื่อ/เบอร์โทร" class="w-48" @keyup.enter="reload" />
                    <Button variant="brand" size="sm" @click="showModal = true">
                        <UserPlus />
                        เพิ่มลูกค้า
                    </Button>
                </div>
            </template>

            <DataTable v-if="customers.data.length">
                <thead>
                    <tr>
                        <th>ชื่อ</th>
                        <th>เบอร์โทร</th>
                        <th>ระดับ</th>
                        <th class="text-right">แต้มสะสม</th>
                        <th class="text-right">ยอดซื้อสะสม</th>
                        <th class="text-right">จำนวนครั้ง</th>
                        <th>มาล่าสุด</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in customers.data" :key="c.id">
                        <td class="font-medium">{{ c.name }}</td>
                        <td class="tabular text-muted-foreground">{{ c.phone ?? '-' }}</td>
                        <td><Badge :variant="tierVariant[c.tier] ?? 'outline'">{{ c.tier }}</Badge></td>
                        <td class="tabular text-right">{{ number(c.points) }}</td>
                        <td class="tabular text-right font-medium">{{ money(c.total_spent) }}</td>
                        <td class="tabular text-right">{{ number(c.visit_count) }}</td>
                        <td class="text-muted-foreground">{{ date(c.last_visit_at) }}</td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else description="ยังไม่มีลูกค้าในระบบ" />

            <Pagination :links="customers.links" :total="customers.total" />
        </SectionCard>

        <Modal v-model:open="showModal" title="เพิ่มลูกค้า">
            <form class="space-y-3" @submit.prevent="submit">
                <div class="space-y-1">
                    <Label for="cname">ชื่อ</Label>
                    <Input id="cname" v-model="form.name" required />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="phone">เบอร์โทร</Label>
                        <Input id="phone" v-model="form.phone" />
                    </div>
                    <div class="space-y-1">
                        <Label for="bd">วันเกิด</Label>
                        <Input id="bd" v-model="form.birthdate" type="date" />
                    </div>
                </div>
                <div class="space-y-1">
                    <Label for="email">อีเมล</Label>
                    <Input id="email" v-model="form.email" type="email" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="outline" @click="showModal = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="form.processing">บันทึก</Button>
                </div>
            </form>
        </Modal>
    </BackOfficeLayout>
</template>
