<script setup lang="ts">
/**
 * เงินที่รับจากลูกค้าไปแล้วตอนระบบล่ม แต่ลงบิลไม่ได้
 *
 * หน้านี้ตอบคำถามเดียว: "เงินก้อนนี้ไปไหน"
 * ระบบไม่เปิดบิลใหม่ให้เอง ผู้จัดการทำด้วยมือแล้วมาบันทึกว่าทำอะไรไป
 */
import { computed, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import { Wallet, CheckCircle2, PackageMinus, TriangleAlert } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Modal from '@/components/ui/Modal.vue'
import Select from '@/components/ui/Select.vue'
import { dateTime, money } from '@/lib/format'

interface HoldRow {
    id: number
    uuid: string
    kind: string
    kind_label: string
    amount: number
    expected_total: number | null
    message: string | null
    warning: string | null
    order_no: string | null
    order_id: number
    client_at: string | null
    created_at: string | null
    resolution: string | null
    resolution_label: string | null
    resolution_note: string | null
    resolved_by: string | null
    resolved_at: string | null
}

const props = defineProps<{
    branch: { id: number; name: string }
    holds: HoldRow[]
    resolved: HoldRow[]
    warnings: HoldRow[]
    resolutions: Array<{ value: string; label: string }>
    openTotal: number
}>()

const deciding = ref<HoldRow | null>(null)

const form = useForm({ resolution: '', note: '' })

function ask(row: HoldRow) {
    deciding.value = row
    form.reset()
    form.resolution = props.resolutions[0]?.value ?? ''
}

function submit() {
    if (!deciding.value) return

    form.post(`/backoffice/offline-holds/${deciding.value.id}/resolve`, {
        preserveScroll: true,
        onSuccess: () => (deciding.value = null),
    })
}

const canSubmit = computed(
    () => form.resolution !== '' && form.note.trim().length >= 10 && !form.processing,
)

/** ยอดบิลตอนเก็บเงิน ต่างจากยอดตอนนี้ไหม — เป็นสาเหตุที่พบบ่อยที่สุดที่ทำให้ลงไม่ได้ */
function totalsDiffer(row: HoldRow): boolean {
    return row.expected_total !== null && Math.abs(row.expected_total - row.amount) > 0.01
}
</script>

<template>
    <Head title="เงินค้างจากตอนระบบล่ม" />

    <BackOfficeLayout>
        <div class="space-y-6">
            <header class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold">เงินค้างจากตอนระบบล่ม</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ branch.name }} · เงินที่พนักงานรับจากลูกค้าไปแล้ว แต่ระบบลงบิลให้ไม่ได้
                    </p>
                </div>

                <div v-if="holds.length" class="rounded-xl border border-[var(--status-critical)]/40 bg-[var(--status-critical)]/10 px-4 py-2 text-right">
                    <p class="text-xs text-muted-foreground">รอตัดสิน</p>
                    <p class="tabular text-xl font-bold text-[var(--status-critical)]">
                        {{ money(openTotal) }} ฿
                    </p>
                </div>
            </header>

            <!-- ══ รอตัดสิน ══ -->
            <SectionCard :title="`รอผู้จัดการตัดสิน (${holds.length})`" content-class="p-0">
                <template #actions>
                    <Wallet class="size-4 shrink-0 text-[var(--status-critical)]" />
                </template>

                <EmptyState
                    v-if="!holds.length"
                    title="ไม่มีเงินค้าง"
                    description="ทุกครั้งที่รับเงินตอนระบบล่ม ลงบิลสำเร็จหมด"
                />

                <ul v-else class="divide-y">
                    <li v-for="row in holds" :key="row.id" class="px-4 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 space-y-1">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="tabular text-lg font-bold">{{ money(row.amount) }} ฿</span>
                                    <Badge variant="outline">{{ row.kind_label }}</Badge>
                                    <span v-if="row.order_no" class="text-sm text-muted-foreground">
                                        บิล {{ row.order_no }}
                                    </span>
                                </p>

                                <!--
                                    ข้อความจากเซิร์ฟเวอร์คือเหตุผลที่ลงไม่ได้
                                    ต้องอยู่ใกล้ตัวเลขที่สุด เพราะเป็นสิ่งแรกที่ผู้จัดการต้องอ่าน
                                -->
                                <p v-if="row.message" class="text-sm text-[var(--status-critical)]">
                                    {{ row.message }}
                                </p>

                                <p v-if="totalsDiffer(row)" class="text-xs text-muted-foreground">
                                    เงินที่รับมา {{ money(row.amount) }} ฿ ·
                                    ยอดบิลตอนเก็บเงิน {{ money(row.expected_total ?? 0) }} ฿
                                </p>

                                <p class="text-xs text-muted-foreground">
                                    พนักงานกดเมื่อ
                                    <template v-if="row.client_at">{{ dateTime(row.client_at) }}</template>
                                    <template v-else>—</template>
                                    · ส่งขึ้นระบบ {{ row.created_at ? dateTime(row.created_at) : '—' }}
                                </p>
                            </div>

                            <Button variant="brand" @click="ask(row)">ตัดสิน</Button>
                        </div>
                    </li>
                </ul>
            </SectionCard>

            <!-- ══ เตือนเรื่องสต๊อก ══ -->
            <SectionCard
                v-if="warnings.length"
                title="ลงบิลสำเร็จ แต่มีเรื่องต้องดู (7 วันล่าสุด)"
                content-class="p-0"
            >
                <template #actions>
                    <PackageMinus class="size-4 shrink-0 text-[var(--status-warning)]" />
                </template>

                <ul class="divide-y">
                    <li v-for="row in warnings" :key="row.id" class="px-4 py-3">
                        <p class="text-sm">
                            <span v-if="row.order_no" class="font-medium">บิล {{ row.order_no }}</span>
                            <span class="text-muted-foreground"> · {{ row.created_at ? dateTime(row.created_at) : '—' }}</span>
                        </p>
                        <p class="mt-0.5 flex items-start gap-1.5 text-sm text-[var(--status-warning)]">
                            <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                            <span>{{ row.warning }}</span>
                        </p>
                    </li>
                </ul>
            </SectionCard>

            <!-- ══ ตัดสินไปแล้ว ══ -->
            <SectionCard
                v-if="resolved.length"
                title="ตัดสินไปแล้ว (30 วันล่าสุด)"
                content-class="p-0"
            >
                <template #actions>
                    <CheckCircle2 class="size-4 shrink-0 text-[var(--status-good)]" />
                </template>

                <ul class="divide-y">
                    <li v-for="row in resolved" :key="row.id" class="px-4 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="tabular font-semibold">{{ money(row.amount) }} ฿</span>
                                <Badge variant="success">{{ row.resolution_label }}</Badge>
                                <span v-if="row.order_no" class="text-muted-foreground">บิล {{ row.order_no }}</span>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.resolved_by }} · {{ row.resolved_at ? dateTime(row.resolved_at) : '—' }}
                            </p>
                        </div>
                        <p v-if="row.resolution_note" class="mt-1 text-sm text-muted-foreground">
                            {{ row.resolution_note }}
                        </p>
                    </li>
                </ul>
            </SectionCard>
        </div>

        <!-- ══ กล่องตัดสิน ══ -->
        <Modal
            :open="deciding !== null"
            title="ตัดสินเงินก้อนนี้"
            @update:open="(value: boolean) => { if (!value) deciding = null }"
        >
            <div v-if="deciding" class="space-y-4">
                <div class="rounded-lg border bg-muted/40 px-3 py-2">
                    <p class="tabular text-lg font-bold">{{ money(deciding.amount) }} ฿</p>
                    <p v-if="deciding.order_no" class="text-sm text-muted-foreground">บิล {{ deciding.order_no }}</p>
                    <p v-if="deciding.message" class="mt-1 text-sm text-[var(--status-critical)]">
                        {{ deciding.message }}
                    </p>
                </div>

                <!--
                    เขียนไว้ตรงนี้เพราะเป็นสิ่งที่ผู้จัดการมักเข้าใจผิดที่สุด
                    การกดปุ่มนี้ไม่ได้ทำให้เงินเข้าระบบ มันแค่บันทึกว่าคุณจัดการมันแล้ว
                -->
                <p class="rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground">
                    หน้านี้บันทึก <b>คำตัดสิน</b> เท่านั้น ระบบไม่ได้เปิดบิลหรือคืนเงินให้เอง —
                    ทำจริงให้เสร็จก่อน แล้วค่อยมากดบันทึกว่าทำอะไรไป
                </p>

                <div class="space-y-1.5">
                    <Label for="resolution">ทำอะไรกับเงินก้อนนี้</Label>
                    <Select id="resolution" v-model="form.resolution">
                        <option v-for="r in resolutions" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </Select>
                </div>

                <div class="space-y-1.5">
                    <Label for="note">รายละเอียด (อย่างน้อย 10 ตัวอักษร)</Label>
                    <Input
                        id="note"
                        v-model="form.note"
                        placeholder="เช่น เปิดบิล S260924-0042 แล้วเก็บเงินสดตามยอดเดิม"
                    />
                    <p v-if="form.errors.note" class="text-xs text-[var(--status-critical)]">
                        {{ form.errors.note }}
                    </p>
                </div>

                <div class="flex justify-end gap-2 border-t pt-3">
                    <Button variant="outline" @click="deciding = null">ยกเลิก</Button>
                    <Button variant="brand" :disabled="!canSubmit" @click="submit">บันทึกคำตัดสิน</Button>
                </div>
            </div>
        </Modal>
    </BackOfficeLayout>
</template>
