<script setup lang="ts">
/**
 * แถบพนักงาน — ขึ้นเฉพาะตอนมีคนล็อกอินอยู่
 *
 * ใช้ตอนรับออเดอร์ทางโทรศัพท์หรือเดินรับที่โต๊ะ:
 * สั่งจากหน้าจอเดียวกับลูกค้า แต่ของที่สั่งเข้าครัวทันทีโดยไม่ต้องรออนุมัติ
 */
import { Headset } from 'lucide-vue-next'
import Select from '@/components/ui/Select.vue'

defineProps<{
    userName: string
    tables: Array<{ id: number; name: string; seats: number; status: string }>
}>()

const tableId = defineModel<number | ''>('tableId', { default: '' })
</script>

<template>
    <div class="border-b border-[var(--series-1)]/30 bg-[var(--series-1)]/10">
        <div class="mx-auto flex max-w-2xl flex-wrap items-center gap-2 px-4 py-2">
            <Headset class="size-4 shrink-0 text-[var(--series-1)]" />
            <p class="text-xs font-medium text-[var(--series-1)]">
                โหมดพนักงาน · {{ userName }}
            </p>

            <Select v-model="tableId" class="ml-auto h-8 w-auto min-w-[140px] text-xs">
                <option value="">ไม่ระบุโต๊ะ (รับที่ร้าน)</option>
                <option v-for="t in tables" :key="t.id" :value="t.id">
                    โต๊ะ {{ t.name }} ({{ t.seats }} ที่นั่ง)
                </option>
            </Select>
        </div>

        <p class="mx-auto max-w-2xl px-4 pb-2 text-xs text-muted-foreground">
            ออเดอร์ที่คุณสั่งจะถือว่าร้านรับแล้ว และเข้าครัวทันทีโดยไม่ต้องรอยืนยัน
        </p>
    </div>
</template>
