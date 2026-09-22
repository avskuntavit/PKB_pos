<script setup lang="ts">
import { computed } from 'vue'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const badgeVariants = cva(
    'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground',
                secondary: 'border-transparent bg-secondary text-secondary-foreground',
                outline: 'text-foreground',
                success: 'border-transparent bg-[var(--status-good)]/12 text-[var(--status-good)]',
                warning: 'border-transparent bg-[var(--status-warning)]/15 text-[var(--status-warning)]',
                danger: 'border-transparent bg-[var(--status-critical)]/12 text-[var(--status-critical)]',
            },
        },
        defaultVariants: { variant: 'default' },
    },
)

import type { HTMLAttributes } from 'vue'

const props = defineProps<{
    variant?: VariantProps<typeof badgeVariants>['variant']
    class?: HTMLAttributes['class']
}>()

const classes = computed(() => cn(badgeVariants({ variant: props.variant }), props.class))
</script>

<template>
    <span :class="classes"><slot /></span>
</template>
