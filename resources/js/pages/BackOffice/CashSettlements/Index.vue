<script setup lang="ts">
/**
 * ตรวจการนำส่งเงินสด — หน้าของผู้จัดการ
 *
 * ── ทำไมกล่อง "ไม่มีใบนำส่งเลย" อยู่บนสุด ──────────────────
 * วันที่ไม่มีใครกดนำส่ง อันตรายกว่าวันที่นำส่งแล้วยอดไม่ตรง
 * เพราะอย่างหลังอย่างน้อยมีคนรับผิดชอบและมีตัวเลขให้ไล่
 * ส่วนอย่างแรกคือเงินที่ไม่มีใครพูดถึงเลย ถ้าไล่จากตารางอย่างเดียวจะไม่เห็น
 */
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { AlertTriangle, Check, ExternalLink, X } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { money } from '@/lib/format'
import type { CashSettlementRow } from '@/types'

const props = defineProps<{
    settlements: CashSettlementRow[]
    statuses: Array<{ value: string; label: string }>
    filters: { status: string | null }
    missing: Array<{ business_date: string; expected: number }>
}>()

const status = ref(props.filters.status ?? '')

const STATUS_CLASS: Record<string, string> = {
    pending: 'bg-muted text-muted-foreground',
    submitted: 'bg-[var(--status-warning)]/15 text-[var(--status-warning)]',
    verified: 'bg-[var(--status-good)]/15 text-[var(--status-good)]',
    disputed: 'bg-[var(--status-critical)]/15 text-[var(--status-critical)]',
}

function applyFilter() {
    router.get(
        '/backoffice/cash-settlements',
        status.value ? { status: status.value } : {},
        { preserveState: true, replace: true },
    )
}

/* ---------- ยืนยัน / ตีกลับ ---------- */

const verifying = ref<number | null>(null)
const disputing = ref<CashSettlementRow | null>(null)
const disputeForm = useForm({ note: '' })

function verify(row: CashSettlementRow) {
    if (verifying.value) return

    verifying.value = row.id

    router.post(
        `/backoffice/cash-settlements/${row.id}/verify`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (verifying.value = null),
        },
    )
}

function openDispute(row: CashSettlementRow) {
    disputing.value = row
    disputeForm.reset()
    disputeForm.note = row.note ?? ''
}

function submitDispute() {
    if (! disputing.value) return

    disputeForm.post(`/backoffice/cash-settlements/${disputing.value.id}/dispute`, {
        preserveScroll: true,
        onSuccess: () => (disputing.value = null),
    })
}

const totalMissing = computed(() => props.missing.reduce((sum, m) => sum + m.expected, 0))
</script>

<template>
    <Head title="นำส่งเงินสดประจำวัน" />

    <BackOfficeLayout title="นำส่งเงินสดประจำวัน">
        <div class="space-y-4">
            <!-- วันที่ไม่มีใบนำส่งเลย -->
            <div
                v-if="missing.length"
                class="rounded-xl border-2 border-[var(--status-critical)] bg-[var(--status-critical)]/8 p-4"
                role="alert"
            >
                <div class="flex items-start gap-2.5">
                    <AlertTriangle class="mt-0.5 size-5 shrink-0 text-[var(--status-critical)]" />
                    <div class="min-w-0">
                        <p class="font-semibold text-[var(--status-critical)]">
                            มี {{ missing.length }} วันที่ยังไม่มีใครกดนำส่งเลย
                            รวม {{ money(totalMissing) }} บาท
                        </p>
                        <ul class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                            <li v-for="m in missing" :key="m.business_date">
                                {{ m.business_date }} · <span class="tabular">{{ money(m.expected) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <SectionCard title="รายการนำส่ง">
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <select
                        v-model="status"
                        class="h-9 rounded-md border bg-card px-2 text-sm"
                        @change="applyFilter"
                    >
                        <option value="">ทุกสถานะ</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>

                <div v-if="settlements.length" class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-sm">
                        <thead>
                            <tr class="border-b text-left text-xs text-muted-foreground">
                                <th class="py-2 pe-3 font-medium">วันขาย</th>
                                <th class="py-2 pe-3 text-right font-medium">จากบิล</th>
                                <th class="py-2 pe-3 text-right font-medium" title="เงินสดที่รับตอนเน็ตหลุดแต่ยังไม่มีบิลรองรับ">
                                    เงินค้าง
                                </th>
                                <th class="py-2 pe-3 text-right font-medium">ต้องนำส่ง</th>
                                <th class="py-2 pe-3 text-right font-medium">นับได้</th>
                                <th class="py-2 pe-3 text-right font-medium">โอนแล้ว</th>
                                <th class="py-2 pe-3 text-right font-medium">ส่วนต่าง</th>
                                <th class="py-2 pe-3 font-medium">สถานะ</th>
                                <th class="py-2 pe-3 font-medium">ผู้นำส่ง</th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in settlements" :key="row.id">
                                <td class="py-2.5 pe-3">
                                    {{ row.business_date }}
                                    <span
                                        v-if="row.status !== 'verified' && row.days_overdue > 1"
                                        class="block text-xs text-[var(--status-warning)]"
                                    >
                                        ค้างมา {{ row.days_overdue }} วัน
                                    </span>
                                </td>
                                <td class="tabular py-2.5 pe-3 text-right">{{ money(row.expected_amount) }}</td>
                                <td
                                    class="tabular py-2.5 pe-3 text-right"
                                    :class="row.held_cash_amount > 0 && 'font-semibold text-[var(--status-warning)]'"
                                >
                                    {{ row.held_cash_amount > 0 ? money(row.held_cash_amount) : '—' }}
                                </td>
                                <td class="tabular py-2.5 pe-3 text-right font-medium">{{ money(row.due_amount) }}</td>
                                <td class="tabular py-2.5 pe-3 text-right">{{ money(row.counted_amount) }}</td>
                                <td class="tabular py-2.5 pe-3 text-right">{{ money(row.transferred_amount) }}</td>
                                <td
                                    class="tabular py-2.5 pe-3 text-right"
                                    :class="row.diff_amount !== 0 && 'font-semibold text-[var(--status-critical)]'"
                                >
                                    {{ money(row.diff_amount) }}
                                </td>
                                <td class="py-2.5 pe-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs"
                                        :class="STATUS_CLASS[row.status] ?? 'bg-muted'"
                                    >
                                        {{ row.status_label }}
                                    </span>
                                    <span v-if="row.note" class="mt-0.5 block text-xs text-muted-foreground">
                                        {{ row.note }}
                                    </span>
                                </td>
                                <td class="py-2.5 pe-3">
                                    {{ row.settled_by ?? '-' }}
                                    <a
                                        v-if="row.slip_path"
                                        :href="row.slip_path"
                                        target="_blank"
                                        rel="noopener"
                                        class="mt-0.5 flex items-center gap-1 text-xs text-[var(--series-1)] hover:underline"
                                    >
                                        <ExternalLink class="size-3" />
                                        ดูสลิป
                                    </a>
                                </td>
                                <td class="py-2.5">
                                    <div v-if="row.status !== 'verified'" class="flex justify-end gap-1.5">
                                        <Button
                                            variant="brand"
                                            size="sm"
                                            :disabled="verifying === row.id || row.status === 'pending'"
                                            @click="verify(row)"
                                        >
                                            <Spinner v-if="verifying === row.id" class="size-3.5" />
                                            <Check v-else />
                                            เงินเข้าแล้ว
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            :disabled="row.status === 'pending'"
                                            @click="openDispute(row)"
                                        >
                                            <X />
                                            ไม่ตรง
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <EmptyState
                    v-else
                    title="ยังไม่มีรายการนำส่ง"
                    description="รายการจะขึ้นเมื่อพนักงานกดนำส่งเงินสดจากหน้าขาย"
                />
            </SectionCard>
        </div>

        <Modal
            :open="disputing !== null"
            title="ยอดไม่ตรงกับที่เข้าบัญชี"
            description="ระบุว่าติดตรงไหน คนที่มารับเรื่องต่อจะได้ไม่ต้องเริ่มจากศูนย์"
            @update:open="(value?: boolean) => { if (! value) disputing = null }"
        >
            <form class="space-y-3" @submit.prevent="submitDispute">
                <Input v-model="disputeForm.note" placeholder="เช่น เข้าบัญชีแค่ 3,000 จาก 3,500" required />
                <p v-if="disputeForm.errors.note" class="text-xs text-[var(--status-critical)]">
                    {{ disputeForm.errors.note }}
                </p>
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="disputing = null">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="disputeForm.processing">บันทึก</Button>
                </div>
            </form>
        </Modal>
    </BackOfficeLayout>
</template>
