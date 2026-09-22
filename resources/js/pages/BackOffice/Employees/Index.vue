<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { BadgeCheck, Check, X } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import { dateTime, money } from '@/lib/format'

interface Row {
    id: number
    name: string
    phone: string | null
    employee_code: string | null
    employee_department: string | null
    status: string | null
    status_label: string | null
    note: string | null
    requested_at: string | null
    reviewed_at: string | null
    reviewer: string | null
    used_this_month: number
}

defineProps<{ pending: Row[]; approved: Row[]; rejected: Row[] }>()

const rejecting = ref<Row | null>(null)
const revoking = ref<Row | null>(null)
const note = ref('')

function approve(row: Row) {
    router.post(`/backoffice/employees/${row.id}/approve`, {}, { preserveScroll: true })
}

function reject() {
    if (!rejecting.value || !note.value.trim()) return

    router.post(
        `/backoffice/employees/${rejecting.value.id}/reject`,
        { note: note.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                rejecting.value = null
                note.value = ''
            },
        },
    )
}

function revoke() {
    if (!revoking.value) return

    router.post(
        `/backoffice/employees/${revoking.value.id}/revoke`,
        { note: note.value || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                revoking.value = null
                note.value = ''
            },
        },
    )
}
</script>

<template>
    <Head title="สิทธิ์พนักงานองค์กร" />

    <BackOfficeLayout title="สิทธิ์พนักงานองค์กร">
        <SectionCard title="รออนุมัติ" content-class="p-0">
            <template #actions>
                <Badge v-if="pending.length" variant="warning">{{ pending.length }} คำขอ</Badge>
            </template>

            <p class="border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
                ระบบไม่ได้ต่อกับฐานข้อมูล HR จึงยืนยันตัวตนอัตโนมัติไม่ได้ —
                กรุณาตรวจรหัสพนักงานกับทะเบียนจริงก่อนกดอนุมัติ
            </p>

            <DataTable v-if="pending.length">
                <thead>
                    <tr>
                        <th>ชื่อ</th>
                        <th>เบอร์โทร</th>
                        <th>รหัสพนักงาน</th>
                        <th>แผนก</th>
                        <th>ยื่นเมื่อ</th>
                        <th class="text-right">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in pending" :key="row.id">
                        <td class="font-medium">{{ row.name }}</td>
                        <td class="tabular text-muted-foreground">{{ row.phone ?? '-' }}</td>
                        <td class="tabular font-medium">{{ row.employee_code }}</td>
                        <td class="text-muted-foreground">{{ row.employee_department ?? '-' }}</td>
                        <td class="text-muted-foreground">{{ dateTime(row.requested_at) }}</td>
                        <td>
                            <div class="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="text-[var(--status-critical)]"
                                    @click="rejecting = row"
                                >
                                    <X />
                                    ปฏิเสธ
                                </Button>
                                <Button variant="brand" size="sm" @click="approve(row)">
                                    <Check />
                                    อนุมัติ
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else title="ไม่มีคำขอค้าง" description="คำขอใหม่จะขึ้นที่นี่เอง" />
        </SectionCard>

        <SectionCard title="ได้รับสิทธิ์แล้ว" content-class="p-0">
            <DataTable v-if="approved.length">
                <thead>
                    <tr>
                        <th>ชื่อ</th>
                        <th>รหัสพนักงาน</th>
                        <th>แผนก</th>
                        <th class="text-right">ใช้สิทธิ์เดือนนี้</th>
                        <th>อนุมัติโดย</th>
                        <th class="text-right">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in approved" :key="row.id">
                        <td class="flex items-center gap-1.5 font-medium">
                            <BadgeCheck class="size-4 text-[var(--series-1)]" />
                            {{ row.name }}
                        </td>
                        <td class="tabular">{{ row.employee_code }}</td>
                        <td class="text-muted-foreground">{{ row.employee_department ?? '-' }}</td>
                        <td class="tabular text-right">{{ money(row.used_this_month) }}</td>
                        <td class="text-muted-foreground">
                            {{ row.reviewer ?? '-' }}
                            <span class="block text-xs">{{ dateTime(row.reviewed_at) }}</span>
                        </td>
                        <td class="text-right">
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-[var(--status-critical)]"
                                @click="revoking = row"
                            >
                                ถอนสิทธิ์
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
            <EmptyState v-else title="ยังไม่มีพนักงานที่ได้รับสิทธิ์" />
        </SectionCard>

        <SectionCard v-if="rejected.length" title="ไม่ผ่าน / ถอนสิทธิ์" content-class="p-0">
            <DataTable>
                <thead>
                    <tr>
                        <th>ชื่อ</th>
                        <th>รหัสพนักงาน</th>
                        <th>เหตุผล</th>
                        <th>เมื่อ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rejected" :key="row.id">
                        <td class="font-medium">{{ row.name }}</td>
                        <td class="tabular text-muted-foreground">{{ row.employee_code ?? '-' }}</td>
                        <td class="text-muted-foreground">{{ row.note ?? '-' }}</td>
                        <td class="text-muted-foreground">{{ dateTime(row.reviewed_at) }}</td>
                    </tr>
                </tbody>
            </DataTable>
        </SectionCard>

        <Modal
            :open="rejecting !== null"
            title="ปฏิเสธคำขอ"
            description="ลูกค้าจะเห็นเหตุผลนี้ในหน้าบัญชีของตัวเอง"
            @update:open="(v) => !v && (rejecting = null)"
        >
            <div class="space-y-3">
                <Input v-model="note" class="h-11" placeholder="เช่น ไม่พบรหัสนี้ในทะเบียนพนักงาน" />
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="preset in ['ไม่พบรหัสนี้ในทะเบียน', 'รหัสไม่ตรงกับชื่อ', 'พ้นสภาพพนักงานแล้ว']"
                        :key="preset"
                        variant="outline"
                        size="sm"
                        @click="note = preset"
                    >
                        {{ preset }}
                    </Button>
                </div>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="rejecting = null">ยกเลิก</Button>
                    <Button variant="destructive" :disabled="!note.trim()" @click="reject">ยืนยันปฏิเสธ</Button>
                </div>
            </div>
        </Modal>

        <Modal
            :open="revoking !== null"
            title="ถอนสิทธิ์พนักงาน"
            description="ประวัติการใช้สิทธิ์เดิมยังอยู่ครบ ใช้สำหรับกรณีพ้นสภาพพนักงาน"
            @update:open="(v) => !v && (revoking = null)"
        >
            <div class="space-y-3">
                <Input v-model="note" class="h-11" placeholder="เหตุผล (ไม่บังคับ)" />
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="revoking = null">ยกเลิก</Button>
                    <Button variant="destructive" @click="revoke">ยืนยันถอนสิทธิ์</Button>
                </div>
            </div>
        </Modal>
    </BackOfficeLayout>
</template>
