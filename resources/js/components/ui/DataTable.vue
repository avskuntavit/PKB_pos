<script setup lang="ts">
/**
 * ตารางบาง ๆ ครอบ <table> ให้สไตล์เหมือนกันทุกหน้า
 * ตารางกว้างเกินจอจะ scroll แนวนอนในกล่องตัวเอง ไม่ดันทั้งหน้า
 */
</script>

<template>
    <div class="dt-wrap w-full overflow-x-auto">
        <table class="w-full caption-bottom text-sm">
            <slot />
        </table>
    </div>
</template>

<style scoped>
.dt-wrap :deep(thead th) {
    height: 2.5rem;
    padding: 0 0.75rem;
    text-align: left;
    vertical-align: middle;
    white-space: nowrap;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--muted-foreground);
}

.dt-wrap :deep(tbody tr) {
    border-top: 1px solid var(--border);
}

.dt-wrap :deep(tbody tr:hover) {
    background: color-mix(in oklab, var(--muted) 50%, transparent);
}

.dt-wrap :deep(tbody td) {
    padding: 0.625rem 0.75rem;
    vertical-align: middle;
}
</style>
