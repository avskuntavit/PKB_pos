<script setup lang="ts">
/**
 * แถบเตือนเมื่อหน้าจอ POS ติดต่อเซิร์ฟเวอร์ไม่ได้
 *
 * ── ทำไมต้องบอกตรง ๆ ว่า "ยังไม่ถูกบันทึก" ─────────────────
 * ของเดิมเวลาเน็ตหลุด ปุ่มจะหมุนค้างแล้วเงียบไป พนักงานตีความว่า
 * "ระบบอืด" แล้วกดซ้ำ ซึ่งเป็นที่มาของบิลซ้ำและใบสั่งครัวซ้ำ
 * แถบนี้จึงพูดสิ่งที่พนักงานต้องรู้จริง ๆ ก่อนจะตัดสินใจกดอะไรต่อ
 *
 * ── ทำไมนับถอยหลัง ─────────────────────────────────────────
 * ถ้าไม่บอกว่ากำลังลองใหม่อยู่ พนักงานจะคิดว่าระบบยอมแพ้ไปแล้ว
 * แล้วไปรีเฟรชหน้าเอง ซึ่งทำให้ของที่พิมพ์ค้างในฟอร์มหายหมด
 */
import { computed } from 'vue'
import { CloudOff, LogIn, RefreshCw } from 'lucide-vue-next'
import { useConnection } from '@/composables/useConnection'
import { time } from '@/lib/format'

const { isOffline, sessionExpired, lastOkAt, checking, retryInSeconds, checkNow } = useConnection()

const lastOkLabel = computed(() =>
    lastOkAt.value ? time(new Date(lastOkAt.value)) : null,
)
</script>

<template>
    <!-- หมดเวลาเข้าสู่ระบบ — เซิร์ฟเวอร์ยังอยู่ แค่ต้องล็อกอินใหม่ -->
    <div
        v-if="sessionExpired"
        class="shrink-0 bg-[var(--status-warning)] px-4 py-2 text-sm text-white no-print"
        role="alert"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <LogIn class="size-4 shrink-0" />
            <span class="font-semibold">หมดเวลาเข้าสู่ระบบแล้ว</span>
            <span class="opacity-90">สิ่งที่กดตอนนี้จะไม่ถูกบันทึก — เข้าสู่ระบบใหม่ก่อน</span>
            <a href="/login" class="ml-auto rounded-md bg-white/20 px-3 py-1 font-medium hover:bg-white/30">
                เข้าสู่ระบบ
            </a>
        </div>
    </div>

    <div
        v-else-if="isOffline"
        class="shrink-0 bg-[var(--status-critical)] px-4 py-2 text-sm text-white no-print"
        role="alert"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <CloudOff class="size-4 shrink-0" />

            <span class="font-semibold">ติดต่อเซิร์ฟเวอร์ไม่ได้</span>

            <span class="opacity-90">
                สิ่งที่กดตอนนี้จะยังไม่ถูกบันทึก
                <template v-if="lastOkLabel"> · ติดต่อได้ล่าสุด {{ lastOkLabel }}</template>
            </span>

            <button
                type="button"
                class="ml-auto flex shrink-0 items-center gap-1.5 rounded-md bg-white/20 px-3 py-1 font-medium transition-colors hover:bg-white/30 disabled:opacity-60"
                :disabled="checking"
                @click="checkNow"
            >
                <RefreshCw class="size-3.5" :class="checking && 'animate-spin'" />
                <template v-if="checking">กำลังลอง…</template>
                <template v-else-if="retryInSeconds > 0">ลองใหม่ ({{ retryInSeconds }} วิ)</template>
                <template v-else>ลองใหม่</template>
            </button>
        </div>

        <ul class="mt-1 space-y-0.5 text-xs opacity-90">
            <li>· บิลที่บันทึกไปก่อนหน้านี้อยู่ครบ ไม่หายไปไหน</li>
            <li>· ใบสั่งครัวที่ส่งไปแล้วยังพิมพ์ตามคิวเดิม ถ้าเซิร์ฟเวอร์กับเครื่องพิมพ์ยังคุยกันได้</li>
            <li>· พอกลับมาแล้ว กดรายการที่ค้างซ้ำได้เลย ระบบกันไม่ให้บันทึกซ้ำให้แล้ว</li>
        </ul>
    </div>
</template>
