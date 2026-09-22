<script setup lang="ts">
/**
 * แจ้งว่า "ออเดอร์นี้จะวิ่งเข้าโต๊ะไหน"
 *
 * ของเดิมเป็นข้อความเทาบรรทัดเดียวขนาด 14px ปนกับชื่อร้าน ลูกค้าไม่ทันสังเกต
 * ซึ่งอันตรายกว่าที่คิด เพราะถ้าหยิบเมนูจากโต๊ะข้าง ๆ หรือสแกน QR ผิดโต๊ะ
 * บิลจะไปโผล่อีกโต๊ะแล้วรู้ตัวตอนพนักงานยกอาหารมาเสิร์ฟผิดที่
 *
 * จึงมีสามรูปแบบ ใช้คนละจังหวะ
 *   hero    — การ์ดใหญ่ เห็นครั้งแรกที่เปิดหน้า
 *   strip   — แถบบางสีเข้ม เกาะอยู่บนสุดตอนเลื่อนดูเมนู และในตะกร้า
 *   blocked — สแกน QR โต๊ะมาแล้วแต่โต๊ะยังไม่ถูกเปิด ยังสั่งเข้าโต๊ะไม่ได้
 *             มี slot ชื่อ action ไว้ใส่ปุ่มเรียกพนักงาน — ตัว banner ไม่รู้จักการเรียกพนักงานเอง
 *             เพราะมันเป็นแค่ป้ายบอกสถานะ ไม่ควรรู้เรื่อง endpoint ของหน้าแม่
 *
 * ทุกแบบอ่านค่าจาก props ที่หน้าแม่ได้มาจาก session ฝั่งเซิร์ฟเวอร์
 * ไม่ใช่ค่าที่หน้าเว็บตั้งเอง — ตอนเช็คเอาต์เซิร์ฟเวอร์ก็อ่าน session ซ้ำอยู่ดี
 */
import { Hourglass, Utensils } from 'lucide-vue-next'

withDefaults(
    defineProps<{
        /** ชื่อโต๊ะ เช่น A1 — variant 'blocked' ใช้ชื่ออย่างเดียว ไม่มีจำนวนที่นั่ง */
        tableName: string
        seats?: number | null
        branchName?: string | null
        variant?: 'hero' | 'strip' | 'blocked'
    }>(),
    { seats: null, branchName: null, variant: 'hero' },
)
</script>

<template>
    <!-- ── แถบบาง ── -->
    <div
        v-if="variant === 'strip'"
        class="bg-[var(--series-1)] text-white"
        role="status"
    >
        <div class="mx-auto flex max-w-2xl items-center gap-2 px-4 py-1.5 lg:max-w-5xl">
            <Utensils class="size-4 shrink-0" />
            <p class="min-w-0 flex-1 truncate text-sm">
                กำลังสั่งให้ <span class="text-base font-bold">{{ tableName }}</span>
            </p>
        </div>
    </div>

    <!-- ── สแกนมาแล้วแต่โต๊ะยังไม่ถูกเปิด ── -->
    <div
        v-else-if="variant === 'blocked'"
        class="rounded-2xl border-2 border-dashed border-[var(--status-warning)] bg-[var(--status-warning)]/10 p-4"
        role="status"
    >
        <div class="flex items-center gap-4">
            <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-[var(--status-warning)] text-white">
                <Hourglass class="size-7" />
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold tracking-wide text-[var(--status-warning)]">
                    ยังสั่งเข้าโต๊ะนี้ไม่ได้
                </p>
                <p class="truncate text-3xl font-black leading-tight">{{ tableName }}</p>
            </div>
        </div>

        <p class="mt-3 border-t border-[var(--status-warning)]/30 pt-2 text-sm">
            กดปุ่มด้านล่างเรียกพนักงานมาเปิดโต๊ะได้เลย — ระหว่างนี้ดูเมนูรอได้
            <span class="mt-1 block text-xs text-muted-foreground">
                พอพนักงานเปิดให้แล้ว รีเฟรชหน้านี้ครั้งเดียว ไม่ต้องสแกนใหม่
            </span>
        </p>

        <div class="mt-3">
            <slot name="action" />
        </div>
    </div>

    <!-- ── การ์ดใหญ่ ── -->
    <div
        v-else
        class="rounded-2xl border-2 border-[var(--series-1)] bg-[var(--series-1)]/10 p-4"
        role="status"
    >
        <div class="flex items-center gap-4">
            <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-[var(--series-1)] text-white">
                <Utensils class="size-7" />
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold tracking-wide text-[var(--series-1)]">
                    กำลังสั่งให้โต๊ะนี้
                </p>
                <!-- ชื่อโต๊ะคือข้อมูลที่ต้องอ่านออกจากระยะแขน จึงใหญ่ที่สุดในการ์ด -->
                <p class="truncate text-3xl font-black leading-tight">{{ tableName }}</p>
                <p v-if="branchName || seats" class="mt-0.5 truncate text-xs text-muted-foreground">
                    <template v-if="branchName">{{ branchName }}</template>
                    <template v-if="branchName && seats"> · </template>
                    <template v-if="seats">{{ seats }} ที่นั่ง</template>
                </p>
            </div>
        </div>

        <p class="mt-3 border-t border-[var(--series-1)]/25 pt-2 text-xs text-muted-foreground">
            อาหารจะถูกเสิร์ฟมาที่โต๊ะนี้ — ถ้าไม่ใช่โต๊ะที่คุณนั่ง สแกน QR บนโต๊ะของคุณอีกครั้ง
        </p>
    </div>
</template>
