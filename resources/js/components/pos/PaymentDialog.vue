<script setup lang="ts">
/**
 * รับชำระเงิน — รองรับหลายช่องทางในบิลเดียว
 *
 * ระบบไม่ได้ต่อกับระบบชำระเงินใด ๆ (ธนาคาร/โครงการรัฐ)
 * หน้าจอนี้คือการที่ "พนักงานกดยืนยันว่าได้รับเงินแล้ว" ด้วยตาตัวเองที่หน้างาน
 * ตัวเลขที่บันทึกตรงนี้จึงเป็นสิ่งที่รายงานยึดถือ
 */
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Info, Landmark, Plus, Trash2 } from 'lucide-vue-next'
import Modal from '@/components/ui/Modal.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import { money } from '@/lib/format'
import { useIdempotencyKey } from '@/lib/idempotency'
import type { Option, Order } from '@/types'

const props = defineProps<{
    order: Order
    methods: Option[]
    /**
     * บรรทัดที่ถูกเติมไว้ให้ตั้งแต่เปิดหน้าต่าง — ใช้ตอนเงินโอนเข้ามาแล้วผ่าน QR
     *
     * ── ทำไมต้องเติมให้ ไม่ปล่อยพนักงานพิมพ์เอง ────────────────────────
     * ฝั่งเซิร์ฟเวอร์จับคู่เงินที่เข้ามากับแถว payments **ด้วยยอด**
     * ถ้าพนักงานพิมพ์ยอดเองแล้วพลาดไปหนึ่งสตางค์ บิลจะปิดได้ตามปกติ
     * แต่เงินก้อนนั้นจะไม่ถูกผูกเข้ากับบิล กลายเป็นเงินลอยที่ไม่มีใครเห็น
     * จนกว่าจะกระทบยอดปลายเดือน
     */
    prefill?: { method: string; amount: number; reference: string } | null
}>()
const open = defineModel<boolean>('open', { default: false })

const grandTotal = computed(() => Number(props.order.grand_total))

/** ช่องทางจ่ายที่ตรงกับสิ่งที่ลูกค้าแจ้งไว้ตอนสั่งล่วงหน้า */
const intentToMethod: Record<string, string> = {
    pay_at_store: 'cash',
    promptpay: 'promptpay',
    khon_la_khrueng: 'khon_la_khrueng',
    thai_chuay_thai: 'thai_chuay_thai',
}

const suggestedMethod = computed(
    () => intentToMethod[props.order.payment_intent ?? ''] ?? 'cash',
)

const governmentMethods = ['khon_la_khrueng', 'thai_chuay_thai']

function newLine(method: string, amount: number) {
    return { method, amount, received: amount, reference: '' }
}

const form = useForm({
    voucher_code: '',
    lines: [newLine(suggestedMethod.value, grandTotal.value)],
})

/** ต้องติ๊กยืนยันก่อน ถ้ามีช่องทางที่ระบบตรวจสอบเองไม่ได้ */
const confirmedExternally = ref(false)

/*
| คีย์กันจ่ายซ้ำ — หนึ่งคีย์ต่อการเปิดหน้าต่างหนึ่งครั้ง
|
| กดจ่ายแล้วเน็ตหลุด กดใหม่อีกที = ความตั้งใจเดิม เซิร์ฟเวอร์จะไม่บันทึกซ้ำให้
| นี่คือความเสียหายที่แก้ย้อนหลังยากที่สุด เพราะเงินในลิ้นชักจะไม่ตรงระบบ
*/
const { rotate: rotateKey, headers: idempotencyHeaders } = useIdempotencyKey()

// เปิด dialog ใหม่ทุกครั้ง ให้ยอดและช่องทางตั้งต้นตรงกับบิลปัจจุบัน
watch(open, (value) => {
    if (value) {
        form.reset()
        form.lines = props.prefill
            ? [{
                method: props.prefill.method,
                amount: props.prefill.amount,
                received: props.prefill.amount,
                reference: props.prefill.reference,
            }]
            : [newLine(suggestedMethod.value, grandTotal.value)]
        /*
        | เงินที่เข้ามาผ่านเกตเวย์แล้วไม่ต้องให้ติ๊กยืนยันอีก
        | ระบบเห็นเงินก้อนนั้นเองแล้ว การบังคับติ๊กจะกลายเป็นพิธีกรรมที่คนกดผ่าน ๆ
        | แล้ววันที่มันสำคัญจริง (จ่ายผ่านแอปที่ระบบมองไม่เห็น) ก็จะถูกกดผ่านเหมือนกัน
        */
        confirmedExternally.value = props.prefill !== null && props.prefill !== undefined
        // บิลใหม่ = ความตั้งใจใหม่ ถ้าใช้คีย์เดิมจะโดนปฏิเสธว่า "บันทึกไปแล้ว"
        rotateKey()
    }
})

const paid = computed(() => form.lines.reduce((sum, l) => sum + Number(l.amount || 0), 0))
const remaining = computed(() => Math.max(0, Number((grandTotal.value - paid.value).toFixed(2))))

const change = computed(() => {
    const cashLines = form.lines.filter((l) => l.method === 'cash')
    const received = cashLines.reduce((sum, l) => sum + Number(l.received || 0), 0)
    const cashDue = cashLines.reduce((sum, l) => sum + Number(l.amount || 0), 0)
    return Math.max(0, Number((received - cashDue).toFixed(2)))
})

/** มีบรรทัดที่เงินเข้าผ่านแอปข้างนอก ซึ่งระบบนี้มองไม่เห็น */
const hasExternalLine = computed(() =>
    form.lines.some((l) => governmentMethods.includes(l.method) || l.method === 'promptpay'),
)

const externalLabels = computed(() =>
    form.lines
        .filter((l) => governmentMethods.includes(l.method) || l.method === 'promptpay')
        .map((l) => props.methods.find((m) => m.value === l.method)?.label ?? l.method),
)

/** ปุ่มเงินด่วน — ปัดขึ้นเป็นแบงก์ที่ลูกค้าน่าจะยื่นให้ */
const quickCash = computed(() => {
    const total = grandTotal.value
    const suggestions = new Set<number>([
        total,
        Math.ceil(total / 20) * 20,
        Math.ceil(total / 100) * 100,
        Math.ceil(total / 500) * 500,
        Math.ceil(total / 1000) * 1000,
    ])

    return [...suggestions].filter((v) => v >= total).sort((a, b) => a - b).slice(0, 5)
})

function setCash(amount: number) {
    const cash = form.lines.find((l) => l.method === 'cash')
    if (cash) cash.received = amount
}

function addLine() {
    // ค่าเริ่มต้นเป็นเงินสด เพราะเคสแบ่งจ่ายส่วนใหญ่คือ "โครงการรัฐ + เงินสดส่วนที่เหลือ"
    form.lines.push(newLine('cash', remaining.value))
}

function removeLine(index: number) {
    form.lines.splice(index, 1)
}

const canSubmit = computed(
    () => !form.processing && remaining.value === 0 && (!hasExternalLine.value || confirmedExternally.value),
)

function submit() {
    form.post(`/pos/orders/${props.order.id}/pay`, {
        preserveScroll: true,
        headers: idempotencyHeaders(),
        onSuccess: () => rotateKey(),
    })
}
</script>

<template>
    <Modal v-model:open="open" title="ชำระเงิน" class="max-w-xl">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="rounded-lg border bg-muted/40 p-4 text-center">
                <p class="text-xs text-muted-foreground">ยอดที่ต้องชำระ</p>
                <p class="tabular text-3xl font-semibold">{{ money(grandTotal) }} บาท</p>
            </div>

            <!-- ลูกค้าแจ้งวิธีจ่ายไว้ตอนสั่งล่วงหน้า -->
            <p
                v-if="order.payment_intent_label"
                class="flex items-start gap-2 rounded-lg border border-[var(--series-1)]/30 bg-[var(--series-1)]/10 px-3 py-2 text-sm"
            >
                <component
                    :is="order.is_government_scheme ? Landmark : Info"
                    class="mt-0.5 size-4 shrink-0 text-[var(--series-1)]"
                />
                <span>
                    ลูกค้าแจ้งไว้ว่าจะจ่ายผ่าน <strong>{{ order.payment_intent_label }}</strong>
                    <span v-if="order.is_government_scheme" class="block text-xs text-muted-foreground">
                        ออก QR ของโครงการให้ลูกค้าสแกนที่เคาน์เตอร์ แล้วกลับมากดยืนยันที่หน้าจอนี้
                    </span>
                </span>
            </p>

            <div class="space-y-2">
                <div
                    v-for="(line, i) in form.lines"
                    :key="i"
                    class="grid grid-cols-[1fr_auto] items-end gap-2 rounded-lg border p-3"
                >
                    <div class="grid gap-2 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label :for="`m-${i}`">ช่องทาง</Label>
                            <Select :id="`m-${i}`" v-model="line.method">
                                <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </Select>
                        </div>
                        <div class="space-y-1">
                            <Label :for="`a-${i}`">ตัดยอด</Label>
                            <Input :id="`a-${i}`" v-model="line.amount" type="number" step="0.01" min="0" />
                        </div>
                        <div v-if="line.method === 'cash'" class="space-y-1">
                            <Label :for="`r-${i}`">รับเงินมา</Label>
                            <Input :id="`r-${i}`" v-model="line.received" type="number" step="0.01" min="0" />
                        </div>
                        <div v-else class="space-y-1">
                            <Label :for="`ref-${i}`">เลขอ้างอิง</Label>
                            <Input :id="`ref-${i}`" v-model="line.reference" placeholder="ไม่บังคับ" />
                        </div>
                    </div>

                    <Button
                        v-if="form.lines.length > 1"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="text-[var(--status-critical)]"
                        aria-label="ลบช่องทางนี้"
                        @click="removeLine(i)"
                    >
                        <Trash2 />
                    </Button>
                </div>

                <Button type="button" variant="outline" size="sm" @click="addLine">
                    <Plus />
                    แบ่งจ่ายอีกช่องทาง
                </Button>
            </div>

            <div v-if="form.lines.some((l) => l.method === 'cash')" class="flex flex-wrap gap-2">
                <Button
                    v-for="amount in quickCash"
                    :key="amount"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="setCash(amount)"
                >
                    {{ money(amount) }}
                </Button>
            </div>

            <div class="space-y-1">
                <Label for="voucher">รหัสส่วนลด (ถ้ามี)</Label>
                <Input id="voucher" v-model="form.voucher_code" placeholder="เช่น SAVE50" />
            </div>

            <dl class="space-y-1 rounded-lg border p-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">ชำระแล้ว</dt>
                    <dd class="tabular">{{ money(paid) }}</dd>
                </div>
                <div class="flex justify-between" :class="remaining > 0 ? 'text-[var(--status-critical)]' : ''">
                    <dt>ยังขาด</dt>
                    <dd class="tabular">{{ money(remaining) }}</dd>
                </div>
                <div class="flex justify-between font-medium">
                    <dt>เงินทอน</dt>
                    <dd class="tabular">{{ money(change) }}</dd>
                </div>
            </dl>

            <!-- ช่องทางที่ระบบมองไม่เห็นเงินเข้า ต้องให้คนยืนยัน -->
            <label
                v-if="hasExternalLine"
                class="flex cursor-pointer items-start gap-2 rounded-lg border border-[var(--status-warning)]/40 bg-[var(--status-warning)]/10 p-3 text-sm"
            >
                <input v-model="confirmedExternally" type="checkbox" class="mt-0.5 size-4 shrink-0" />
                <span>
                    ยืนยันว่าเห็นรายการสำเร็จแล้วด้วยตาตัวเอง ({{ externalLabels.join(', ') }})
                    <span class="block text-xs text-muted-foreground">
                        ระบบนี้ไม่ได้ต่อกับธนาคารหรือแอปของโครงการ จึงตรวจให้ไม่ได้ —
                        ดูสลิปหรือหน้าจอยืนยันในแอปนั้นก่อนติ๊ก
                    </span>
                </span>
            </label>

            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" @click="open = false">ยกเลิก</Button>
                <Button type="submit" variant="brand" size="lg" :disabled="!canSubmit">
                    ยืนยันรับเงินแล้ว
                </Button>
            </div>
        </form>
    </Modal>
</template>
