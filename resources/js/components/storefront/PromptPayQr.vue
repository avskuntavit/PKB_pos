<script setup lang="ts">
/**
 * QR พร้อมเพย์ — วาดจาก payload ที่เซิร์ฟเวอร์สร้างตามมาตรฐาน EMVCo
 *
 * ระบบไม่รู้ว่าเงินเข้าแล้วหรือยัง (ไม่ได้ต่อ API ธนาคาร)
 * พนักงานเป็นคนยืนยันจากสลิปหรือแอปธนาคารตอนลูกค้ามารับของ
 */
import { onMounted, ref, watch } from 'vue'
import QRCode from 'qrcode'
import { money } from '@/lib/format'

const props = defineProps<{
    payload: string
    amount: number
    merchantName: string
}>()

const dataUrl = ref('')
const failed = ref(false)

async function render() {
    try {
        dataUrl.value = await QRCode.toDataURL(props.payload, {
            width: 480,
            margin: 1,
            errorCorrectionLevel: 'M',
            color: { dark: '#000000', light: '#ffffff' },
        })
        failed.value = false
    } catch {
        failed.value = true
    }
}

onMounted(render)
watch(() => props.payload, render)
</script>

<template>
    <div class="flex flex-col items-center gap-2 rounded-xl border bg-white p-5 text-center text-black">
        <p class="text-sm font-medium">สแกนจ่ายด้วยแอปธนาคาร</p>
        <p class="text-xs text-neutral-500">{{ merchantName }}</p>

        <img v-if="dataUrl" :src="dataUrl" alt="QR พร้อมเพย์สำหรับชำระเงิน" class="my-1 size-52" />
        <div
            v-else-if="failed"
            class="my-1 grid size-52 place-items-center border border-dashed text-xs text-neutral-500"
        >
            สร้าง QR ไม่สำเร็จ กรุณาจ่ายที่เคาน์เตอร์
        </div>
        <div v-else class="my-1 size-52 animate-pulse bg-neutral-100" />

        <p class="tabular text-2xl font-bold">{{ money(amount) }} ฿</p>
        <p class="text-xs text-neutral-500">
            โอนแล้วเก็บสลิปไว้แสดงพนักงานตอนมารับของด้วยนะครับ
        </p>
    </div>
</template>
