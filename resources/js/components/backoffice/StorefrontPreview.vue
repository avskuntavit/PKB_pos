<script setup lang="ts">
/**
 * ตัวอย่างหน้าร้านแบบสด — ขยับตามที่พิมพ์ทันที ไม่ต้องกดบันทึกก่อน
 *
 * จำลองเฉพาะส่วนหัวของ /order ที่ได้รับผลจากหน้าตั้งค่าสาขา
 * ใช้คลาสชุดเดียวกับหน้าจริง ของที่เห็นตรงนี้จึงตรงกับที่ลูกค้าเห็น
 */
import { computed } from 'vue'
import { Clock, MapPin, Phone } from 'lucide-vue-next'

const props = defineProps<{
    name: string
    intro: string | null
    phone: string | null
    address: string | null
    openTime: string
    closeTime: string
    prepMinutes: number | string
    /** ปิดรับออเดอร์ล่วงหน้าไว้ไหม */
    acceptingOrders: boolean
    cover: string | null
    logo: string | null
}>()

/** เทียบเวลาปัจจุบันกับเวลาเปิด-ปิด รองรับร้านที่ปิดข้ามเที่ยงคืน */
const isOpenNow = computed(() => {
    const now = new Date()
    const minutes = now.getHours() * 60 + now.getMinutes()

    const toMinutes = (t: string) => {
        const [h, m] = t.split(':').map(Number)
        return (h || 0) * 60 + (m || 0)
    }

    const open = toMinutes(props.openTime)
    const close = toMinutes(props.closeTime)

    return close > open ? minutes >= open && minutes < close : minutes >= open || minutes < close
})
</script>

<template>
    <div class="overflow-hidden rounded-xl border bg-muted/40">
        <!-- รูปปก — สัดส่วนเดียวกับหน้าจริง -->
        <div
            class="aspect-video w-full bg-cover bg-center"
            :style="
                cover
                    ? { backgroundImage: `url(${cover})` }
                    : { background: 'linear-gradient(135deg, var(--series-1), var(--series-7))' }
            "
        />

        <div class="bg-card px-3 py-2.5">
            <div class="flex items-start gap-2">
                <img
                    v-if="logo"
                    :src="logo"
                    alt="โลโก้ร้าน"
                    class="-mt-7 size-12 shrink-0 rounded-lg border-2 border-card object-cover shadow-sm"
                />

                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="min-w-0 truncate text-base font-bold">{{ name || 'ยังไม่ได้ตั้งชื่อร้าน' }}</h3>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="
                                isOpenNow
                                    ? 'bg-[var(--status-good)]/15 text-[var(--status-good)]'
                                    : 'bg-[var(--status-critical)]/15 text-[var(--status-critical)]'
                            "
                        >
                            {{ isOpenNow ? 'เปิดอยู่' : 'ปิดแล้ว' }}
                        </span>
                    </div>

                    <p v-if="intro" class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{{ intro }}</p>
                </div>
            </div>

            <ul class="mt-2 space-y-1 text-[11px] text-muted-foreground">
                <li class="flex items-center gap-1.5">
                    <Clock class="size-3 shrink-0" />
                    เปิด {{ openTime }} – {{ closeTime }} · ครัวใช้เวลาราว {{ prepMinutes }} นาที
                </li>
                <li v-if="address" class="flex items-start gap-1.5">
                    <MapPin class="mt-px size-3 shrink-0" />
                    <span class="line-clamp-1">{{ address }}</span>
                </li>
                <li v-if="phone" class="flex items-center gap-1.5">
                    <Phone class="size-3 shrink-0" />
                    {{ phone }}
                </li>
            </ul>

            <p
                v-if="!acceptingOrders"
                class="mt-2 rounded-md border border-[var(--status-warning)]/30 bg-[var(--status-warning)]/10 px-2 py-1.5 text-[11px] text-[var(--status-warning)]"
            >
                ตอนนี้ร้านปิดรับออเดอร์ล่วงหน้า ดูเมนูได้ แต่ยังสั่งไม่ได้ครับ
            </p>
        </div>
    </div>
</template>
