<script setup lang="ts">
/**
 * นำส่งเงินสดสิ้นวัน — หน้าที่พนักงานใช้ตอนปิดร้าน
 *
 * ── ทำไมโชว์สามยอด ────────────────────────────────────────
 * เงินหายได้สองจังหวะ และต้องแยกให้ออกว่าหายตรงไหน
 *   ควรได้ vs นับได้   ต่างกัน = หายที่หน้าเคาน์เตอร์ระหว่างวัน
 *   นับได้ vs โอนแล้ว  ต่างกัน = หายระหว่างทางไปธนาคาร
 * ถ้าโชว์ยอดเดียว พอไม่ตรงขึ้นมาจะไม่มีใครรู้ว่าต้องไปตามที่ไหน
 *
 * ── ทำไมช่อง "ต้องนำส่ง" แก้ไม่ได้ ──────────────────────────
 * ระบบคิดจากบิล ถ้าให้พนักงานพิมพ์เอง ตัวเลขที่เอาไปเทียบก็มาจาก
 * คนเดียวกับที่ถือเงิน แล้วการตรวจสอบก็ไม่เหลือความหมาย
 *
 * ── ทำไมแยกบรรทัด "เงินรับตอนเน็ตหลุด" ─────────────────────
 * เงินก้อนนั้นอยู่ในลิ้นชักจริงจึงต้องนับรวม แต่ยังไม่มีบิลรองรับ
 * ถ้ากลืนเข้าไปในยอดเดียว พนักงานจะไม่รู้ว่ามีก้อนที่ยังไม่จบเรื่อง
 * และพอผู้จัดการมาตรวจก็จะหาไม่เจอว่าส่วนต่างมาจากไหน
 */
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { AlertTriangle, Banknote, Check, CircleAlert, HandCoins, Upload } from 'lucide-vue-next'
import PosLayout from '@/layouts/PosLayout.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { money } from '@/lib/format'
import { useIdempotencyKey } from '@/lib/idempotency'
import type { CashSettlement } from '@/types'

const props = defineProps<{
    businessDate: string
    settlement: CashSettlement
    hasOpenShift: boolean
    unresolvedHeld: { count: number; amount: number }
    outstanding: Array<{
        business_date: string
        expected: number
        status: string | null
        status_label: string
    }>
}>()

const expected = computed(() => Number(props.settlement.expected_amount))
const heldCash = computed(() => Number(props.settlement.held_cash_amount))
/** ยอดที่ต้องนำส่งจริง = จากบิล + เงินที่รับมาแล้วยังไม่มีบิลรองรับ */
const due = computed(() => Number(props.settlement.due_amount))
const counted = computed(() => Number(props.settlement.counted_amount))
const locked = computed(() => props.settlement.status === 'verified')

/** ตั้งต้นด้วยยอดที่นับได้จริง ไม่ใช่ยอดที่ควรได้ — คนโอนตามเงินที่อยู่ในมือ */
const form = useForm({
    transferred_amount: String(counted.value > 0 ? counted.value : due.value),
    reference: '',
    note: '',
    slip: null as File | null,
})

const { rotate: rotateKey, headers: keyHeaders } = useIdempotencyKey()

const typed = computed(() => Number(form.transferred_amount) || 0)
// เทียบกับยอดที่ต้องนำส่งทั้งหมด ไม่ใช่เฉพาะยอดจากบิล
// ไม่งั้นวันที่มีเงินค้างจะขึ้นว่า "โอนเกิน" ทุกครั้งทั้งที่พนักงานส่งครบ
const diff = computed(() => Number((typed.value - due.value).toFixed(2)))

const slipName = ref<string | null>(null)

function pickSlip(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null
    form.slip = file
    slipName.value = file?.name ?? null
}

function submit() {
    form
        .transform((data) => ({ ...data, slip: data.slip ?? undefined }))
        .post(`/pos/cash-settlement?date=${props.businessDate}`, {
            forceFormData: true,
            preserveScroll: true,
            headers: keyHeaders(),
            onSuccess: () => rotateKey(),
        })
}

const STATUS_CLASS: Record<string, string> = {
    pending: 'bg-muted text-muted-foreground',
    submitted: 'bg-[var(--status-warning)]/15 text-[var(--status-warning)]',
    verified: 'bg-[var(--status-good)]/15 text-[var(--status-good)]',
    disputed: 'bg-[var(--status-critical)]/15 text-[var(--status-critical)]',
}

function goTo(date: string) {
    router.get('/pos/cash-settlement', { date }, { preserveState: false })
}
</script>

<template>
    <Head title="นำส่งเงินสด" />

    <PosLayout title="นำส่งเงินสด">
        <div class="h-full overflow-y-auto p-4">
            <div class="mx-auto max-w-2xl space-y-4">
                <!-- หัวเรื่อง -->
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 class="text-xl font-bold">นำส่งเงินสดประจำวัน</h1>
                        <p class="text-sm text-muted-foreground">วันขาย {{ businessDate }}</p>
                    </div>
                    <span
                        class="rounded-full px-3 py-1 text-sm font-medium"
                        :class="STATUS_CLASS[settlement.status] ?? 'bg-muted'"
                    >
                        {{ settlement.status_label }}
                    </span>
                </div>

                <!-- ยังปิดรอบไม่ครบ -->
                <div
                    v-if="hasOpenShift"
                    class="flex items-start gap-2.5 rounded-xl bg-[var(--status-warning)]/12 p-3 text-sm"
                    role="status"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0 text-[var(--status-warning)]" />
                    <p>
                        ยังมีรอบการขายที่เปิดค้างอยู่ ยอดที่นับได้จึงยังไม่ครบ
                        ปิดรอบให้หมดก่อนแล้วค่อยนำส่งจะตรงกว่า
                    </p>
                </div>

                <!-- เงินที่รับตอนเน็ตหลุดแต่ยังไม่มีใครตัดสิน -->
                <div
                    v-if="unresolvedHeld.count > 0"
                    class="flex items-start gap-2.5 rounded-xl bg-[var(--status-warning)]/12 p-3 text-sm"
                    role="status"
                >
                    <HandCoins class="mt-0.5 size-4 shrink-0 text-[var(--status-warning)]" />
                    <p>
                        มีเงินสด {{ money(unresolvedHeld.amount) }} บาท จาก
                        {{ unresolvedHeld.count }} รายการ ที่รับมาตอนเน็ตหลุดแล้วยังลงบิลไม่ได้
                        — <strong>นับรวมในยอดที่ต้องนำส่งแล้ว</strong> เพราะเงินอยู่ในลิ้นชักจริง
                        ผู้จัดการต้องเข้าไปตัดสินที่หน้าเงินค้างด้วย
                    </p>
                </div>

                <!-- สามยอด -->
                <dl class="grid grid-cols-3 gap-2">
                    <div class="rounded-xl border bg-card p-3">
                        <dt class="text-xs text-muted-foreground">ต้องนำส่ง</dt>
                        <dd class="tabular mt-1 text-lg font-bold">{{ money(due) }}</dd>
                        <dd v-if="heldCash > 0" class="mt-0.5 text-[11px] text-muted-foreground">
                            บิล {{ money(expected) }} + เน็ตหลุด {{ money(heldCash) }}
                        </dd>
                        <dd v-else class="mt-0.5 text-[11px] text-muted-foreground">คิดจากบิล</dd>
                    </div>
                    <div class="rounded-xl border bg-card p-3">
                        <dt class="text-xs text-muted-foreground">นับได้ในลิ้นชัก</dt>
                        <dd class="tabular mt-1 text-lg font-bold">{{ money(counted) }}</dd>
                        <dd class="mt-0.5 text-[11px] text-muted-foreground">หักเงินทอนตั้งต้นแล้ว</dd>
                    </div>
                    <div class="rounded-xl border bg-card p-3">
                        <dt class="text-xs text-muted-foreground">จะโอน</dt>
                        <dd
                            class="tabular mt-1 text-lg font-bold"
                            :class="diff !== 0 && 'text-[var(--status-critical)]'"
                        >
                            {{ money(typed) }}
                        </dd>
                        <dd v-if="diff !== 0" class="mt-0.5 text-[11px] text-[var(--status-critical)]">
                            {{ diff > 0 ? 'เกิน' : 'ขาด' }} {{ money(Math.abs(diff)) }}
                        </dd>
                    </div>
                </dl>

                <!-- ฟอร์ม -->
                <form v-if="!locked" class="space-y-3 rounded-xl border bg-card p-4" @submit.prevent="submit">
                    <div class="space-y-1">
                        <Label for="amount">ยอดที่โอนจริง (บาท)</Label>
                        <Input
                            id="amount"
                            v-model="form.transferred_amount"
                            type="number"
                            step="0.01"
                            min="0"
                            required
                        />
                        <p v-if="form.errors.transferred_amount" class="text-xs text-[var(--status-critical)]">
                            {{ form.errors.transferred_amount }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <Label for="ref">เลขอ้างอิงรายการโอน</Label>
                        <Input id="ref" v-model="form.reference" placeholder="เช่น เลขที่รายการจากแอปธนาคาร" />
                    </div>

                    <div class="space-y-1">
                        <Label for="slip">สลิปการโอน</Label>
                        <label
                            class="flex cursor-pointer items-center gap-2 rounded-md border border-dashed px-3 py-2.5 text-sm text-muted-foreground hover:bg-accent"
                        >
                            <Upload class="size-4 shrink-0" />
                            <span class="truncate">{{ slipName ?? 'เลือกรูปสลิป' }}</span>
                            <input id="slip" type="file" accept="image/*" class="hidden" @change="pickSlip" />
                        </label>
                        <p v-if="form.errors.slip" class="text-xs text-[var(--status-critical)]">
                            {{ form.errors.slip }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <Label for="note">หมายเหตุ</Label>
                        <Input id="note" v-model="form.note" placeholder="เช่น เงินขาด 20 บาท ทอนผิด" />
                    </div>

                    <div
                        v-if="diff !== 0"
                        class="flex items-start gap-2 rounded-lg bg-[var(--status-warning)]/12 p-2.5 text-xs"
                    >
                        <CircleAlert class="mt-0.5 size-3.5 shrink-0 text-[var(--status-warning)]" />
                        <span>
                            ยอดที่จะโอนไม่ตรงกับที่ต้องนำส่ง {{ money(Math.abs(diff)) }} บาท
                            เขียนเหตุผลไว้ในหมายเหตุด้วย ผู้จัดการจะได้ไม่ต้องตามถาม
                        </span>
                    </div>

                    <Button type="submit" variant="brand" size="xl" class="w-full" :disabled="form.processing">
                        <Spinner v-if="form.processing" class="size-4" />
                        <Banknote v-else />
                        บันทึกการนำส่ง
                    </Button>
                </form>

                <div v-else class="flex items-center gap-2 rounded-xl border bg-card p-4 text-sm">
                    <Check class="size-4 shrink-0 text-[var(--status-good)]" />
                    ใบนำส่งของวันนี้ถูกตรวจกับบัญชีธนาคารแล้ว แก้ไขไม่ได้
                </div>

                <!-- วันที่ยังค้าง -->
                <section v-if="outstanding.length" class="rounded-xl border bg-card p-4">
                    <h2 class="text-sm font-semibold">วันที่ยังนำส่งไม่จบ</h2>
                    <ul class="mt-2 divide-y text-sm">
                        <li
                            v-for="row in outstanding"
                            :key="row.business_date"
                            class="flex items-center justify-between gap-2 py-2"
                        >
                            <button
                                type="button"
                                class="text-left hover:underline"
                                @click="goTo(row.business_date)"
                            >
                                {{ row.business_date }}
                                <span class="block text-xs text-muted-foreground">{{ row.status_label }}</span>
                            </button>
                            <span class="tabular shrink-0 font-medium">{{ money(row.expected) }}</span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </PosLayout>
</template>
