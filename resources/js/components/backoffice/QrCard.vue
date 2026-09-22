<script setup lang="ts">
/**
 * แผ่น QR 1 ใบ — ใช้ได้ทั้ง QR ประจำโต๊ะ และ QR ของร้านที่วางไว้หน้าเคาน์เตอร์
 * สร้าง QR ในเบราว์เซอร์ด้วย qrcode ไม่ต้องลง package ฝั่ง PHP และไม่ต้องยิงไป API ภายนอก
 */
import { onMounted, ref, watch } from 'vue'
import QRCode from 'qrcode'

const props = withDefaults(
    defineProps<{
        /** บรรทัดใหญ่กลางแผ่น เช่น "โต๊ะ 5" หรือ "สั่งกลับบ้าน" */
        heading: string
        sub?: string | null
        branchName: string
        url: string
        caption?: string
        hint?: string
    }>(),
    {
        caption: 'สแกนเพื่อสั่งอาหาร',
        hint: 'ไม่ต้องติดตั้งแอป',
    },
)

const dataUrl = ref('')
const error = ref(false)

async function render() {
    try {
        dataUrl.value = await QRCode.toDataURL(props.url, {
            width: 420,
            margin: 1,
            errorCorrectionLevel: 'M', // ทนรอยเปื้อน/ขีดข่วนได้ ~15% พอสำหรับแผ่นตั้งโต๊ะ
            color: { dark: '#000000', light: '#ffffff' },
        })
        error.value = false
    } catch {
        error.value = true
    }
}

onMounted(render)
watch(() => props.url, render)
</script>

<template>
    <div class="qr-card flex break-inside-avoid flex-col items-center gap-2 rounded-xl border bg-white p-5 text-center text-black">
        <p class="text-sm font-medium">{{ branchName }}</p>

        <p class="text-3xl font-bold leading-none">{{ heading }}</p>
        <p v-if="sub" class="text-xs text-neutral-500">{{ sub }}</p>

        <img v-if="dataUrl" :src="dataUrl" :alt="`QR ${heading}`" class="my-1 size-44" />
        <div v-else-if="error" class="my-1 grid size-44 place-items-center border border-dashed text-xs text-neutral-500">
            สร้าง QR ไม่สำเร็จ
        </div>
        <div v-else class="my-1 size-44 animate-pulse bg-neutral-100" />

        <p class="text-base font-semibold">{{ caption }}</p>
        <p class="text-xs text-neutral-500">{{ hint }}</p>
    </div>
</template>

<style scoped>
@media print {
    .qr-card {
        border-color: #d4d4d4;
        page-break-inside: avoid;
    }
}
</style>
