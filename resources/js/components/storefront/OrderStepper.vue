<script setup lang="ts">
/** แถบความคืบหน้าของออเดอร์ — อ่านได้ในแวบเดียวว่าถึงขั้นไหนแล้ว */
import { computed } from 'vue'
import { Check } from 'lucide-vue-next'

const props = defineProps<{
    stepIndex: number
    isFinished: boolean
    failed?: boolean
}>()

const steps = [
    { label: 'ส่งออเดอร์' },
    { label: 'ร้านรับแล้ว' },
    { label: 'กำลังทำ' },
    { label: 'พร้อมรับ' },
    { label: 'รับของแล้ว' },
]

const activeIndex = computed(() => Math.max(0, Math.min(props.stepIndex, steps.length - 1)))
</script>

<template>
    <ol v-if="!failed" class="flex items-start">
        <li v-for="(s, i) in steps" :key="s.label" class="flex flex-1 flex-col items-center">
            <div class="flex w-full items-center">
                <span class="h-0.5 flex-1" :class="i === 0 ? 'bg-transparent' : i <= activeIndex ? 'bg-[var(--series-1)]' : 'bg-border'" />

                <span
                    class="grid size-7 shrink-0 place-items-center rounded-full border-2 text-xs font-semibold"
                    :class="
                        i < activeIndex
                            ? 'border-[var(--series-1)] bg-[var(--series-1)] text-white'
                            : i === activeIndex
                              ? 'border-[var(--series-1)] bg-card text-[var(--series-1)]'
                              : 'border-border bg-card text-muted-foreground'
                    "
                >
                    <Check v-if="i < activeIndex" class="size-3.5" />
                    <template v-else>{{ i + 1 }}</template>
                </span>

                <span
                    class="h-0.5 flex-1"
                    :class="i === steps.length - 1 ? 'bg-transparent' : i < activeIndex ? 'bg-[var(--series-1)]' : 'bg-border'"
                />
            </div>

            <span
                class="mt-1 px-0.5 text-center text-[11px] leading-tight"
                :class="i <= activeIndex ? 'font-medium text-foreground' : 'text-muted-foreground'"
            >
                {{ s.label }}
            </span>
        </li>
    </ol>
</template>
