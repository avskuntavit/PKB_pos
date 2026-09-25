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
import { CloudOff, LogIn, RefreshCw, UploadCloud } from 'lucide-vue-next'
import { useConnection } from '@/composables/useConnection'
import { useOfflineQueue } from '@/composables/useOfflineQueue'
import { money, time } from '@/lib/format'

const { isOffline, sessionExpired, lastOkAt, checking, retryInSeconds, checkNow } = useConnection()

/*
| คิวใบเดียวกับที่หน้าคีย์บิลใช้ (composable ตัวนี้เป็น singleton)
| ถ้าต่างคนต่างถือ ตัวเลข "ค้างกี่รายการ" สองที่จะไม่ตรงกัน
*/
const queue = useOfflineQueue()

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
                <template v-if="queue.count.value > 0">
                    เก็บไว้ในเครื่องนี้แล้ว {{ queue.count.value }} รายการ รอส่งขึ้นระบบ
                </template>
                <template v-else>เพิ่มรายการกับส่งครัวยังกดได้ ระบบเก็บไว้ให้ก่อน</template>
                <template v-if="queue.heldCash.value > 0">
                    · <b>เก็บเงินสดไว้แล้ว {{ money(queue.heldCash.value) }} ฿</b>
                </template>
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
            <li>· เพิ่มรายการกับส่งครัวได้ตามปกติ ของจะขึ้นระบบเองทันทีที่กลับมาต่อได้</li>
            <li>· รับ<b>เงินสด</b>ได้ เฉพาะบิลที่ไม่มีรายการค้างอยู่ในเครื่อง — ยังไม่ออกใบเสร็จจนกว่าระบบจะกลับมา</li>
            <li>· <b>เปิดบิลใหม่ · แก้รายการที่ขึ้นระบบไปแล้ว · รับโอน/บัตร/ส่วนลด</b> ยังทำตอนนี้ไม่ได้</li>
            <li>· <b>ห้ามรีเฟรชหรือปิดแท็บ</b> ระหว่างนี้ — ของที่คีย์ค้างไว้ยังอยู่ แต่หน้าจอจะเปิดใหม่ไม่ได้จนกว่าระบบจะกลับมา</li>
        </ul>
    </div>

    <!-- ══ ต่อได้แล้ว แต่ยังมีของค้างจากตอนหลุด ══ -->
    <!--
        แถบนี้ต้องอยู่ต่อจนกว่าคิวจะว่าง ไม่ใช่หายไปพร้อมกับแถบสีแดง
        ช่วงที่อันตรายที่สุดคือ "เน็ตกลับมาแล้วแต่ของยังไม่ขึ้น" เพราะหน้าจอดูปกติทุกอย่าง
    -->
    <div
        v-else-if="queue.hasPending.value"
        class="shrink-0 bg-[var(--status-warning)] px-4 py-2 text-sm text-white no-print"
        role="status"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <UploadCloud class="size-4 shrink-0" />

            <span v-if="queue.waiting.value.length" class="font-semibold">
                มีของจากตอนระบบล่มรอส่ง {{ queue.waiting.value.length }} รายการ
                <template v-if="queue.heldCash.value > 0">
                    (รวมเงินสด {{ money(queue.heldCash.value) }} ฿)
                </template>
            </span>
            <span v-else class="font-semibold">มีรายการที่ส่งไม่สำเร็จค้างอยู่</span>

            <!--
                ใบที่ล้มไปแล้วกดส่งซ้ำไม่ได้ — คีย์ uuid นั้นถูกจองไปแล้วตั้งแต่ครั้งแรก
                จึงต้องบอกตรง ๆ ว่าให้ "คีย์ใหม่" ไม่ใช่ปล่อยให้พนักงานกดปุ่มส่งซ้ำไปเรื่อย ๆ
            -->
            <span v-if="queue.failed.value.length" class="opacity-90">
                · ส่งไม่สำเร็จ {{ queue.failed.value.length }} รายการ — เปิดบิลนั้นดูเหตุผล แล้ว<b>คีย์ใหม่</b>
            </span>

            <button
                v-if="queue.waiting.value.length"
                type="button"
                class="ml-auto flex shrink-0 items-center gap-1.5 rounded-md bg-white/20 px-3 py-1 font-medium transition-colors hover:bg-white/30 disabled:opacity-60"
                :disabled="queue.syncing.value"
                @click="queue.sync()"
            >
                <RefreshCw class="size-3.5" :class="queue.syncing.value && 'animate-spin'" />
                {{ queue.syncing.value ? 'กำลังส่ง…' : 'ส่งขึ้นระบบเดี๋ยวนี้' }}
            </button>
        </div>

        <p v-if="queue.lastSyncMessage.value" class="mt-1 text-xs opacity-90">
            {{ queue.lastSyncMessage.value }}
        </p>
    </div>
</template>
