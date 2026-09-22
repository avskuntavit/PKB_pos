<script setup lang="ts">
/**
 * แถบแจ้งเตือนเมื่อมีโต๊ะกดเรียกพนักงาน — ขึ้นทุกหน้าของ POS
 *
 * "ขอเปิดโต๊ะ" เด่นกว่าชนิดอื่นโดยตั้งใจ เพราะลูกค้าที่กดมายังสั่งอะไรไม่ได้เลย
 * นั่งรออยู่เฉย ๆ ต่างจากคนที่ขอน้ำซึ่งกินข้าวไปพลางได้
 */
import { Link, router } from '@inertiajs/vue3'
import { BellRing, Check, DoorOpen, HandPlatter } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'
import type { ServiceCallItem } from '@/types'

withDefaults(
    defineProps<{
        calls: ServiceCallItem[]
        /** หน้านี้เปิดโต๊ะได้เองไหม — มีแต่ผังโต๊ะที่ทำได้ หน้าอื่นส่งไปที่ผังแทน */
        canOpenTable?: boolean
    }>(),
    { canOpenTable: false },
)

const emit = defineEmits<{ refresh: []; openTable: [call: ServiceCallItem] }>()

const isOpenRequest = (call: ServiceCallItem) => call.type === 'open_table'

function iconFor(call: ServiceCallItem) {
    if (isOpenRequest(call)) return DoorOpen

    return call.type === 'bill' ? HandPlatter : BellRing
}

function acknowledge(call: ServiceCallItem) {
    router.post(`/pos/calls/${call.id}/ack`, {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('refresh'),
    })
}

function done(call: ServiceCallItem) {
    router.post(`/pos/calls/${call.id}/done`, {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('refresh'),
    })
}
</script>

<template>
    <div v-if="calls.length" class="border-b bg-[var(--series-2)]/10">
        <ul class="flex gap-2 overflow-x-auto px-4 py-2">
            <li
                v-for="call in calls"
                :key="call.id"
                class="flex shrink-0 items-center gap-2 rounded-lg border bg-card px-3 py-2"
                :class="isOpenRequest(call) && 'border-[var(--status-warning)] ring-1 ring-[var(--status-warning)]/40'"
            >
                <component
                    :is="iconFor(call)"
                    class="size-4 shrink-0"
                    :class="isOpenRequest(call) ? 'text-[var(--status-warning)]' : 'text-[var(--series-2)]'"
                />

                <span class="text-sm">
                    <span class="font-semibold">โต๊ะ {{ call.table }}</span>
                    <span class="text-muted-foreground"> · {{ call.label }}</span>
                    <span class="block text-xs text-muted-foreground">
                        รอมา {{ call.waiting_minutes }} นาที
                    </span>
                </span>

                <!-- ขอเปิดโต๊ะมีทางลัดของตัวเอง เพราะสิ่งที่พนักงานต้องทำคือ "เปิดบิล" ไม่ใช่ "รับเรื่อง" -->
                <Button
                    v-if="isOpenRequest(call) && canOpenTable"
                    variant="brand"
                    size="sm"
                    @click="emit('openTable', call)"
                >
                    <DoorOpen />
                    เปิดโต๊ะ
                </Button>
                <Link
                    v-else-if="isOpenRequest(call)"
                    href="/pos"
                    class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md bg-[var(--series-1)] px-3 text-xs font-medium text-white"
                >
                    <DoorOpen class="size-3.5" />
                    ไปเปิดโต๊ะ
                </Link>

                <Button
                    v-else-if="call.status === 'open'"
                    variant="outline"
                    size="sm"
                    @click="acknowledge(call)"
                >
                    รับเรื่อง
                </Button>
                <Button v-else variant="brand" size="sm" @click="done(call)">
                    <Check />
                    เสร็จ
                </Button>
            </li>
        </ul>
    </div>
</template>
