<script setup lang="ts">
/**
 * แผงยืนยันรายการที่ลูกค้าสแกนสั่งเอง
 *
 * ค่าเริ่มต้นคือเลือกไว้ทุกรายการ เพราะกรณีปกติคือยืนยันทั้งหมด
 * พนักงานเอาติ๊กออกเฉพาะอันที่มีปัญหา (ของหมด / ลูกค้ากดผิด)
 */
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { Check, QrCode, X } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import { money, number, time } from '@/lib/format'
import type { OrderItem } from '@/types'

const props = defineProps<{
    orderId: number
    items: OrderItem[]
}>()

const selected = ref<number[]>(props.items.map((i) => i.id))
const rejectReason = ref('')
const processing = ref(false)

watch(
    () => props.items,
    (items) => {
        selected.value = items.map((i) => i.id)
    },
)

const total = computed(() =>
    props.items
        .filter((i) => selected.value.includes(i.id))
        .reduce((sum, i) => sum + Number(i.line_total), 0),
)

function toggle(id: number) {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((x) => x !== id)
        : [...selected.value, id]
}

function submit(action: 'approve' | 'reject') {
    if (selected.value.length === 0) return

    processing.value = true

    router.post(
        `/pos/orders/${props.orderId}/${action}`,
        action === 'reject'
            ? { item_ids: selected.value, reason: rejectReason.value || null }
            : { item_ids: selected.value },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false
                rejectReason.value = ''
            },
        },
    )
}
</script>

<template>
    <div class="border-b bg-[var(--status-warning)]/8">
        <div class="flex items-center gap-2 px-4 py-2.5">
            <QrCode class="size-4 text-[var(--status-warning)]" />
            <p class="text-sm font-semibold text-[var(--status-warning)]">
                ลูกค้าสั่งเอง {{ items.length }} รายการ — รอยืนยัน
            </p>
        </div>

        <ul class="max-h-56 space-y-1 overflow-y-auto px-4 pb-2">
            <li v-for="item in items" :key="item.id">
                <label class="flex cursor-pointer items-start gap-2 rounded-lg border bg-card p-2.5">
                    <input
                        type="checkbox"
                        class="mt-0.5 size-4 shrink-0"
                        :checked="selected.includes(item.id)"
                        @change="toggle(item.id)"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">
                            {{ item.product_name }}
                            <span class="tabular text-muted-foreground">×{{ number(item.qty) }}</span>
                        </span>
                        <span v-if="item.modifiers.length" class="block truncate text-xs text-muted-foreground">
                            {{ item.modifiers.map((m) => m.name).join(', ') }}
                        </span>
                        <span v-if="item.note" class="block truncate text-xs text-[var(--status-warning)]">
                            ★ {{ item.note }}
                        </span>
                    </span>
                    <span class="tabular shrink-0 text-sm">{{ money(item.line_total) }}</span>
                </label>
            </li>
        </ul>

        <div class="space-y-2 px-4 pb-3">
            <Input v-model="rejectReason" placeholder="เหตุผลถ้าจะปฏิเสธ เช่น ของหมด" class="h-9" />

            <div class="flex gap-2">
                <Button
                    variant="outline"
                    class="flex-1"
                    :disabled="processing || selected.length === 0"
                    @click="submit('reject')"
                >
                    <X />
                    ปฏิเสธ
                </Button>
                <Button
                    variant="brand"
                    class="flex-[2]"
                    :disabled="processing || selected.length === 0"
                    @click="submit('approve')"
                >
                    <Check />
                    ยืนยัน {{ selected.length }} รายการ · {{ money(total) }} ฿
                </Button>
            </div>
        </div>
    </div>
</template>
