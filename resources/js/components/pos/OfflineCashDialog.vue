<script setup lang="ts">
/**
 * รับเงินสดตอนระบบล่ม
 *
 * ── ทำไมแยกจาก PaymentDialog ตัวปกติ ─────────────────────────────────────
 * ตัวปกติมีทั้งบัตร โอน พร้อมเพย์ คูปอง แต้ม ส่วนลด — ทุกอย่างต้องคุยกับเซิร์ฟเวอร์
 * ตอนหลุดใช้ได้แค่เงินสด การเอาตัวเดิมมาปิดปุ่มทีละอันจะเหลือหน้าจอที่เต็มไปด้วย
 * ปุ่มเทาที่กดไม่ได้ ซึ่งอ่านว่า "ระบบพัง" มากกว่า "ตอนนี้ทำได้แค่นี้"
 *
 * ── สองขั้นในกล่องเดียว ─────────────────────────────────────────────────
 * กรอกเงิน -> สลิป · เพราะพนักงานต้องอ่านเลขสลิปให้ลูกค้าหรือจดใส่กระดาษทันที
 * ถ้าปิดกล่องแล้วต้องไปหาเลขที่อื่น จะมีคนลืมจด แล้วเงินก้อนนั้นตามไม่ได้
 */
import { computed, ref } from 'vue'
import { HandCoins, Check, TriangleAlert } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Modal from '@/components/ui/Modal.vue'
import { money } from '@/lib/format'

const props = defineProps<{
    orderNo: string | null
    tableName: string | null
    total: number
    slipNo: string
}>()

const emit = defineEmits<{
    close: []
    confirm: [received: number, change: number]
}>()

const received = ref<string>('')
const done = ref(false)

const receivedValue = computed(() => {
    const n = Number(received.value)

    return Number.isFinite(n) ? n : 0
})

const change = computed(() => Math.max(0, Math.round((receivedValue.value - props.total) * 100) / 100))

const short = computed(() => receivedValue.value + 0.01 < props.total)

const canConfirm = computed(() => receivedValue.value > 0 && !short.value)

/** ปุ่มลัดแบงก์ที่ใช้จริงในร้าน — พิมพ์เองก็ได้ แต่ส่วนใหญ่จ่ายพอดีหรือแบงก์กลม */
const quickAmounts = computed(() => {
    const exact = Math.round(props.total * 100) / 100
    const notes = [100, 500, 1000]
    const out = [exact]

    for (const n of notes) {
        const up = Math.ceil(exact / n) * n
        if (up > exact && !out.includes(up)) out.push(up)
    }

    return out.slice(0, 4)
})

function confirm() {
    if (!canConfirm.value) return

    emit('confirm', receivedValue.value, change.value)
    done.value = true
}
</script>

<template>
    <Modal
        :open="true"
        :title="done ? 'เก็บเงินแล้ว' : 'รับเงินสด (ระบบล่ม)'"
        @update:open="(value: boolean) => { if (!value) emit('close') }"
    >
        <!-- ══ ขั้นที่ 1 · กรอกเงินที่รับมา ══ -->
        <div v-if="!done" class="space-y-4">
            <div
                class="flex items-start gap-2 rounded-lg border border-[var(--status-warning)]/40 bg-[var(--status-warning)]/10 px-3 py-2 text-xs"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0 text-[var(--status-warning)]" />
                <span>
                    ตอนนี้ระบบล่ม รับได้เฉพาะ<b>เงินสด</b> และจะยัง<b>ไม่ออกใบเสร็จ</b>
                    ระบบจะปิดบิลกับออกเลขใบเสร็จให้เองเมื่อกลับมาต่อได้
                </span>
            </div>

            <div class="rounded-lg border bg-muted/40 px-3 py-2">
                <p class="text-xs text-muted-foreground">
                    <template v-if="tableName">โต๊ะ {{ tableName }} · </template>
                    <template v-if="orderNo">บิล {{ orderNo }}</template>
                </p>
                <p class="tabular text-2xl font-bold">{{ money(total) }} ฿</p>
            </div>

            <div class="space-y-1.5">
                <Label for="received">รับเงินมา</Label>
                <Input
                    id="received"
                    v-model="received"
                    type="number"
                    inputmode="decimal"
                    min="0"
                    step="0.01"
                    placeholder="0.00"
                />

                <div class="flex flex-wrap gap-2 pt-1">
                    <Button
                        v-for="amount in quickAmounts"
                        :key="amount"
                        variant="outline"
                        size="sm"
                        @click="received = String(amount)"
                    >
                        {{ money(amount) }}
                    </Button>
                </div>
            </div>

            <div class="flex items-baseline justify-between border-t pt-3">
                <span class="text-sm text-muted-foreground">เงินทอน</span>
                <span class="tabular text-xl font-bold">{{ money(change) }} ฿</span>
            </div>

            <p v-if="short" class="text-xs text-[var(--status-critical)]">
                เงินที่รับมายังน้อยกว่ายอดบิล
            </p>

            <div class="flex justify-end gap-2 border-t pt-3">
                <Button variant="outline" @click="emit('close')">ยกเลิก</Button>
                <Button variant="brand" :disabled="!canConfirm" @click="confirm">
                    <HandCoins />
                    รับเงินและปิดโต๊ะ
                </Button>
            </div>
        </div>

        <!-- ══ ขั้นที่ 2 · สลิปชั่วคราว ══ -->
        <!--
            ไม่ใช่ใบเสร็จ และต้องเขียนคำนั้นตัวใหญ่ที่สุดบนสลิป
            ถ้าลูกค้าเอาไปใช้เบิกแล้วมันไม่ใช่ใบเสร็จภาษี ร้านเป็นคนรับผลทีหลัง
        -->
        <div v-else class="space-y-4">
            <div class="rounded-lg border-2 border-dashed px-4 py-4 text-center">
                <p class="text-xs font-bold uppercase tracking-wide text-[var(--status-warning)]">
                    ใบแจ้งยอดชั่วคราว — ไม่ใช่ใบเสร็จรับเงิน
                </p>

                <p class="tabular mt-3 text-3xl font-bold">{{ slipNo }}</p>

                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">ยอดบิล</dt>
                        <dd class="tabular font-semibold">{{ money(total) }} ฿</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">รับเงินมา</dt>
                        <dd class="tabular">{{ money(receivedValue) }} ฿</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">เงินทอน</dt>
                        <dd class="tabular">{{ money(change) }} ฿</dd>
                    </div>
                    <div v-if="orderNo" class="flex justify-between">
                        <dt class="text-muted-foreground">บิล</dt>
                        <dd>{{ orderNo }}</dd>
                    </div>
                </dl>
            </div>

            <ul class="space-y-1 text-xs text-muted-foreground">
                <li>· <b>จดเลข {{ slipNo }} ไว้</b> เผื่อต้องตามหาบิลนี้ทีหลัง</li>
                <li>· ถ้าลูกค้าขอใบเสร็จ ให้ติดต่อกลับมาหลังระบบกลับมาแล้ว</li>
                <li>· ห้ามปิดหรือรีเฟรชแท็บนี้จนกว่าระบบจะกลับมาและส่งขึ้นครบ</li>
            </ul>

            <div class="flex justify-end border-t pt-3">
                <Button variant="brand" @click="emit('close')">
                    <Check />
                    เรียบร้อย
                </Button>
            </div>
        </div>
    </Modal>
</template>
