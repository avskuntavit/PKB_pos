<script setup lang="ts">
/** สลิปใบสั่งครัวขนาด 80 มม. — กดพิมพ์จากเบราว์เซอร์ได้เลย ไม่ต้องลง driver เพิ่ม */
import { onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import { Printer } from 'lucide-vue-next'
import PosLayout from '@/layouts/PosLayout.vue'
import Button from '@/components/ui/Button.vue'
import { dateTime, number } from '@/lib/format'

defineProps<{ ticket: Record<string, any> }>()

function print() {
    window.print()
}

onMounted(() => {
    setTimeout(print, 400)
})
</script>

<template>
    <Head :title="`ใบสั่งครัว ${ticket.ticket_no}`" />

    <PosLayout title="ใบสั่งครัว">
        <div class="h-full overflow-y-auto p-4">
            <div class="mx-auto max-w-sm space-y-4">
                <div class="flex gap-2 no-print">
                    <Link
                        href="/kitchen"
                        class="grid h-11 flex-1 place-items-center rounded-md border text-sm hover:bg-accent"
                    >
                        กลับหน้าจอครัว
                    </Link>
                    <Button variant="brand" class="h-11 flex-1" @click="print">
                        <Printer />
                        พิมพ์อีกครั้ง
                    </Button>
                </div>

                <div class="rounded-lg border bg-white p-5 text-black">
                    <div class="text-center">
                        <p class="text-xl font-bold">
                            {{ ticket.dining_table?.name ? `โต๊ะ ${ticket.dining_table.name}` : 'ซื้อกลับบ้าน' }}
                        </p>
                        <p class="text-sm font-medium">{{ ticket.print_group_label ?? 'ครัว' }}</p>
                    </div>

                    <div class="mt-3 space-y-0.5 border-y border-dashed py-2 text-xs">
                        <p>ใบที่: {{ ticket.ticket_no }}</p>
                        <p>บิล: {{ ticket.order?.order_no }} · รอบที่ {{ ticket.round }}</p>
                        <p>เวลา: {{ dateTime(ticket.queued_at) }}</p>
                        <p v-if="ticket.source === 'self_order'" class="font-bold">** ลูกค้าสั่งเองผ่าน QR **</p>
                    </div>

                    <ul class="mt-2 space-y-2">
                        <li v-for="item in ticket.items" :key="item.id" class="border-b border-dotted pb-2 last:border-0">
                            <p class="text-lg font-bold">
                                {{ number(item.qty) }} × {{ item.product_name }}
                            </p>
                            <p v-if="item.modifiers_text" class="pl-4 text-sm">
                                - {{ item.modifiers_text }}
                            </p>
                            <p v-if="item.note" class="pl-4 text-sm font-bold">
                                ★ {{ item.note }}
                            </p>
                        </li>
                    </ul>

                    <p class="mt-4 text-center text-xs">-- จบรายการ --</p>
                </div>
            </div>
        </div>
    </PosLayout>
</template>
