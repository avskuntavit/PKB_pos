<script setup lang="ts">
/**
 * หน้าตาของ "หนึ่งรายการในตะกร้า" อย่างที่ลูกค้าเห็น
 *
 * แยกออกมาจาก CartSheet เพื่อให้แผ่นตัวอย่างในหลังบ้านใช้มาร์กอัปชุดเดียวกันได้
 * ถ้าลอกไปวางซ้ำ วันที่แก้หน้าตะกร้าจริงแล้วลืมแก้ตัวอย่าง หลังบ้านจะโกหกทันที
 *
 * ตัวนี้แสดงผลอย่างเดียว ปุ่มปรับจำนวน/ลบ เป็นเรื่องของ CartSheet
 * ซึ่งส่งเข้ามาทาง slot "actions"
 */
import { money } from '@/lib/format'

defineProps<{
    qty: number
    name: string
    modifierNames: string[]
    note: string | null
    unitPrice: number
    /**
     * ชื่อคนที่หยิบรายการนี้ใส่ตะกร้า — มีเฉพาะตะกร้าร่วมของโต๊ะ
     *
     * ตะกร้าส่วนตัวไม่ต้องส่งมา เพราะมีเจ้าของคนเดียวอยู่แล้ว
     * การเขียนชื่อตัวเองกำกับทุกบรรทัดในตะกร้าตัวเองคือการรบกวนสายตาเปล่า ๆ
     */
    orderedBy?: string | null
}>()
</script>

<template>
    <div class="flex items-start gap-3">
        <span
            class="tabular grid size-7 shrink-0 place-items-center rounded-md border text-sm font-medium"
            :aria-label="`จำนวน ${qty}`"
        >
            {{ qty }}
        </span>

        <div class="min-w-0 flex-1">
            <p class="text-base font-medium">{{ name }}</p>

            <ul v-if="modifierNames.length" class="mt-1 space-y-0.5 text-sm text-muted-foreground">
                <li v-for="m in modifierNames" :key="m" class="flex gap-2">
                    <span aria-hidden="true">•</span>
                    <span class="min-w-0">{{ m }}</span>
                </li>
            </ul>

            <p v-if="note" class="mt-1 text-sm text-muted-foreground">หมายเหตุ: {{ note }}</p>

            <p v-if="orderedBy" class="mt-1 text-xs text-muted-foreground">
                <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5">
                    <span aria-hidden="true">👤</span>
                    {{ orderedBy }} สั่ง
                </span>
            </p>

            <slot name="actions" />
        </div>

        <span class="tabular shrink-0 text-base font-semibold">฿{{ money(unitPrice * qty) }}</span>
    </div>
</template>
