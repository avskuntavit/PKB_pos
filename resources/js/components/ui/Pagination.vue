<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
    links: Array<{ url: string | null; label: string; active: boolean }>
    total?: number
}>()
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
        <p v-if="total !== undefined" class="text-xs text-muted-foreground">
            ทั้งหมด {{ new Intl.NumberFormat('th-TH').format(total) }} รายการ
        </p>

        <nav class="flex flex-wrap gap-1" aria-label="แบ่งหน้า">
            <template v-for="(link, i) in links" :key="i">
                <span
                    v-if="!link.url"
                    class="rounded-md px-2.5 py-1 text-xs text-muted-foreground/50"
                    v-html="link.label"
                />
                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-2.5 py-1 text-xs transition-colors"
                    :class="
                        link.active
                            ? 'bg-[var(--series-1)] text-white'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                    "
                    v-html="link.label"
                />
            </template>
        </nav>
    </div>
</template>
