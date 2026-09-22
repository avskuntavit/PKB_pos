<script setup lang="ts">
defineProps<{ title?: string; description?: string }>()
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-1 px-4 py-12 text-center">
        <p class="text-sm font-medium">{{ title ?? 'ยังไม่มีข้อมูล' }}</p>
        <p v-if="description" class="text-xs text-muted-foreground">{{ description }}</p>
    </div>
</template>
