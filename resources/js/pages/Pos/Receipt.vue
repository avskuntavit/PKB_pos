<script setup lang="ts">
import { onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import { Printer } from 'lucide-vue-next'
import PosLayout from '@/layouts/PosLayout.vue'
import Button from '@/components/ui/Button.vue'
import { dateTime, money, number } from '@/lib/format'

const props = defineProps<{ order: Record<string, any> }>()

const activeItems = props.order.items?.filter((i: any) => i.status !== 'void') ?? []

function print() {
    window.print()
}

onMounted(() => {
    // เปิดหน้าต่างพิมพ์ให้อัตโนมัติ แคชเชียร์ไม่ต้องกดเพิ่ม
    setTimeout(print, 400)
})
</script>

<template>
    <Head :title="`ใบเสร็จ ${order.receipt_no ?? order.order_no}`" />

    <PosLayout title="ใบเสร็จ">
        <div class="h-full overflow-y-auto p-4">
            <div class="mx-auto max-w-sm space-y-4">
                <div class="flex gap-2 no-print">
                    <Button variant="outline" class="flex-1" as="a" href="/pos">กลับผังโต๊ะ</Button>
                    <Button variant="brand" class="flex-1" @click="print">
                        <Printer />
                        พิมพ์ใบเสร็จ
                    </Button>
                </div>

                <!-- ใบเสร็จขนาดกระดาษความร้อน 80 มม. -->
                <div class="rounded-lg border bg-white p-5 text-black">
                    <div class="text-center">
                        <p class="text-base font-semibold">{{ order.branch?.name }}</p>
                        <p class="text-xs">เลขประจำตัวผู้เสียภาษี {{ order.branch?.tax_id ?? '-' }}</p>
                        <p class="mt-2 text-xs">ใบเสร็จรับเงิน / ใบกำกับภาษีอย่างย่อ</p>
                    </div>

                    <div class="mt-3 space-y-0.5 border-y border-dashed py-2 text-xs">
                        <p>เลขที่: {{ order.receipt_no ?? order.order_no }}</p>
                        <p>วันที่: {{ dateTime(order.closed_at) }}</p>
                        <p v-if="order.dining_table">โต๊ะ: {{ order.dining_table.name }}</p>
                        <p>พนักงาน: {{ order.closed_by?.name ?? '-' }}</p>
                    </div>

                    <table class="mt-2 w-full text-xs">
                        <tbody>
                            <tr v-for="item in activeItems" :key="item.id" class="align-top">
                                <td class="py-1">
                                    {{ item.product_name }}
                                    <span v-if="item.modifiers?.length" class="block text-[10px] opacity-70">
                                        {{ item.modifiers.map((m: any) => m.name).join(', ') }}
                                    </span>
                                </td>
                                <td class="w-8 py-1 text-right">×{{ number(item.qty) }}</td>
                                <td class="w-16 py-1 text-right">{{ money(item.line_total) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <dl class="mt-2 space-y-0.5 border-t border-dashed pt-2 text-xs">
                        <div class="flex justify-between">
                            <dt>ยอดขาย</dt>
                            <dd>{{ money(order.subtotal) }}</dd>
                        </div>
                        <div v-if="Number(order.bill_discount) > 0" class="flex justify-between">
                            <dt>ส่วนลด</dt>
                            <dd>-{{ money(order.bill_discount) }}</dd>
                        </div>
                        <div v-if="Number(order.voucher_discount) > 0" class="flex justify-between">
                            <dt>Voucher</dt>
                            <dd>-{{ money(order.voucher_discount) }}</dd>
                        </div>
                        <div v-if="Number(order.service_charge) > 0" class="flex justify-between">
                            <dt>ค่าบริการ</dt>
                            <dd>{{ money(order.service_charge) }}</dd>
                        </div>
                        <div class="flex justify-between border-t pt-1 text-sm font-semibold">
                            <dt>รวมสุทธิ</dt>
                            <dd>{{ money(order.grand_total) }}</dd>
                        </div>
                        <div class="flex justify-between opacity-70">
                            <dt>ภาษีมูลค่าเพิ่ม (รวมในราคาแล้ว)</dt>
                            <dd>{{ money(order.tax_amount) }}</dd>
                        </div>
                    </dl>

                    <dl class="mt-2 space-y-0.5 border-t border-dashed pt-2 text-xs">
                        <div v-for="p in order.payments" :key="p.id" class="flex justify-between">
                            <dt>{{ p.method }}</dt>
                            <dd>{{ money(p.amount) }}</dd>
                        </div>
                        <div v-if="Number(order.change_amount) > 0" class="flex justify-between">
                            <dt>เงินทอน</dt>
                            <dd>{{ money(order.change_amount) }}</dd>
                        </div>
                    </dl>

                    <p class="mt-4 text-center text-xs">ขอบคุณที่ใช้บริการ</p>
                </div>
            </div>
        </div>
    </PosLayout>
</template>
