<script setup lang="ts">
/**
 * แถบสัดส่วนแนวนอน + legend — ใช้โชว์สัดส่วนช่องทางชำระเงิน
 * ไม่ใช้กราฟวงกลมเพราะอ่านสัดส่วนยากกว่าเมื่อค่าใกล้กัน
 */
import { computed } from 'vue'
import { money, percent } from '@/lib/format'

const props = defineProps<{
    items: Array<{ label: string; amount: number; percent: number }>
}>()

const total = computed(() => props.items.reduce((sum, i) => sum + i.amount, 0))
</script>

<template>
    <div class="space-y-3">
        <div v-if="total > 0" class="flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full">
            <div
                v-for="(item, i) in items"
                :key="item.label"
                class="h-full first:rounded-l-full last:rounded-r-full"
                :style="{
                    width: `${item.percent}%`,
                    background: `var(--series-${(i % 8) + 1})`,
                }"
                :title="`${item.label} ${percent(item.percent)}`"
            />
        </div>
        <div v-else class="h-2.5 w-full rounded-full bg-muted" />

        <ul class="grid gap-1.5 text-xs sm:grid-cols-2">
            <li v-for="(item, i) in items" :key="item.label" class="flex items-center justify-between gap-2">
                <span class="flex min-w-0 items-center gap-1.5">
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :style="{ background: `var(--series-${(i % 8) + 1})` }"
                    />
                    <span class="truncate text-muted-foreground">{{ item.label }}</span>
                </span>
                <span class="tabular shrink-0 font-medium">
                    {{ money(item.amount) }}
                    <span class="text-muted-foreground">({{ percent(item.percent, 1) }})</span>
                </span>
            </li>
        </ul>
    </div>
</template>
