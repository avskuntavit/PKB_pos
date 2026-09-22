<script setup lang="ts">
import { cn } from '@/lib/utils'

defineProps<{ class?: string; for?: string }>()
</script>

<template>
    <label :for="$props.for" :class="cn('text-xs font-medium text-muted-foreground', $props.class)">
        <slot />
    </label>
</template>
