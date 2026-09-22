<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import { Plus } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Pagination from '@/components/ui/Pagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import { date, money, number } from '@/lib/format'
import type { Paginated } from '@/types'

defineProps<{ vouchers: Paginated<Record<string, any>> }>()

const showModal = ref(false)

const form = useForm({
    code: '',
    name: '',
    type: 'amount',
    value: 0,
    min_spend: 0,
    max_discount: null as number | null,
    usage_limit: 1,
    starts_at: '',
    ends_at: '',
})

function submit() {
    form.post('/backoffice/vouchers', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            showModal.value = false
        },
    })
}
</script>

<template>
    <Head title="Voucher" />

    <BackOfficeLayout title="Voucher">
        <SectionCard title="รหัสส่วนลด" content-class="p-0">
            <template #actions>
                <Button variant="brand" size="sm" @click="showModal = true">
                    <Plus />
                    สร้างรหัส
                </Button>
            </template>

            <DataTable v-if="vouchers.data.length">
                <thead>
                    <tr>
                        <th>รหัส</th>
                        <th>ชื่อ</th>
                        <th class="text-right">มูลค่า</th>
                        <th class="text-right">ใช้ไป/จำกัด</th>
                        <th>หมดอายุ</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="v in vouchers.data" :key="v.id">
                        <td class="tabular font-medium">{{ v.code }}</td>
                        <td>{{ v.name }}</td>
                        <td class="tabular text-right">
                            {{ v.type === 'percent' ? `${v.value}%` : money(v.value) }}
                        </td>
                        <td class="tabular text-right">
                            {{ number(v.used_count) }}/{{ number(v.usage_limit) }}
                        </td>
                        <td class="text-muted-foreground">{{ v.ends_at ? date(v.ends_at) : 'ไม่จำกัด' }}</td>
                        <td>
                            <Badge :variant="v.is_active ? 'success' : 'secondary'">
                                {{ v.is_active ? 'ใช้งาน' : 'ปิด' }}
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else description="ยังไม่มีรหัสส่วนลด" />

            <Pagination :links="vouchers.links" :total="vouchers.total" />
        </SectionCard>

        <Modal v-model:open="showModal" title="สร้างรหัสส่วนลด">
            <form class="space-y-3" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="vcode">รหัส</Label>
                        <Input id="vcode" v-model="form.code" required placeholder="SAVE50" />
                    </div>
                    <div class="space-y-1">
                        <Label for="vlimit">จำกัดการใช้ (ครั้ง)</Label>
                        <Input id="vlimit" v-model="form.usage_limit" type="number" min="1" required />
                    </div>
                </div>

                <div class="space-y-1">
                    <Label for="vname">ชื่อ</Label>
                    <Input id="vname" v-model="form.name" required />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="vtype">ประเภท</Label>
                        <Select id="vtype" v-model="form.type">
                            <option value="amount">ลดเป็นจำนวนเงิน</option>
                            <option value="percent">ลดเป็นเปอร์เซ็นต์</option>
                        </Select>
                    </div>
                    <div class="space-y-1">
                        <Label for="vvalue">มูลค่า</Label>
                        <Input id="vvalue" v-model="form.value" type="number" step="0.01" min="0" required />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="vstart">เริ่ม</Label>
                        <Input id="vstart" v-model="form.starts_at" type="date" />
                    </div>
                    <div class="space-y-1">
                        <Label for="vend">สิ้นสุด</Label>
                        <Input id="vend" v-model="form.ends_at" type="date" />
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="outline" @click="showModal = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="form.processing">บันทึก</Button>
                </div>
            </form>
        </Modal>
    </BackOfficeLayout>
</template>
