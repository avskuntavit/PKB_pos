<script setup lang="ts">
/**
 * กรอกข้อมูลผู้รับ + เลือกวิธีจ่าย
 *
 * ไม่มี OTP โดยตั้งใจ (ลูกค้ากรอกเบอร์แล้วสั่งได้เลย)
 * เบอร์จึงถือเป็นช่องทางติดต่อ ไม่ใช่การยืนยันตัวตน — ตัวจริงมาเจอพนักงานที่ร้านอยู่ดี
 */
import { computed, ref } from 'vue'
import { Building2, ChevronLeft, ShoppingBag } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import { money } from '@/lib/format'

interface PaymentOption {
    value: string
    label: string
    description: string
    is_government_scheme: boolean
}

const props = defineProps<{
    total: number
    itemCount: number
    orderTypes: Array<{ value: string; label: string }>
    paymentIntents: PaymentOption[]
    earliestPickupAt: string
    prepMinutes: number
    isStaff: boolean
    processing: boolean
    errors: Record<string, string>
}>()

const emit = defineEmits<{ close: []; submit: [] }>()

const type = defineModel<string>('type', { default: 'takeaway' })
const name = defineModel<string>('name', { default: '' })
const phone = defineModel<string>('phone', { default: '' })
const pickupAt = defineModel<string>('pickupAt', { default: '' })
const guestCount = defineModel<number>('guestCount', { default: 1 })
const paymentIntent = defineModel<string>('paymentIntent', { default: 'pay_at_store' })
const note = defineModel<string>('note', { default: '' })

/** ค่าเริ่มต้นของช่องเวลา — เร็วที่สุดเท่าที่ครัวทำทัน */
const earliestLocal = computed(() => toLocalInput(props.earliestPickupAt))

function toLocalInput(iso: string): string {
    const d = new Date(iso)
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

if (!pickupAt.value) {
    pickupAt.value = earliestLocal.value
}

const isDineIn = computed(() => type.value === 'dine_in')

const selectedPayment = computed(() =>
    props.paymentIntents.find((p) => p.value === paymentIntent.value),
)

const canSubmit = computed(
    () => name.value.trim().length > 0 && phone.value.trim().length >= 9 && !props.processing,
)
</script>

<template>
    <div class="fixed inset-0 z-40 flex flex-col bg-background">
        <header class="flex h-14 shrink-0 items-center gap-2 border-b px-3">
            <Button variant="ghost" size="icon" aria-label="ย้อนกลับ" @click="emit('close')">
                <ChevronLeft />
            </Button>
            <h2 class="text-base font-semibold">ยืนยันออเดอร์</h2>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <div class="mx-auto max-w-2xl space-y-4 p-4">
                <!-- รูปแบบการรับ -->
                <section class="space-y-2">
                    <h3 class="text-sm font-semibold">รับอาหารแบบไหน</h3>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="t in orderTypes"
                            :key="t.value"
                            type="button"
                            class="flex items-center gap-3 rounded-xl border-2 p-3 text-left transition-colors"
                            :class="
                                type === t.value
                                    ? 'border-[var(--series-1)] bg-[var(--series-1)]/8'
                                    : 'border-border hover:bg-accent'
                            "
                            @click="type = t.value"
                        >
                            <component
                                :is="t.value === 'dine_in' ? Building2 : ShoppingBag"
                                class="size-5 shrink-0"
                                :class="type === t.value ? 'text-[var(--series-1)]' : 'text-muted-foreground'"
                            />
                            <span class="text-sm font-medium">{{ t.label }}</span>
                        </button>
                    </div>
                </section>

                <!-- ข้อมูลผู้รับ -->
                <section class="space-y-3 rounded-xl border bg-card p-4">
                    <h3 class="text-sm font-semibold">ข้อมูลผู้รับ</h3>

                    <div class="space-y-1">
                        <Label for="cname">ชื่อ</Label>
                        <Input id="cname" v-model="name" class="h-11" placeholder="ชื่อที่ให้เรียกตอนมารับ" />
                        <p v-if="errors.name" class="text-xs text-[var(--status-critical)]">{{ errors.name }}</p>
                    </div>

                    <div class="space-y-1">
                        <Label for="cphone">เบอร์โทร</Label>
                        <Input
                            id="cphone"
                            v-model="phone"
                            type="tel"
                            inputmode="tel"
                            class="h-11"
                            placeholder="08xxxxxxxx"
                        />
                        <p v-if="errors.phone" class="text-xs text-[var(--status-critical)]">{{ errors.phone }}</p>
                        <p class="text-xs text-muted-foreground">
                            ร้านใช้ติดต่อกลับกรณีของหมด และใช้สะสมแต้มให้อัตโนมัติ
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="pickup">{{ isDineIn ? 'เวลาที่จะมาถึงร้าน' : 'เวลาที่จะมารับ' }}</Label>
                            <Input
                                id="pickup"
                                v-model="pickupAt"
                                type="datetime-local"
                                class="h-11"
                                :min="earliestLocal"
                            />
                            <p class="text-xs text-muted-foreground">
                                ครัวใช้เวลาประมาณ {{ prepMinutes }} นาที
                            </p>
                        </div>

                        <div v-if="isDineIn" class="space-y-1">
                            <Label for="guests">จำนวนคน</Label>
                            <Input id="guests" v-model="guestCount" type="number" min="1" max="30" class="h-11" />
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label for="onote">หมายเหตุถึงร้าน</Label>
                        <Input id="onote" v-model="note" class="h-11" placeholder="เช่น ขอช้อนส้อมเพิ่ม 2 ชุด" />
                    </div>
                </section>

                <!-- วิธีชำระเงิน -->
                <section class="space-y-2">
                    <h3 class="text-sm font-semibold">วิธีชำระเงิน</h3>

                    <div class="space-y-2">
                        <button
                            v-for="option in paymentIntents"
                            :key="option.value"
                            type="button"
                            class="flex w-full items-start gap-3 rounded-xl border-2 p-3 text-left transition-colors"
                            :class="
                                paymentIntent === option.value
                                    ? 'border-[var(--series-1)] bg-[var(--series-1)]/8'
                                    : 'border-border hover:bg-accent'
                            "
                            @click="paymentIntent = option.value"
                        >
                            <span
                                class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border-2"
                                :class="paymentIntent === option.value ? 'border-[var(--series-1)]' : 'border-muted-foreground/40'"
                            >
                                <span
                                    v-if="paymentIntent === option.value"
                                    class="size-2.5 rounded-full bg-[var(--series-1)]"
                                />
                            </span>

                            <span class="min-w-0">
                                <span class="block text-sm font-medium">{{ option.label }}</span>
                                <span class="block text-xs text-muted-foreground">{{ option.description }}</span>
                            </span>
                        </button>
                    </div>

                    <p
                        v-if="selectedPayment?.is_government_scheme"
                        class="rounded-lg border border-dashed bg-card px-3 py-2 text-xs text-muted-foreground"
                    >
                        ระบบจะแจ้งให้พนักงานเตรียมไว้ — เงื่อนไขและสิทธิ์ของโครงการเป็นไปตามที่แอปของโครงการกำหนด
                        ระบบนี้ไม่ได้ตรวจสิทธิ์ให้ และไม่ได้ตัดเงินจากโครงการเอง
                    </p>
                </section>

                <p v-if="isStaff" class="rounded-lg border border-[var(--series-1)]/30 bg-[var(--series-1)]/10 px-3 py-2 text-xs">
                    คุณกำลังสั่งแทนลูกค้า — ออเดอร์นี้จะเข้าครัวทันทีโดยไม่ต้องรอร้านกดรับ
                </p>
            </div>
        </div>

        <footer
            class="shrink-0 border-t bg-card px-4 py-3"
            :style="{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom, 0px))' }"
        >
            <div class="mx-auto flex max-w-2xl items-center gap-3">
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">{{ itemCount }} รายการ</p>
                    <p class="tabular text-lg font-semibold">{{ money(total) }} ฿</p>
                </div>
                <Button variant="brand" size="xl" class="flex-1" :disabled="!canSubmit" @click="emit('submit')">
                    {{ processing ? 'กำลังส่ง...' : 'ยืนยันสั่งอาหาร' }}
                </Button>
            </div>
        </footer>
    </div>
</template>
