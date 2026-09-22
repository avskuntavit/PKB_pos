<script setup lang="ts">
/**
 * เลย์เอาต์หน้าลูกค้า — ออกแบบสำหรับมือถือเป็นหลัก
 * ไม่มีเมนูของระบบ ไม่มีปุ่มออกจากระบบ เพราะลูกค้าไม่ได้ล็อกอิน
 */
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import type { PageProps } from '@/types'

defineProps<{
    branchName: string
    tableName: string
    zoneName?: string | null
}>()

const page = usePage<PageProps>()
const flash = computed(() => page.props.flash)
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-muted/40">
        <header class="sticky top-0 z-30 border-b bg-card">
            <div class="mx-auto flex max-w-2xl items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold">{{ branchName }}</p>
                    <p class="truncate text-xs text-muted-foreground">
                        โต๊ะ {{ tableName }}<template v-if="zoneName"> · {{ zoneName }}</template>
                    </p>
                </div>
                <slot name="header-action" />
            </div>
        </header>

        <div
            v-if="flash.success"
            class="border-b border-[var(--status-good)]/30 bg-[var(--status-good)]/10 px-4 py-2 text-center text-sm text-[var(--status-good)]"
        >
            {{ flash.success }}
        </div>
        <div
            v-if="flash.error"
            class="border-b border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 px-4 py-2 text-center text-sm text-[var(--status-critical)]"
        >
            {{ flash.error }}
        </div>

        <main class="mx-auto w-full max-w-2xl flex-1 pb-28">
            <slot />
        </main>

        <slot name="bottom-bar" />
    </div>
</template>
