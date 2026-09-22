<script setup lang="ts">
/** การ์ดตัวเลขสรุป — ตัวเลขเด่น 1 ค่า พร้อมบรรทัดอธิบายสั้น ๆ */
withDefaults(
    defineProps<{
        label: string
        value: string
        unit?: string
        hint?: string
        tone?: 'default' | 'good' | 'warning' | 'critical'
    }>(),
    { tone: 'default' },
)

const toneClass: Record<string, string> = {
    default: 'text-foreground',
    good: 'text-[var(--status-good)]',
    warning: 'text-[var(--status-warning)]',
    critical: 'text-[var(--status-critical)]',
}
</script>

<template>
    <div class="rounded-lg border bg-card p-4">
        <p class="text-xs text-muted-foreground">{{ label }}</p>
        <p class="mt-1 flex items-baseline gap-1">
            <span class="tabular text-2xl font-semibold" :class="toneClass[tone]">{{ value }}</span>
            <span v-if="unit" class="text-xs text-muted-foreground">{{ unit }}</span>
        </p>
        <p v-if="hint" class="mt-0.5 text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
