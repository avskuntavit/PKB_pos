<script setup lang="ts">
import { cn } from '@/lib/utils'

defineProps<{ class?: string }>()
const model = defineModel<string | number | null>()
</script>

<template>
    <select
        v-model="model"
        :class="
            cn(
                'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                $props.class,
            )
        "
    >
        <slot />
    </select>
</template>
