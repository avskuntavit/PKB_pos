<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import { CalendarCheck, Lock, LockOpen, RotateCcw, TriangleAlert } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Modal from '@/components/ui/Modal.vue'
import { dateTime, money, number } from '@/lib/format'

interface PeriodRow {
    period: string
    label: string
    status: 'open' | 'closed' | 'reopened'
    is_closed: boolean
    /** เดือนที่ยังขายอยู่ปิดไม่ได้ — ปุ่มต้องเทาไว้ ไม่ใช่กดแล้วค่อยขึ้น error */
    has_ended: boolean
    closed_at: string | null
    closed_by: string | null
    note: string | null
    reopened_at: string | null
    reopened_by: string | null
    reopen_reason: string | null
    times_reopened: number
    bills: number
    sales: number
    open_bills: number
}

const props = defineProps<{
    periods: PeriodRow[]
    branchName: string
    canReopen: boolean
    minReasonChars: number
}>()

const closing = ref<PeriodRow | null>(null)
const reopening = ref<PeriodRow | null>(null)

const closeForm = useForm({ period: '', note: '' })
const reopenForm = useForm({ period: '', reason: '' })

const lastClosed = computed(() => props.periods.find((p) => p.is_closed) ?? null)

function askClose(row: PeriodRow) {
    closing.value = row
    closeForm.reset()
    closeForm.period = row.period
}

function submitClose() {
    closeForm.post('/backoffice/periods/close', {
        preserveScroll: true,
        onSuccess: () => {
            closing.value = null
        },
    })
}

function askReopen(row: PeriodRow) {
    reopening.value = row
    reopenForm.reset()
    reopenForm.period = row.period
}

function submitReopen() {
    reopenForm.post('/backoffice/periods/reopen', {
        preserveScroll: true,
        onSuccess: () => {
            reopening.value = null
        },
    })
}

function statusVariant(row: PeriodRow) {
    if (row.is_closed) return 'success'

    return row.status === 'reopened' ? 'warning' : 'secondary'
}

function statusLabel(row: PeriodRow) {
    if (row.is_closed) return 'ปิดแล้ว'

    return row.status === 'reopened' ? 'เปิดกลับมา' : 'ยังเปิดอยู่'
}
</script>

<template>
    <Head title="ปิดงวดบัญชี" />

    <BackOfficeLayout title="ปิดงวดบัญชี">
        <SectionCard :title="'งวดของ ' + branchName" content-class="p-0">
            <div class="space-y-1 border-b px-4 py-3 text-xs text-muted-foreground">
                <p>
                    งวดที่ปิดแล้ว <strong>แก้บิลของเดือนนั้นไม่ได้อีก</strong> —
                    ทั้งการแก้รายการ รับชำระ คืนเงิน และทำลายบิล
                </p>
                <p>
                    สต๊อก ใบนำส่งเงินสด การกระทบยอดธนาคาร และการส่งข้อมูลให้ระบบบัญชี
                    <strong>ไม่ถูกล็อก</strong> เพราะเป็นงานที่มักทำเสร็จหลังปิดงวด
                </p>
                <p>งวดของแต่ละสถานีแยกกัน ปิดของสถานีนี้ไม่กระทบสถานีอื่น</p>
                <p v-if="lastClosed" class="font-medium text-foreground">
                    ปิดล่าสุดถึงงวด {{ lastClosed.label }}
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">งวด</th>
                            <th class="px-4 py-2 font-medium">สถานะ</th>
                            <th class="px-4 py-2 text-right font-medium">บิล</th>
                            <th class="px-4 py-2 text-right font-medium">ยอดขาย</th>
                            <th class="px-4 py-2 font-medium">รายละเอียด</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in periods" :key="row.period">
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <p class="font-medium">{{ row.label }}</p>
                                <p class="font-mono text-[11px] text-muted-foreground">{{ row.period }}</p>
                            </td>

                            <td class="whitespace-nowrap px-4 py-2.5">
                                <Badge :variant="statusVariant(row)">{{ statusLabel(row) }}</Badge>
                                <p
                                    v-if="row.times_reopened > 0"
                                    class="mt-1 text-[11px] text-[var(--status-warning)]"
                                >
                                    เคยเปิดกลับ {{ row.times_reopened }} ครั้ง
                                </p>
                            </td>

                            <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                {{ number(row.bills) }}
                                <p v-if="row.open_bills > 0" class="text-[11px] text-[var(--status-critical)]">
                                    เปิดค้าง {{ number(row.open_bills) }}
                                </p>
                            </td>

                            <td class="whitespace-nowrap px-4 py-2.5 text-right">{{ money(row.sales) }}</td>

                            <td class="px-4 py-2.5 text-xs text-muted-foreground">
                                <p v-if="row.closed_at">
                                    ปิดโดย {{ row.closed_by ?? '—' }} · {{ dateTime(row.closed_at) }}
                                </p>
                                <p v-if="row.note">บันทึก: {{ row.note }}</p>
                                <p v-if="row.reopened_at" class="text-[var(--status-warning)]">
                                    เปิดกลับโดย {{ row.reopened_by ?? '—' }} · {{ dateTime(row.reopened_at) }}
                                    <span v-if="row.reopen_reason"> — {{ row.reopen_reason }}</span>
                                </p>
                                <p v-if="!row.has_ended">เดือนนี้ยังไม่จบ ปิดไม่ได้</p>
                            </td>

                            <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                <Button
                                    v-if="!row.is_closed"
                                    size="sm"
                                    variant="outline"
                                    :disabled="!row.has_ended"
                                    @click="askClose(row)"
                                >
                                    <Lock />
                                    ปิดงวด
                                </Button>

                                <Button
                                    v-else-if="canReopen"
                                    size="sm"
                                    variant="ghost"
                                    @click="askReopen(row)"
                                >
                                    <LockOpen />
                                    เปิดกลับ
                                </Button>

                                <span v-else class="text-xs text-muted-foreground">ล็อกแล้ว</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </SectionCard>

        <!-- ยืนยันการปิดงวด -->
        <Modal
            :open="closing !== null"
            title="ปิดงวดบัญชี"
            @update:open="(value: boolean) => { if (!value) closing = null }"
        >
            <div v-if="closing" class="space-y-4">
                <div class="rounded-lg border border-[var(--status-warning)]/30 bg-[var(--status-warning)]/10 p-3 text-sm">
                    <p class="flex items-start gap-2">
                        <TriangleAlert class="mt-0.5 size-4 shrink-0 text-[var(--status-warning)]" />
                        <span>
                            หลังปิดงวด <strong>{{ closing.label }}</strong>
                            บิลทั้ง {{ number(closing.bills) }} ใบของเดือนนี้จะแก้ไม่ได้อีก
                            ทั้งการแก้รายการ คืนเงิน และทำลายบิล
                        </span>
                    </p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        ถ้าจำเป็นต้องแก้ทีหลัง เจ้าของระบบเปิดงวดกลับได้ แต่ต้องใส่เหตุผลไว้เป็นหลักฐาน
                    </p>
                </div>

                <div>
                    <Label for="close-note">บันทึกไว้ (ไม่บังคับ)</Label>
                    <Input
                        id="close-note"
                        v-model="closeForm.note"
                        placeholder="เช่น ยื่น ภ.พ.30 เดือนนี้แล้ว"
                        maxlength="255"
                    />
                    <p v-if="closeForm.errors.note" class="mt-1 text-xs text-destructive">
                        {{ closeForm.errors.note }}
                    </p>
                </div>

                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="closing = null">ยกเลิก</Button>
                    <Button :disabled="closeForm.processing" @click="submitClose">
                        <CalendarCheck />
                        {{ closeForm.processing ? 'กำลังปิด...' : 'ปิดงวดนี้' }}
                    </Button>
                </div>
            </div>
        </Modal>

        <!-- ยืนยันการเปิดงวดกลับ -->
        <Modal
            :open="reopening !== null"
            title="เปิดงวดที่ปิดแล้วกลับมา"
            @update:open="(value: boolean) => { if (!value) reopening = null }"
        >
            <div v-if="reopening" class="space-y-4">
                <div class="rounded-lg border border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 p-3 text-sm">
                    <p>
                        งวด <strong>{{ reopening.label }}</strong> อาจถูกยื่นภาษีและส่งให้ระบบบัญชีไปแล้ว
                        การแก้บิลย้อนหลังจะทำให้ตัวเลขในระบบไม่ตรงกับที่ยื่นไป
                    </p>
                    <p class="mt-2 text-xs">
                        เหตุผลที่เขียนจะถูกบันทึกถาวรพร้อมชื่อผู้เปิดและเวลา
                        <span v-if="reopening.times_reopened > 0">
                            (งวดนี้เคยถูกเปิดกลับมาแล้ว {{ reopening.times_reopened }} ครั้ง)
                        </span>
                    </p>
                </div>

                <div>
                    <Label for="reopen-reason">เหตุผล (อย่างน้อย {{ minReasonChars }} ตัวอักษร)</Label>
                    <Input
                        id="reopen-reason"
                        v-model="reopenForm.reason"
                        placeholder="เช่น ลูกค้าขอคืนเงินบิล #1234 ตามที่ตกลงกับผู้จัดการ"
                        maxlength="255"
                    />
                    <p v-if="reopenForm.errors.reason" class="mt-1 text-xs text-destructive">
                        {{ reopenForm.errors.reason }}
                    </p>
                </div>

                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="reopening = null">ยกเลิก</Button>
                    <Button
                        variant="destructive"
                        :disabled="reopenForm.processing"
                        @click="submitReopen"
                    >
                        <RotateCcw />
                        {{ reopenForm.processing ? 'กำลังเปิด...' : 'เปิดงวดกลับ' }}
                    </Button>
                </div>
            </div>
        </Modal>
    </BackOfficeLayout>
</template>
