<script setup lang="ts">
/** เลือกตัวเลือกเสริม + จำนวน ก่อนใส่ลงบิล */
import { computed, ref } from 'vue'
import { Check, TriangleAlert } from 'lucide-vue-next'
import Modal from '@/components/ui/Modal.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import { money } from '@/lib/format'
import type { Modifier, ModifierGroup, Product } from '@/types'

const props = defineProps<{ product: Product }>()

const emit = defineEmits<{
    close: []
    confirm: [productId: number, qty: number, modifierIds: number[], note: string | null, openPrice: number | null]
}>()

const open = ref(true)
const qty = ref(1)
const note = ref('')
const openPrice = ref<number | null>(null)

/** ขั้นต่ำที่กลุ่มนี้ต้องเลือก — กลุ่มที่ตั้งว่าบังคับ ถือว่าอย่างน้อย 1 */
function minFor(group: ModifierGroup): number {
    return group.is_required ? Math.max(1, group.min_select) : group.min_select
}

/** ตั้งค่าเริ่มต้นจาก is_default — ไม่เกินโควตาของกลุ่ม */
function initialSelection(): Record<number, number[]> {
    const out: Record<number, number[]> = {}

    for (const group of props.product.modifier_groups) {
        out[group.id] = group.modifiers
            .filter((m) => m.is_default)
            .map((m) => m.id)
            .slice(0, group.max_select)
    }

    return out
}

const selected = ref<Record<number, number[]>>(initialSelection())

function isOn(groupId: number, modifierId: number): boolean {
    return (selected.value[groupId] ?? []).includes(modifierId)
}

function toggle(group: ModifierGroup, modifierId: number) {
    const current = selected.value[group.id] ?? []

    if (current.includes(modifierId)) {
        // กลุ่มบังคับเลือก ห้ามปลดจนเหลือน้อยกว่าขั้นต่ำ — ให้กดตัวอื่นแทนไปเลย
        if (current.length <= minFor(group)) {
            return
        }

        selected.value[group.id] = current.filter((id) => id !== modifierId)
        return
    }

    if (group.max_select === 1) {
        selected.value[group.id] = [modifierId]
        return
    }

    if (current.length < group.max_select) {
        selected.value[group.id] = [...current, modifierId]
    }
}

const selectedIds = computed(() => Object.values(selected.value).flat())

const chosenModifiers = computed<Modifier[]>(() =>
    props.product.modifier_groups.flatMap((g) => g.modifiers).filter((m) => selectedIds.value.includes(m.id)),
)

const totalPrice = computed(() => {
    const base = props.product.is_open_price ? Number(openPrice.value ?? 0) : props.product.price
    const extras = chosenModifiers.value.reduce((sum, m) => sum + Number(m.price_delta), 0)

    return (base + extras) * qty.value
})

/** ขนาดจานรวม — ผลคูณของตัวคูณจากทุกตัวเลือกที่เลือก */
const portion = computed(() =>
    chosenModifiers.value.reduce((acc, m) => acc * Number(m.portion_multiplier ?? 1), 1),
)

/** กลุ่มที่ยังเลือกไม่ครบ — บอกผู้ใช้ว่าติดตรงไหน แทนที่จะปิดปุ่มเฉย ๆ */
const incomplete = computed(() =>
    props.product.modifier_groups.filter((g) => (selected.value[g.id]?.length ?? 0) < minFor(g)),
)

function groupHint(group: ModifierGroup): string {
    const min = minFor(group)
    const count = selected.value[group.id]?.length ?? 0

    if (min > 0 && count < min) {
        return `ต้องเลือกอีก ${min - count}`
    }

    if (group.max_select > 1) {
        return `เลือกแล้ว ${count} / ${group.max_select}`
    }

    return min > 0 ? 'ต้องเลือก' : 'เลือกหรือไม่ก็ได้'
}

function confirm() {
    if (incomplete.value.length) return
    emit('confirm', props.product.id, qty.value, selectedIds.value, note.value || null, openPrice.value)
}

function onUpdate(value: boolean) {
    open.value = value
    if (!value) emit('close')
}
</script>

<template>
    <Modal
        :open="open"
        :title="product.name"
        description="เลือกตัวเลือกและจำนวนก่อนใส่ลงบิล"
        @update:open="onUpdate"
    >
        <div class="max-h-[50vh] space-y-4 overflow-y-auto">
            <div v-if="product.is_open_price" class="space-y-1">
                <Label for="oprice">ราคา (บาท)</Label>
                <Input id="oprice" v-model="openPrice" type="number" step="0.01" min="0" />
            </div>

            <div v-for="group in product.modifier_groups" :key="group.id" class="space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-medium">
                        {{ group.name }}
                        <span v-if="minFor(group) > 0" class="text-[var(--status-critical)]">*</span>
                    </p>
                    <span
                        class="text-xs"
                        :class="
                            (selected[group.id]?.length ?? 0) < minFor(group)
                                ? 'text-[var(--status-critical)]'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ groupHint(group) }}
                    </span>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="m in group.modifiers"
                        :key="m.id"
                        type="button"
                        class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm transition-colors"
                        :class="
                            isOn(group.id, m.id)
                                ? 'border-[var(--series-1)] bg-[var(--series-1)]/10 text-[var(--series-1)]'
                                : 'hover:bg-accent'
                        "
                        :aria-pressed="isOn(group.id, m.id)"
                        @click="toggle(group, m.id)"
                    >
                        <Check v-if="isOn(group.id, m.id)" class="size-3.5 shrink-0" />
                        {{ m.name }}

                        <!-- ตัวเลือกที่เปลี่ยนขนาดจาน โชว์ตัวคูณให้เห็นว่าได้เยอะขึ้นจริง -->
                        <span
                            v-if="Number(m.portion_multiplier ?? 1) !== 1"
                            class="rounded bg-current/10 px-1 text-[11px] font-medium"
                        >
                            ×{{ m.portion_multiplier }}
                        </span>

                        <span v-if="Number(m.price_delta) !== 0" class="tabular text-xs opacity-70">
                            {{ Number(m.price_delta) > 0 ? '+' : '' }}{{ money(m.price_delta) }}
                        </span>
                    </button>
                </div>
            </div>

            <div class="space-y-1">
                <Label for="mnote">หมายเหตุถึงครัว</Label>
                <Input id="mnote" v-model="note" placeholder="เช่น ไม่ใส่ผักชี" />
            </div>
        </div>

        <div class="space-y-2 border-t pt-3">
            <p v-if="incomplete.length" class="flex items-center gap-2 text-xs text-[var(--status-critical)]">
                <TriangleAlert class="size-4 shrink-0" />
                ยังเลือกไม่ครบ: {{ incomplete.map((g) => g.name).join(', ') }}
            </p>

            <p v-else-if="portion !== 1" class="text-xs text-muted-foreground">
                ขนาดจาน ×{{ portion }} — ระบบจะตัดวัตถุดิบตามสัดส่วนนี้
            </p>

            <div class="flex items-center gap-2">
                <div class="flex items-center gap-1">
                    <Button variant="outline" size="icon" aria-label="ลดจำนวน" @click="qty = Math.max(1, qty - 1)">-</Button>
                    <span class="tabular w-10 text-center">{{ qty }}</span>
                    <Button variant="outline" size="icon" aria-label="เพิ่มจำนวน" @click="qty++">+</Button>
                </div>

                <Button variant="brand" class="ml-auto" :disabled="incomplete.length > 0" @click="confirm">
                    เพิ่มลงบิล · {{ money(totalPrice) }} บาท
                </Button>
            </div>
        </div>
    </Modal>
</template>
