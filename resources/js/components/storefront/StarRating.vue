<script setup lang="ts">
/** ให้ดาว 1-5 — กดได้ด้วยนิ้วบนมือถือ และใช้เป็นตัวแสดงผลอย่างเดียวก็ได้ */
import { Star } from 'lucide-vue-next'

withDefaults(defineProps<{ readonly?: boolean; size?: 'sm' | 'lg' }>(), {
    readonly: false,
    size: 'lg',
})

const model = defineModel<number>({ default: 0 })

const labels = ['', 'ต้องปรับปรุง', 'พอใช้', 'ดี', 'ดีมาก', 'ประทับใจ']
</script>

<template>
    <div class="flex flex-col items-center gap-1">
        <div class="flex gap-1" role="radiogroup" aria-label="ให้คะแนน">
            <button
                v-for="n in 5"
                :key="n"
                type="button"
                :disabled="readonly"
                :aria-label="`${n} ดาว`"
                :aria-checked="model === n"
                role="radio"
                class="rounded-md p-0.5 transition-transform disabled:cursor-default"
                :class="readonly ? '' : 'active:scale-90'"
                @click="!readonly && (model = n)"
            >
                <Star
                    :class="[
                        size === 'lg' ? 'size-9' : 'size-4',
                        n <= model ? 'fill-[var(--status-warning)] text-[var(--status-warning)]' : 'text-muted-foreground/40',
                    ]"
                />
            </button>
        </div>

        <p v-if="!readonly && model > 0" class="text-sm font-medium">{{ labels[model] }}</p>
    </div>
</template>
