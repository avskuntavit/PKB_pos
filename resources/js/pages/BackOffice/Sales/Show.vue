<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ArrowLeft } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Badge from '@/components/ui/Badge.vue'
import { dateTime, money, number } from '@/lib/format'

defineProps<{ order: Record<string, any> }>()
</script>

<template>
    <Head :title="`บิล ${order.order_no}`" />

    <BackOfficeLayout :title="`บิล ${order.order_no}`">
        <Link
            href="/backoffice/sales"
            class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" />
            กลับไปรายการบิล
        </Link>

        <div class="grid gap-4 lg:grid-cols-3">
            <SectionCard title="รายการในบิล" class="lg:col-span-2" content-class="p-0">
                <DataTable>
                    <thead>
                        <tr>
                            <th>สินค้า</th>
                            <th class="text-right">ราคา</th>
                            <th class="text-right">จำนวน</th>
                            <th class="text-right">รวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in order.items" :key="item.id">
                            <td>
                                <div class="font-medium" :class="{ 'line-through opacity-50': item.status === 'void' }">
                                    {{ item.product_name }}
                                </div>
                                <div v-if="item.modifiers?.length" class="text-xs text-muted-foreground">
                                    {{ item.modifiers.map((m: any) => m.name).join(', ') }}
                                </div>
                                <div v-if="item.note" class="text-xs text-muted-foreground">หมายเหตุ: {{ item.note }}</div>
                            </td>
                            <td class="tabular text-right">{{ money(item.unit_price) }}</td>
                            <td class="tabular text-right">{{ number(item.qty) }}</td>
                            <td class="tabular text-right font-medium">{{ money(item.line_total) }}</td>
                        </tr>
                    </tbody>
                </DataTable>
            </SectionCard>

            <div class="space-y-4">
                <SectionCard title="ข้อมูลบิล">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">สถานะ</dt>
                            <dd><Badge>{{ order.status }}</Badge></dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">เลขใบเสร็จ</dt>
                            <dd class="tabular">{{ order.receipt_no ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">โต๊ะ</dt>
                            <dd>{{ order.dining_table?.name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ลูกค้า</dt>
                            <dd>{{ order.customer?.name ?? 'ทั่วไป' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">เปิดบิล</dt>
                            <dd>{{ dateTime(order.opened_at) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ปิดบิล</dt>
                            <dd>{{ dateTime(order.closed_at) }}</dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title="สรุปยอด">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ยอดขาย</dt>
                            <dd class="tabular">{{ money(order.subtotal) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ส่วนลด</dt>
                            <dd class="tabular">
                                -{{ money(Number(order.item_discount) + Number(order.bill_discount) + Number(order.voucher_discount)) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ค่าบริการ</dt>
                            <dd class="tabular">{{ money(order.service_charge) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">ภาษี</dt>
                            <dd class="tabular">{{ money(order.tax_amount) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2 border-t pt-1.5 text-base font-semibold">
                            <dt>รวมสุทธิ</dt>
                            <dd class="tabular">{{ money(order.grand_total) }}</dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title="การชำระเงิน">
                    <ul class="space-y-1.5 text-sm">
                        <li v-for="p in order.payments" :key="p.id" class="flex justify-between gap-2">
                            <span class="text-muted-foreground">{{ p.method }}</span>
                            <span class="tabular">{{ money(p.amount) }}</span>
                        </li>
                    </ul>
                </SectionCard>
            </div>
        </div>
    </BackOfficeLayout>
</template>
