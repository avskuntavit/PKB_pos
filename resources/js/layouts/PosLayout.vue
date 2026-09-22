<script setup lang="ts">
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { ChartPie, ChefHat, ListOrdered, LogOut, ShoppingBag } from 'lucide-vue-next'
import BrandMark from '@/components/ui/BrandMark.vue'
import ConnectionBanner from '@/components/pos/ConnectionBanner.vue'
import type { PageProps } from '@/types'

defineProps<{ title?: string }>()

const page = usePage<PageProps>()
const user = computed(() => page.props.auth.user)
const canBackOffice = computed(() => ['owner', 'manager'].includes(user.value?.role ?? ''))
</script>

<template>
    <div class="flex h-dvh flex-col overflow-hidden bg-muted/40">
        <header class="flex h-14 shrink-0 items-center justify-between gap-2 border-b bg-card px-3 no-print sm:gap-3 sm:px-4 md:h-16">
            <div class="flex items-center gap-3">
                <Link href="/pos" class="flex items-center" aria-label="หน้าแรก">
                    <BrandMark :height="26" />
                </Link>
                <span class="hidden text-sm text-muted-foreground sm:inline">{{ title }}</span>
            </div>

            <div class="flex items-center gap-1 sm:gap-2">
                <span class="hidden text-sm text-muted-foreground lg:inline">
                    {{ page.props.currentBranch?.name }} · {{ user?.name }}
                </span>
                <Link
                    href="/pos/online-orders"
                    class="grid size-11 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent active:bg-accent"
                    aria-label="ออเดอร์ล่วงหน้า"
                >
                    <ShoppingBag class="size-5" />
                </Link>
                <Link
                    href="/pos/queue"
                    class="grid size-11 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent active:bg-accent"
                    aria-label="จัดการคิว"
                >
                    <ListOrdered class="size-5" />
                </Link>
                <Link
                    href="/kitchen"
                    class="grid size-11 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent active:bg-accent"
                    aria-label="หน้าจอครัว"
                >
                    <ChefHat class="size-5" />
                </Link>
                <Link
                    v-if="canBackOffice"
                    href="/backoffice/dashboard"
                    class="grid size-11 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent active:bg-accent"
                    aria-label="หลังบ้าน"
                >
                    <ChartPie class="size-5" />
                </Link>
                <button
                    class="grid size-11 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent active:bg-accent"
                    aria-label="ออกจากระบบ"
                    @click="router.post('/logout')"
                >
                    <LogOut class="size-5" />
                </button>
            </div>
        </header>

        <!--
            อยู่บนสุดถัดจากหัวจอ เพราะเป็นเรื่องที่ต้องรู้ก่อนจะตัดสินใจกดอะไรทั้งหมด
            วางไว้ใน layout ครั้งเดียว ทุกหน้าของ POS จึงได้ไปด้วยกันหมด
        -->
        <ConnectionBanner />

        <div
            v-if="page.props.flash.error"
            class="shrink-0 bg-[var(--status-critical)] px-4 py-2 text-sm text-white no-print"
        >
            {{ page.props.flash.error }}
        </div>

        <main class="min-h-0 flex-1 overflow-hidden">
            <slot />
        </main>
    </div>
</template>
