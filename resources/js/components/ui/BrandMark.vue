<script setup lang="ts">
/**
 * โลโก้และชื่อระบบ
 *
 * ── ทำไมต้องมีคอมโพเนนต์นี้ ────────────────────────────────
 * เดิมชื่อระบบถูกพิมพ์ไว้สามที่ (หัวจอ POS · เมนูหลังบ้าน · หน้าล็อกอิน)
 * เปลี่ยนชื่อหรือเปลี่ยนโลโก้ทีต้องไล่แก้ทุกที่ และลืมที่ใดที่หนึ่งเสมอ
 * ตอนนี้ทั้งชื่อและรูปมาจาก config ฝั่งเซิร์ฟเวอร์ ส่งมาทาง Inertia ที่เดียว
 *
 * ── ทำไมต้องมีตัวอักษรสำรอง ────────────────────────────────
 * ถ้าโหลดรูปไม่ได้ — ย้ายเครื่องแล้วลืมรัน `php artisan storage:link` เป็นต้น —
 * เบราว์เซอร์จะโชว์ไอคอนรูปแตกค้างอยู่บนหัวจอทุกหน้า
 * ตกลงว่าถ้าโหลดไม่ผ่าน ให้ถอยไปใช้ตัวอักษรแรกของชื่อระบบแทน ยังดูเป็นระบบอยู่
 */
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import type { PageProps } from '@/types'

const props = withDefaults(
    defineProps<{
        /** ความสูงของโลโก้ เป็น px */
        height?: number
        /** แสดงชื่อระบบเป็นตัวหนังสือข้างโลโก้ด้วยไหม */
        showName?: boolean
    }>(),
    { height: 28, showName: false },
)

const page = usePage<PageProps>()
const brand = computed(() => page.props.brand)
const broken = ref(false)

const initial = computed(() => (brand.value?.name ?? 'P').trim().charAt(0).toUpperCase())
const fallbackStyle = computed(() => ({
    height: `${props.height}px`,
    width: `${props.height}px`,
    fontSize: `${Math.round(props.height * 0.5)}px`,
}))
</script>

<template>
    <span class="flex items-center gap-2">
        <img
            v-if="brand?.mark && ! broken"
            :src="brand.mark"
            :alt="brand.name"
            :style="{ height: `${height}px` }"
            class="w-auto shrink-0"
            @error="broken = true"
        />
        <span
            v-else
            class="grid shrink-0 place-items-center rounded-md bg-[var(--series-1)] font-semibold text-white"
            :style="fallbackStyle"
        >
            {{ initial }}
        </span>

        <span v-if="showName" class="font-semibold">{{ brand?.name }}</span>
    </span>
</template>
