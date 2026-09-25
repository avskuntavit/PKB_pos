<script setup lang="ts">
import { computed, ref } from 'vue'
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

const props = defineProps<{
    vouchers: Paginated<Record<string, any>>
    bases: Array<{ value: string; label: string; hint: string }>
    /** ค่าเริ่มต้นที่สาขานี้ตั้งไว้ — เลือกไว้ให้ล่วงหน้า แก้ได้ต่อคูปอง */
    defaultBase: string
}>()

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
    // ตั้งต้นจากค่าของสาขา ไม่ใช่ค่าคงที่ในหน้าจอ — ร้านตั้งไว้แล้วต้องไม่ต้องมาเลือกซ้ำทุกใบ
    base_mode: props.defaultBase,
})

/** คำอธิบายของตัวเลือกที่กำลังเลือกอยู่ — โชว์ใต้ช่องเลย ไม่ต้องเดา */
const baseHint = computed(() => props.bases.find((b) => b.value === form.base_mode)?.hint ?? '')

const baseLabel = (value: string) => props.bases.find((b) => b.value === value)?.label ?? value

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
                        <th>ฐานที่ใช้คิด</th>
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
                        <td class="text-muted-foreground">{{ baseLabel(v.base_mode) }}</td>
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
                        <p v-if="form.errors.code" class="text-xs text-[var(--status-critical)]">
                            {{ form.errors.code }}
                        </p>
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
                        <Label for="vmin">ยอดขั้นต่ำ (บาท)</Label>
                        <Input id="vmin" v-model="form.min_spend" type="number" step="0.01" min="0" />
                        <p v-if="form.errors.min_spend" class="text-xs text-[var(--status-critical)]">
                            {{ form.errors.min_spend }}
                        </p>
                    </div>
                    <div class="space-y-1">
                        <Label for="vmax">ลดได้ไม่เกิน (บาท)</Label>
                        <Input id="vmax" v-model="form.max_discount" type="number" step="0.01" min="0" placeholder="ไม่จำกัด" />
                    </div>
                </div>

                <!-- ฐานที่ใช้คิด — ตัวนี้เปลี่ยนความหมายของคูปองทั้งใบ จึงมีคำอธิบายกำกับ -->
                <div class="space-y-1">
                    <Label for="vbase">ฐานที่ใช้คิด (ทั้งยอดขั้นต่ำและมูลค่าที่ลด)</Label>
                    <Select id="vbase" v-model="form.base_mode">
                        <option v-for="b in bases" :key="b.value" :value="b.value">{{ b.label }}</option>
                    </Select>
                    <p class="text-xs text-muted-foreground">{{ baseHint }}</p>
                    <p v-if="form.base_mode === defaultBase" class="text-xs text-muted-foreground">
                        ค่าเริ่มต้นของสาขานี้ — เปลี่ยนที่ตั้งค่าสาขาได้ถ้าอยากให้คูปองใหม่ใช้อีกแบบ
                    </p>
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
