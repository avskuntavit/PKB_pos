<script setup lang="ts">
/**
 * กระทบยอดเงินกับธนาคาร
 *
 * ── หน้านี้ตอบคำถามเดียว ───────────────────────────────────
 * "เงินของวันนั้นเข้าบัญชีบริษัทครบหรือยัง"
 * ตัวเลขที่เอาไปเทียบคือ **ยอดที่ควรเข้าบัญชี** ไม่ใช่ยอดขาย
 * เพราะบัตรถูกหักค่าธรรมเนียม แอปเดลิเวอรี่ถูกหัก GP
 * และเงินสดไม่เข้าเองเลย ต้องรอพนักงานโอน
 *
 * ── ทำไมไม่ต้องกรอกยอดก็กดกระทบได้ ────────────────────────
 * ส่วนใหญ่ยอดตรงอยู่แล้ว ถ้าบังคับพิมพ์เลขเดิมซ้ำทุกวันจะไม่มีใครใช้
 * กรอกเมื่อไหร่ = เห็นเลขอื่นในสเตทเมนต์ ระบบจะติดธงว่าไม่ตรงให้ทันที
 */
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { AlertTriangle, Check, Download, RotateCcw, Search } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import StatCard from '@/components/backoffice/StatCard.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { date, money, number } from '@/lib/format'

interface ChannelRow {
    id: number | null
    channel: string
    label: string
    gross: number
    fee: number
    refund: number
    expected: number
    declared: number | null
    settlement_status: string | null
    actual: number | null
    diff: number
    status: string
    status_label: string
    reference: string | null
    note: string | null
    reconciled_at: string | null
    reconciled_by: string | null
    payment_count: number
}

interface DayRow {
    business_date: string
    channels: ChannelRow[]
    expected_total: number
    actual_total: number
    diff_total: number
    open_count: number
}

const props = defineProps<{
    days: DayRow[]
    channel_totals: Array<{
        channel: string
        label: string
        expected: number
        fee: number
        diff: number
        open_count: number
    }>
    totals: { day_count: number; expected: number; actual: number; diff: number; open_count: number }
    filters: { from: string; to: string }
}>()

const STATUS_CLASS: Record<string, string> = {
    pending: 'bg-muted text-muted-foreground',
    matched: 'bg-[var(--status-good)]/15 text-[var(--status-good)]',
    mismatched: 'bg-[var(--status-critical)]/15 text-[var(--status-critical)]',
}

/* ---------- ช่วงวันที่ ---------- */

const from = ref(props.filters.from)
const to = ref(props.filters.to)

function apply() {
    router.get(
        '/backoffice/bank-reconciliation',
        { from: from.value, to: to.value },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

/** เดือนปัจจุบัน / เดือนที่แล้ว — สองช่วงที่คนปิดบัญชีใช้จริง */
function month(offset: number) {
    const now = new Date()
    const start = new Date(now.getFullYear(), now.getMonth() + offset, 1)
    const end = offset === 0 ? now : new Date(now.getFullYear(), now.getMonth() + offset + 1, 0)

    from.value = start.toLocaleDateString('sv-SE')
    to.value = end.toLocaleDateString('sv-SE')
    apply()
}

const exportUrl = computed(
    () => `/backoffice/bank-reconciliation/export?${new URLSearchParams({ from: props.filters.from, to: props.filters.to })}`,
)

/* ---------- กรองเฉพาะที่ยังค้าง ---------- */

const openOnly = ref(false)

const visibleDays = computed(() =>
    openOnly.value
        ? props.days
              .map((d) => ({ ...d, channels: d.channels.filter((c) => c.status !== 'matched') }))
              .filter((d) => d.channels.length)
        : props.days,
)

/* ---------- กระทบยอด ---------- */

const editing = ref<{ day: DayRow; row: ChannelRow } | null>(null)
const undoing = ref<number | null>(null)

const form = useForm({
    business_date: '',
    channel: '',
    actual_amount: '' as string,
    reference: '',
    note: '',
})

function openReconcile(day: DayRow, row: ChannelRow) {
    editing.value = { day, row }
    form.clearErrors()
    form.business_date = day.business_date
    form.channel = row.channel
    form.actual_amount = row.actual === null ? '' : String(row.actual)
    form.reference = row.reference ?? ''
    form.note = row.note ?? ''
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            // ช่องว่าง = ยืนยันว่าตรงตามที่ระบบคำนวณ ไม่ใช่ศูนย์บาท
            actual_amount: data.actual_amount === '' ? null : Number(data.actual_amount),
        }))
        .post('/backoffice/bank-reconciliation', {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
        })
}

function undo(row: ChannelRow) {
    if (row.id === null || undoing.value !== null) return

    undoing.value = row.id

    router.post(
        `/backoffice/bank-reconciliation/${row.id}/undo`,
        {},
        { preserveScroll: true, onFinish: () => (undoing.value = null) },
    )
}

/** ส่วนต่างที่จะเกิดขึ้นถ้ากดบันทึกตอนนี้ — ให้เห็นก่อนกด ไม่ใช่หลังกด */
const previewDiff = computed(() => {
    if (! editing.value) return 0
    if (form.actual_amount === '') return 0

    return Number(form.actual_amount) - editing.value.row.expected
})
</script>

<template>
    <Head title="กระทบยอดเงินกับธนาคาร" />

    <BackOfficeLayout title="กระทบยอดเงินกับธนาคาร">
        <div class="space-y-4">
            <!-- ช่วงวันที่ -->
            <div class="rounded-xl border bg-card p-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="space-y-1">
                        <Label for="from">ตั้งแต่วันขาย</Label>
                        <Input id="from" v-model="from" type="date" class="w-[160px]" />
                    </div>
                    <div class="space-y-1">
                        <Label for="to">ถึงวันขาย</Label>
                        <Input id="to" v-model="to" type="date" class="w-[160px]" />
                    </div>
                    <Button variant="brand" @click="apply">
                        <Search />
                        ตกลง
                    </Button>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" @click="month(0)">เดือนนี้</Button>
                    <Button variant="outline" size="sm" @click="month(-1)">เดือนที่แล้ว</Button>
                    <a
                        :href="exportUrl"
                        class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm hover:bg-accent"
                    >
                        <Download class="size-4" />
                        ดาวน์โหลด CSV
                    </a>
                </div>
            </div>

            <!-- ยังไม่ได้กระทบ -->
            <div
                v-if="totals.open_count"
                class="rounded-xl border-2 border-[var(--status-warning)] bg-[var(--status-warning)]/8 p-4"
                role="alert"
            >
                <div class="flex items-start gap-2.5">
                    <AlertTriangle class="mt-0.5 size-5 shrink-0 text-[var(--status-warning)]" />
                    <div class="min-w-0">
                        <p class="font-semibold">
                            ยังไม่ได้กระทบ {{ number(totals.open_count) }} รายการ ในช่วงที่เลือก
                        </p>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            แต่ละรายการคือเงินหนึ่งช่องทางของหนึ่งวัน ที่ยังไม่มีใครยืนยันว่าเข้าบัญชีแล้ว
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="ควรเข้าบัญชีรวม" :value="money(totals.expected)" unit="บาท" />
                <StatCard label="ยืนยันแล้ว" :value="money(totals.actual)" unit="บาท" />
                <StatCard label="ส่วนต่างสะสม" :value="money(totals.diff)" unit="บาท" />
                <StatCard label="ยังไม่กระทบ" :value="number(totals.open_count)" unit="รายการ" />
            </div>

            <!-- สรุปตามช่องทาง -->
            <SectionCard v-if="channel_totals.length" title="สรุปตามช่องทาง">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead>
                            <tr class="border-b text-left text-xs text-muted-foreground">
                                <th class="py-2 pe-3 font-medium">ช่องทาง</th>
                                <th class="py-2 pe-3 text-right font-medium">ควรเข้าบัญชี</th>
                                <th class="py-2 pe-3 text-right font-medium">ค่าธรรมเนียม/GP</th>
                                <th class="py-2 pe-3 text-right font-medium">ส่วนต่าง</th>
                                <th class="py-2 text-right font-medium">ยังไม่กระทบ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="t in channel_totals" :key="t.channel">
                                <td class="py-2.5 pe-3">{{ t.label }}</td>
                                <td class="tabular py-2.5 pe-3 text-right font-medium">{{ money(t.expected) }}</td>
                                <td class="tabular py-2.5 pe-3 text-right text-muted-foreground">
                                    {{ t.fee ? money(t.fee) : '-' }}
                                </td>
                                <td
                                    class="tabular py-2.5 pe-3 text-right"
                                    :class="t.diff !== 0 && 'font-semibold text-[var(--status-critical)]'"
                                >
                                    {{ money(t.diff) }}
                                </td>
                                <td class="tabular py-2.5 text-right">
                                    {{ t.open_count ? `${number(t.open_count)} วัน` : '-' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </SectionCard>

            <!-- รายวัน -->
            <SectionCard title="รายวันแยกตามช่องทาง">
                <label class="mb-3 flex items-center gap-2 text-sm">
                    <input v-model="openOnly" type="checkbox" class="size-4 rounded border" />
                    แสดงเฉพาะที่ยังไม่กระทบ
                </label>

                <div v-if="visibleDays.length" class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-sm">
                        <thead>
                            <tr class="border-b text-left text-xs text-muted-foreground">
                                <th class="py-2 pe-3 font-medium">ช่องทาง</th>
                                <th class="py-2 pe-3 text-right font-medium">รับจากลูกค้า</th>
                                <th class="py-2 pe-3 text-right font-medium">ค่าธรรมเนียม</th>
                                <th class="py-2 pe-3 text-right font-medium">คืนเงิน</th>
                                <th class="py-2 pe-3 text-right font-medium">ควรเข้าบัญชี</th>
                                <th class="py-2 pe-3 text-right font-medium">เข้าบัญชีจริง</th>
                                <th class="py-2 pe-3 text-right font-medium">ส่วนต่าง</th>
                                <th class="py-2 pe-3 font-medium">สถานะ</th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <template v-for="day in visibleDays" :key="day.business_date">
                                <tr class="bg-muted/40">
                                    <td class="py-2 pe-3 font-semibold" colspan="4">
                                        {{ date(day.business_date) }}
                                    </td>
                                    <td class="tabular py-2 pe-3 text-right font-semibold">
                                        {{ money(day.expected_total) }}
                                    </td>
                                    <td class="tabular py-2 pe-3 text-right">{{ money(day.actual_total) }}</td>
                                    <td
                                        class="tabular py-2 pe-3 text-right"
                                        :class="day.diff_total !== 0 && 'font-semibold text-[var(--status-critical)]'"
                                    >
                                        {{ money(day.diff_total) }}
                                    </td>
                                    <td class="py-2 pe-3 text-xs text-muted-foreground" colspan="2">
                                        {{ day.open_count ? `ค้าง ${day.open_count} ช่องทาง` : 'กระทบครบแล้ว' }}
                                    </td>
                                </tr>

                                <tr v-for="row in day.channels" :key="`${day.business_date}-${row.channel}`">
                                    <td class="py-2.5 pe-3 ps-4">
                                        {{ row.label }}
                                        <span
                                            v-if="row.channel === 'cash' && row.declared !== null && row.declared !== row.expected"
                                            class="block text-xs text-[var(--status-warning)]"
                                        >
                                            พนักงานแจ้งโอน {{ money(row.declared) }}
                                        </span>
                                    </td>
                                    <td class="tabular py-2.5 pe-3 text-right">{{ money(row.gross) }}</td>
                                    <td class="tabular py-2.5 pe-3 text-right text-muted-foreground">
                                        {{ row.fee ? money(row.fee) : '-' }}
                                    </td>
                                    <td class="tabular py-2.5 pe-3 text-right text-muted-foreground">
                                        {{ row.refund ? money(row.refund) : '-' }}
                                    </td>
                                    <td class="tabular py-2.5 pe-3 text-right font-medium">{{ money(row.expected) }}</td>
                                    <td class="tabular py-2.5 pe-3 text-right">
                                        {{ row.actual === null ? '-' : money(row.actual) }}
                                    </td>
                                    <td
                                        class="tabular py-2.5 pe-3 text-right"
                                        :class="row.diff !== 0 && 'font-semibold text-[var(--status-critical)]'"
                                    >
                                        {{ row.diff ? money(row.diff) : '-' }}
                                    </td>
                                    <td class="py-2.5 pe-3">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs"
                                            :class="STATUS_CLASS[row.status] ?? 'bg-muted'"
                                        >
                                            {{ row.status_label }}
                                        </span>
                                        <span v-if="row.reconciled_by" class="mt-0.5 block text-xs text-muted-foreground">
                                            {{ row.reconciled_by }}
                                        </span>
                                        <span v-if="row.note" class="mt-0.5 block text-xs text-muted-foreground">
                                            {{ row.note }}
                                        </span>
                                    </td>
                                    <td class="py-2.5">
                                        <div class="flex justify-end gap-1.5">
                                            <Button variant="brand" size="sm" @click="openReconcile(day, row)">
                                                <Check />
                                                {{ row.status === 'pending' ? 'กระทบ' : 'แก้ไข' }}
                                            </Button>
                                            <Button
                                                v-if="row.id !== null && row.status !== 'pending'"
                                                variant="outline"
                                                size="sm"
                                                :disabled="undoing === row.id"
                                                @click="undo(row)"
                                            >
                                                <Spinner v-if="undoing === row.id" class="size-3.5" />
                                                <RotateCcw v-else />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <EmptyState
                    v-else
                    title="ไม่มีรายการในช่วงที่เลือก"
                    description="ลองขยายช่วงวันที่ หรือเอาเครื่องหมายถูก แสดงเฉพาะที่ยังไม่กระทบ ออก"
                />
            </SectionCard>
        </div>

        <Modal
            :open="editing !== null"
            title="กระทบยอดกับสเตทเมนต์"
            description="ปล่อยช่องยอดว่างไว้ = ยืนยันว่าตรงตามที่ระบบคำนวณ กรอกเมื่อเห็นเลขอื่นในสเตทเมนต์"
            @update:open="(value?: boolean) => { if (! value) editing = null }"
        >
            <form v-if="editing" class="space-y-3" @submit.prevent="submit">
                <div class="rounded-lg bg-muted/50 p-3 text-sm">
                    <p class="font-medium">{{ date(editing.day.business_date) }} · {{ editing.row.label }}</p>
                    <p class="tabular mt-1">
                        ควรเข้าบัญชี <span class="font-semibold">{{ money(editing.row.expected) }}</span> บาท
                    </p>
                    <p v-if="editing.row.fee" class="mt-0.5 text-xs text-muted-foreground">
                        รับจากลูกค้า {{ money(editing.row.gross) }} หักค่าธรรมเนียม {{ money(editing.row.fee) }}
                    </p>
                    <p v-if="editing.row.channel === 'cash'" class="mt-0.5 text-xs text-muted-foreground">
                        เงินสดใช้ยอดจากใบนำส่งของวันนั้น ไม่ใช่ยอดขายเงินสด
                    </p>
                </div>

                <div class="space-y-1">
                    <Label for="actual">ยอดที่เข้าบัญชีจริง (ถ้าไม่ตรง)</Label>
                    <Input
                        id="actual"
                        v-model="form.actual_amount"
                        type="number"
                        step="0.01"
                        :placeholder="money(editing.row.expected)"
                    />
                    <p v-if="form.errors.actual_amount" class="text-xs text-[var(--status-critical)]">
                        {{ form.errors.actual_amount }}
                    </p>
                    <p v-else-if="previewDiff !== 0" class="text-xs text-[var(--status-critical)]">
                        จะบันทึกเป็นส่วนต่าง {{ money(previewDiff) }} บาท
                    </p>
                </div>

                <div class="space-y-1">
                    <Label for="reference">เลขอ้างอิงในสเตทเมนต์</Label>
                    <Input id="reference" v-model="form.reference" placeholder="เช่น รอบจ่ายของแอป หรือเลขรายการธนาคาร" />
                </div>

                <div class="space-y-1">
                    <Label for="note">หมายเหตุ</Label>
                    <Input id="note" v-model="form.note" placeholder="เช่น แอปหัก GP เพิ่มจากโปรโมชั่น" />
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="editing = null">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="form.processing">
                        <Spinner v-if="form.processing" class="size-3.5" />
                        บันทึก
                    </Button>
                </div>
            </form>
        </Modal>
    </BackOfficeLayout>
</template>
