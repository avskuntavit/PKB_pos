<script setup lang="ts">
/**
 * เลือกร้านก่อนเข้าดูเมนู
 *
 * โผล่เฉพาะตอนที่ระบบเดาไม่ออกจริง ๆ — สแกน QR ที่ร้าน หรือเคยเลือกไว้แล้ว
 * จะข้ามหน้านี้ไปเลย เลือกครั้งเดียวจำไว้ 90 วัน เปลี่ยนได้ตลอดจากหัวหน้าเมนู
 */
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { Head } from '@inertiajs/vue3'
import { Check, MapPin, Store } from 'lucide-vue-next'
import type { PageProps } from '@/types'

interface Station {
    code: string
    name: string
    intro: string | null
    address: string | null
    logo_path: string | null
    cover_path: string | null
    is_open_now: boolean
    is_taking_orders: boolean
    open_time: string
    close_time: string
}

const props = defineProps<{
    stations: Station[]
    currentCode: string | null
    /** ที่ที่จะพากลับไปหลังเลือกร้าน — ปกติคือหน้าเมนู */
    redirectTo: string
}>()

const page = usePage<PageProps>()

/** มาจากหน้าอื่น (เช่น เข้าสู่ระบบ) ไม่ใช่เข้ามาเลือกร้านเฉย ๆ */
const returning = computed(() => props.redirectTo !== '/order')

function select(code: string) {
    router.post(`/order/stations/${code}`, { redirect: props.redirectTo })
}
</script>

<template>
    <Head title="เลือกร้าน" />

    <div class="min-h-dvh bg-muted/40">
        <header class="border-b bg-card">
            <div class="mx-auto max-w-2xl px-4 py-5 text-center lg:max-w-5xl">
                <h1 class="text-xl font-bold">เลือกร้านที่จะสั่ง</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        returning
                            ? 'เลือกร้านแล้วระบบจะพากลับไปหน้าเดิมให้'
                            : 'เลือกครั้งเดียว ครั้งหน้าเข้าเมนูร้านนี้ได้เลย'
                    }}
                </p>
            </div>
        </header>

        <!-- เหตุผลที่ถูกพามาหน้านี้ เช่น กดลิงก์เข้าสู่ระบบตรง ๆ โดยยังไม่ได้เลือกร้าน -->
        <p
            v-if="page.props.flash.error"
            class="border-b border-[var(--status-warning)]/30 bg-[var(--status-warning)]/10 px-4 py-2 text-center text-sm"
        >
            {{ page.props.flash.error }}
        </p>

        <div class="mx-auto grid max-w-2xl gap-3 p-4 lg:max-w-5xl lg:grid-cols-2">
            <button
                v-for="s in stations"
                :key="s.code"
                type="button"
                class="flex w-full overflow-hidden rounded-2xl border-2 bg-card text-left transition-colors"
                :class="
                    s.code === currentCode
                        ? 'border-[var(--series-1)]'
                        : 'border-border active:bg-accent'
                "
                @click="select(s.code)"
            >
                <!-- รูปปกร้าน ถ้าไม่มีก็ใช้บล็อกสีแทน ไม่ปล่อยให้การ์ดแหว่ง -->
                <span class="relative w-28 shrink-0 self-stretch bg-muted">
                    <img
                        v-if="s.cover_path"
                        :src="s.cover_path"
                        :alt="s.name"
                        class="absolute inset-0 size-full object-cover"
                    />
                    <span v-else class="absolute inset-0 grid place-items-center">
                        <Store class="size-6 text-muted-foreground" />
                    </span>
                </span>

                <span class="min-w-0 flex-1 p-3">
                    <span class="flex items-start gap-2">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-base font-semibold">{{ s.name }}</span>
                            <span v-if="s.intro" class="block truncate text-xs text-muted-foreground">
                                {{ s.intro }}
                            </span>
                        </span>
                        <Check
                            v-if="s.code === currentCode"
                            class="mt-0.5 size-5 shrink-0 text-[var(--series-1)]"
                        />
                    </span>

                    <span v-if="s.address" class="mt-1 flex items-start gap-1 text-xs text-muted-foreground">
                        <MapPin class="mt-0.5 size-3 shrink-0" />
                        <span class="line-clamp-2">{{ s.address }}</span>
                    </span>

                    <span class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <span
                            class="rounded-full px-2 py-0.5"
                            :class="
                                s.is_open_now
                                    ? 'bg-[var(--status-good)]/12 text-[var(--status-good)]'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ s.is_open_now ? 'เปิดอยู่' : 'ปิดอยู่' }}
                        </span>
                        <span class="text-muted-foreground">{{ s.open_time }}–{{ s.close_time }}</span>
                        <span v-if="s.is_open_now && !s.is_taking_orders" class="text-[var(--status-warning)]">
                            พักรับออเดอร์ชั่วคราว
                        </span>
                    </span>
                </span>
            </button>

            <p v-if="!stations.length" class="rounded-xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground lg:col-span-2">
                ยังไม่มีร้านที่เปิดให้บริการ
            </p>
        </div>
    </div>
</template>
