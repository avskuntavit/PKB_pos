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
    /**
     * ยอดถูกฝังอยู่ใน QR แล้วหรือยัง
     *
     * บิลยอดน้อยจะได้ QR แบบไม่ระบุยอด เพราะผู้ให้บริการบางเจ้าไม่ออก QR
     * ที่ระบุยอดต่ำ ๆ ให้ ตอนนั้นลูกค้าต้องพิมพ์ยอดเองในแอปธนาคาร
     * ถ้าไม่บอก ลูกค้าจะเจอช่องยอดว่างแล้วพิมพ์มั่ว ร้านได้เงินไม่ครบ
     */
    amountInQr?: boolean
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

        <p
            v-if="amountInQr === false"
            class="rounded-md bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-900"
        >
            QR นี้ไม่ได้ระบุยอด — กรุณา<u>พิมพ์ยอด {{ money(amount) }} บาทเอง</u>ในแอปธนาคาร
        </p>

        <p class="text-xs font-medium text-neutral-700">
            โอนแล้วเก็บสลิปไว้ด้วยนะครับ
        </p>
        <p class="text-xs text-neutral-500">
            ระบบไม่ได้เชื่อมกับธนาคาร ร้านจึงไม่เห็นยอดโอนเอง
            พนักงานจะตรวจจากสลิปที่คุณแสดงตอนมารับของ
        </p>
    </div>
</template>
