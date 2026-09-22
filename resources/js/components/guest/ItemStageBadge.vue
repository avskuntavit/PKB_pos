<script setup lang="ts">
/** สถานะอาหารที่ลูกค้าเห็น — ใช้คำที่คนนอกร้านเข้าใจ ไม่ใช่ศัพท์ในระบบ */
import { computed } from 'vue'

const props = defineProps<{ stage: string }>()

const map: Record<string, { label: string; class: string }> = {
    waiting_approval: {
        label: 'รอพนักงานยืนยัน',
        class: 'bg-[var(--status-warning)]/15 text-[var(--status-warning)]',
    },
    queued: {
        label: 'รอคิวทำ',
        class: 'bg-muted text-muted-foreground',
    },
    preparing: {
        label: 'กำลังปรุง',
        class: 'bg-[var(--series-1)]/12 text-[var(--series-1)]',
    },
    ready: {
        label: 'กำลังนำไปเสิร์ฟ',
        class: 'bg-[var(--series-3)]/15 text-[var(--series-3)]',
    },
    served: {
        label: 'เสิร์ฟแล้ว',
        class: 'bg-[var(--status-good)]/12 text-[var(--status-good)]',
    },
}

const view = computed(() => map[props.stage] ?? map.queued)
</script>

<template>
    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium" :class="view.class">
        {{ view.label }}
    </span>
</template>
