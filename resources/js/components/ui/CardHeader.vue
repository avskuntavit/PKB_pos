<script setup lang="ts">
import { cn } from '@/lib/utils'

defineProps<{ class?: string }>()
</script>

<template>
    <div :class="cn('flex flex-row items-center justify-between gap-2 border-b px-5 py-3.5', $props.class)">
        <slot />
    </div>
</template>
