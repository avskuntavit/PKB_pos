<script setup lang="ts">
/**
 * รายละเอียดสมาชิก — เลื่อนขึ้นจากขอบล่างเมื่อกด "รายละเอียด" บนแถบสมาชิก
 *
 * รวมทุกอย่างที่ลูกค้าอยากทำกับบัญชีไว้ที่เดียว คะแนน คูปอง ประวัติ และออกจากระบบ
 * ไม่ต้องพาออกจากหน้าเมนู เพราะลูกค้ากำลังจะสั่งของอยู่
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { BadgeCheck, ChevronRight, History, LogOut, Ticket, X } from 'lucide-vue-next'
import type { MemberSummary } from '@/types'

defineProps<{ member: MemberSummary }>()

const emit = defineEmits<{ close: [] }>()

const open = ref(false)

function dismiss() {
    open.value = false
}

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') dismiss()
}

onMounted(() => {
    open.value = true
    document.documentElement.style.overflow = 'hidden'
    window.addEventListener('keydown', onEscape)
})

onBeforeUnmount(() => {
    document.documentElement.style.overflow = ''
    window.removeEventListener('keydown', onEscape)
})

function logout() {
    router.post('/order/logout', {}, { onFinish: dismiss })
}
</script>

<template>
    <Teleport to="body">
        <Transition
            appear
            enter-active-class="transition-opacity duration-300 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-200 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-40 bg-black/50" @click="dismiss" />
        </Transition>

        <Transition
            appear
            enter-active-class="transition-transform duration-300 ease-out"
            enter-from-class="translate-y-full"
            leave-active-class="transition-transform duration-200 ease-in"
            leave-to-class="translate-y-full"
            @after-leave="emit('close')"
        >
            <div
                v-if="open"
                class="fixed inset-x-0 bottom-0 z-50 mx-auto w-full max-w-2xl overflow-hidden rounded-t-2xl bg-background shadow-2xl"
                role="dialog"
                aria-modal="true"
                aria-label="รายละเอียดสมาชิก"
            >
                <header class="flex h-14 items-center justify-between gap-2 border-b px-4">
                    <h2 class="text-lg font-bold">รายละเอียดสมาชิก</h2>
                    <button
                        type="button"
                        class="grid size-10 place-items-center rounded-full transition-colors hover:bg-accent"
                        aria-label="ปิด"
                        @click="dismiss"
                    >
                        <X class="size-5" />
                    </button>
                </header>

                <div class="max-h-[70dvh] overflow-y-auto overscroll-contain">
                    <!-- การ์ดชื่อ + คะแนน -->
                    <div class="p-4">
                        <div class="flex items-center justify-between gap-3 rounded-xl border p-4 shadow-sm">
                            <div class="min-w-0">
                                <p class="flex items-center gap-1.5 text-lg font-bold">
                                    <span class="truncate">{{ member.name }}</span>
                                    <BadgeCheck
                                        v-if="member.is_verified_employee"
                                        class="size-4 shrink-0 text-[var(--series-1)]"
                                        aria-label="พนักงานองค์กรที่ยืนยันแล้ว"
                                    />
                                </p>
                                <p v-if="member.phone" class="tabular mt-0.5 text-sm text-muted-foreground">
                                    {{ member.phone }}
                                </p>
                            </div>

                            <div class="shrink-0 text-center">
                                <p class="tabular text-2xl font-bold leading-none text-[var(--series-1)]">
                                    {{ member.points }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">คะแนน</p>
                            </div>
                        </div>
                    </div>

                    <!-- เมนูของบัญชี -->
                    <ul class="divide-y border-y">
                        <li>
                            <Link
                                href="/order/account"
                                class="flex items-center gap-3 px-4 py-4 transition-colors active:bg-accent"
                            >
                                <Ticket class="size-5 shrink-0 text-muted-foreground" />
                                <span class="min-w-0 flex-1 text-base">แลกคูปอง</span>
                                <ChevronRight class="size-5 shrink-0 text-muted-foreground" />
                            </Link>
                        </li>
                        <li>
                            <Link
                                href="/order/account"
                                class="flex items-center gap-3 px-4 py-4 transition-colors active:bg-accent"
                            >
                                <History class="size-5 shrink-0 text-muted-foreground" />
                                <span class="min-w-0 flex-1 text-base">ประวัติการสะสม / แลกคะแนน</span>
                                <ChevronRight class="size-5 shrink-0 text-muted-foreground" />
                            </Link>
                        </li>
                        <li>
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 px-4 py-4 text-left transition-colors active:bg-accent"
                                @click="logout"
                            >
                                <LogOut class="size-5 shrink-0 text-muted-foreground" />
                                <span class="min-w-0 flex-1 text-base">ยกเลิกการเชื่อมต่อสมาชิก</span>
                                <ChevronRight class="size-5 shrink-0 text-muted-foreground" />
                            </button>
                        </li>
                    </ul>

                    <p class="px-4 py-3 text-xs text-muted-foreground">
                        ยกเลิกการเชื่อมต่อแล้วคะแนนยังอยู่ครบ เข้าสู่ระบบด้วยเบอร์เดิมเมื่อไหร่ก็เห็นเหมือนเดิม
                    </p>
                </div>

                <div :style="{ height: 'env(safe-area-inset-bottom, 0px)' }" />
            </div>
        </Transition>
    </Teleport>
</template>
