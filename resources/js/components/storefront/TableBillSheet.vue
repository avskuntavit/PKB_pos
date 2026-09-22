<script setup lang="ts">
/**
 * บิลของโต๊ะ — ลูกค้าเปิดดูว่าสั่งอะไรไปแล้วบ้าง ถึงไหนแล้ว รวมเท่าไหร่
 *
 * ── ทำไมต้องแยกตามรอบ ─────────────────────────────────────
 * โต๊ะหนึ่งสั่งได้หลายรอบ ถ้าเอามากองรวมเป็นลิสต์เดียว ลูกค้าจะแยกไม่ออกว่า
 * "ที่เพิ่งกดไปเมื่อกี้" คืออันไหน และจะนับซ้ำกับรอบก่อนหน้า
 *
 * ── ทำไมต้องมีสถานะรายจาน ─────────────────────────────────
 * คำถามที่ลูกค้าถามพนักงานบ่อยที่สุดคือ "อีกจานมาเมื่อไหร่"
 * ถ้าหน้าจอตอบได้เอง พนักงานก็ไม่ต้องเดินมาตอบ
 */
import { computed } from 'vue'
import { ChevronLeft, CircleAlert, Plus, Receipt, Utensils } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'
import { money, number, time } from '@/lib/format'
import type { TableBill } from '@/types'

const props = defineProps<{
    bill: TableBill
    /** กำลังส่งคำขอเรียกพนักงานอยู่ */
    calling?: boolean
}>()

const emit = defineEmits<{
    (e: 'close'): void
    (e: 'call-bill'): void
}>()

/**
 * สีของป้ายสถานะ
 *
 * ไล่จากเทา (ยังไม่เริ่ม) ไปเขียว (ได้กินแล้ว) เพื่อให้กวาดตาทีเดียวรู้ว่า
 * ยังเหลืออะไรค้างอยู่ ส่วนสีส้มสงวนไว้ให้ "รอร้านยืนยัน" อย่างเดียว
 * เพราะเป็นสถานะเดียวที่ลูกค้าอาจต้องลุกไปตามพนักงาน
 */
const PILL: Record<string, string> = {
    waiting_approval: 'bg-[var(--status-warning)]/15 text-[var(--status-warning)]',
    waiting_kitchen: 'bg-muted text-muted-foreground',
    in_kitchen: 'bg-[var(--series-1)]/12 text-[var(--series-1)]',
    preparing: 'bg-[var(--series-1)]/20 text-[var(--series-1)] font-semibold',
    ready: 'bg-[var(--status-good)]/15 text-[var(--status-good)] font-semibold',
    served: 'bg-[var(--status-good)]/10 text-[var(--status-good)]',
}

const pill = (status: string) => PILL[status] ?? 'bg-muted text-muted-foreground'

const totals = computed(() => props.bill.totals)

/** ตัดเศษที่ไม่ต้องอ่าน — 2 จานไม่ต้องเขียน 2.000 */
const qty = (value: number) => number(value)
</script>

<template>
    <div class="fixed inset-0 z-40 flex flex-col bg-background">
        <header class="flex h-14 shrink-0 items-center gap-1 border-b px-2">
            <button
                type="button"
                class="grid size-10 place-items-center rounded-full transition-colors hover:bg-accent"
                aria-label="ปิด"
                @click="emit('close')"
            >
                <ChevronLeft class="size-5" />
            </button>

            <div class="min-w-0">
                <h2 class="truncate text-lg font-bold">
                    <template v-if="bill.table">บิลของโต๊ะ {{ bill.table }}</template>
                    <template v-else>บิลของคุณ</template>
                </h2>
                <p class="truncate text-xs text-muted-foreground">{{ bill.order_no }}</p>
            </div>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <div class="mx-auto max-w-2xl px-4 py-4 lg:max-w-3xl">
                <!-- รายการที่ร้านยังไม่ยืนยัน — ต้องเตือนก่อนอย่างอื่น -->
                <div
                    v-if="bill.waiting_approval > 0"
                    class="mb-4 flex items-start gap-2.5 rounded-xl bg-[var(--status-warning)]/12 p-3 text-sm"
                    role="status"
                >
                    <CircleAlert class="mt-0.5 size-4 shrink-0 text-[var(--status-warning)]" />
                    <p>
                        มี {{ number(bill.waiting_approval) }} รายการรอร้านกดยืนยัน
                        ครัวจะยังไม่เริ่มทำจนกว่าพนักงานจะยืนยันให้
                    </p>
                </div>

                <!-- ══ แยกตามรอบที่สั่ง ══ -->
                <section v-for="r in bill.rounds" :key="r.round" class="mb-5 last:mb-0">
                    <div class="mb-1.5 flex items-baseline justify-between gap-2">
                        <h3 class="text-sm font-bold">รอบที่ {{ r.round }}</h3>
                        <span v-if="r.placed_at" class="text-xs text-muted-foreground">
                            {{ time(r.placed_at) }}
                        </span>
                    </div>

                    <ul class="divide-y overflow-hidden rounded-xl border bg-card">
                        <li v-for="item in r.items" :key="item.id" class="flex gap-3 p-3">
                            <span class="tabular w-8 shrink-0 text-sm font-bold">{{ qty(item.qty) }}×</span>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">{{ item.name }}</p>
                                <p v-if="item.modifiers_text" class="text-xs text-muted-foreground">
                                    {{ item.modifiers_text }}
                                </p>
                                <!-- ชื่อคนสั่ง — ตอบคำถามที่โต๊ะถามกันเองว่า "จานนี้ของใคร" -->
                                <p v-if="item.guest_name" class="text-xs text-[var(--series-1)]">
                                    {{ item.guest_name }} สั่ง
                                </p>
                                <p v-if="item.note" class="text-xs text-muted-foreground">
                                    หมายเหตุ: {{ item.note }}
                                </p>
                                <span
                                    class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px]"
                                    :class="pill(item.status)"
                                >
                                    {{ item.status_label }}
                                </span>
                            </div>

                            <span class="tabular shrink-0 text-sm font-semibold">
                                {{ money(item.line_total) }}
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- ══ ยอด ══ -->
                <dl class="mt-5 space-y-1 rounded-xl border bg-card p-4 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">ค่าอาหาร</dt>
                        <dd class="tabular">{{ money(totals.subtotal) }}</dd>
                    </div>
                    <div v-if="totals.discount > 0" class="flex justify-between">
                        <dt class="text-muted-foreground">ส่วนลด</dt>
                        <dd class="tabular">-{{ money(totals.discount) }}</dd>
                    </div>
                    <div v-if="totals.service_charge > 0" class="flex justify-between">
                        <dt class="text-muted-foreground">ค่าบริการ</dt>
                        <dd class="tabular">{{ money(totals.service_charge) }}</dd>
                    </div>
                    <div v-if="totals.tax_amount > 0" class="flex justify-between text-xs text-muted-foreground">
                        <dt>ภาษีที่รวมอยู่ในราคาแล้ว</dt>
                        <dd class="tabular">{{ money(totals.tax_amount) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-t pt-2">
                        <dt class="font-semibold">ยอดที่ต้องจ่าย</dt>
                        <dd class="tabular text-2xl font-bold">{{ money(totals.grand_total) }} ฿</dd>
                    </div>
                </dl>

                <p class="mt-2 px-1 text-xs text-muted-foreground">
                    ยอดนี้คือยอดสะสมของโต๊ะ ณ ตอนนี้ สั่งเพิ่มแล้วยอดจะขยับขึ้นเอง
                </p>
            </div>
        </div>

        <!-- ══ ปุ่มท้ายจอ ══ -->
        <div
            class="shrink-0 border-t bg-card px-4 py-3"
            :style="{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom, 0px))' }"
        >
            <div class="mx-auto flex max-w-2xl gap-2 lg:max-w-3xl">
                <Button variant="outline" size="xl" class="flex-1" @click="emit('close')">
                    <Plus />
                    สั่งเพิ่ม
                </Button>

                <Button
                    v-if="bill.bill_called"
                    variant="outline"
                    size="xl"
                    class="flex-1"
                    disabled
                >
                    <Utensils />
                    แจ้งแล้ว รอพนักงาน
                </Button>
                <Button
                    v-else
                    variant="brand"
                    size="xl"
                    class="flex-1"
                    :disabled="calling"
                    @click="emit('call-bill')"
                >
                    <Receipt />
                    เรียกเก็บเงิน
                </Button>
            </div>
        </div>
    </div>
</template>
