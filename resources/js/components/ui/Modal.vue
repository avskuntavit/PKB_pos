<script setup lang="ts">
import { DialogClose, DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui'
import { X } from 'lucide-vue-next'
import { cn } from '@/lib/utils'

withDefaults(defineProps<{ title?: string; description?: string; class?: string }>(), {})

const open = defineModel<boolean>('open', { default: false })
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 bg-black/50 data-[state=open]:animate-in data-[state=open]:fade-in-0" />
            <DialogContent
                :class="
                    cn(
                        'fixed left-1/2 top-1/2 z-50 grid w-[calc(100vw-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 gap-4 rounded-xl border bg-background p-5 shadow-lg data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95',
                        $props.class,
                    )
                "
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-1">
                        <DialogTitle class="text-base font-semibold">{{ title }}</DialogTitle>
                        <p v-if="description" class="text-xs text-muted-foreground">{{ description }}</p>
                    </div>
                    <DialogClose class="rounded-md p-1 text-muted-foreground hover:bg-accent" aria-label="ปิด">
                        <X class="size-4" />
                    </DialogClose>
                </div>

                <slot />
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
