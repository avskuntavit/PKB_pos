<script setup lang="ts">
/**
 * รูปเมนู — ถ้าร้านยังไม่ได้อัปโหลดรูป จะวาดกล่องสีพร้อมอักษรแรกแทน
 * เลือกสีจากชื่อเมนูแบบคงที่ เมนูเดิมจะได้สีเดิมทุกครั้ง ไม่กะพริบเปลี่ยนสี
 */
import { computed } from 'vue'

const props = withDefaults(
    defineProps<{
        name: string
        src?: string | null
        size?: 'sm' | 'lg' | 'xl'
    }>(),
    { size: 'sm' },
)

const palette = [
    'var(--series-1)',
    'var(--series-2)',
    'var(--series-3)',
    'var(--series-4)',
    'var(--series-5)',
    'var(--series-7)',
]

const tone = computed(() => {
    let hash = 0

    for (let i = 0; i < props.name.length; i++) {
        hash = (hash * 31 + props.name.charCodeAt(i)) >>> 0
    }

    return palette[hash % palette.length]
})

const initial = computed(() => props.name.trim().charAt(0) || '?')

const sizeClass = computed(() => {
    if (props.size === 'xl') return 'size-28 rounded-xl text-4xl'
    if (props.size === 'lg') return 'size-24 text-3xl'
    return 'size-16 text-xl'
})
</script>

<template>
    <img
        v-if="src"
        :src="src"
        :alt="name"
        class="shrink-0 rounded-lg object-cover"
        :class="sizeClass"
        loading="lazy"
    />
    <div
        v-else
        class="grid shrink-0 place-items-center rounded-lg font-semibold text-white"
        :class="sizeClass"
        :style="{ background: tone }"
        aria-hidden="true"
    >
        {{ initial }}
    </div>
</template>
