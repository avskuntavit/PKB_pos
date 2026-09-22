<script setup lang="ts">
/**
 * แถวป้ายบนเมนู — ขายดี / เผ็ด / เจ / ต้องระวัง
 *
 * ── ทำไมจำกัดจำนวนป้าย ─────────────────────────────────────
 * เมนูหนึ่งจานติดได้หลายป้าย แต่ถ้าโชว์ครบทุกอันบนแถวเมนูในมือถือ
 * ชื่อเมนูจะถูกเบียดจนอ่านไม่ออก ซึ่งแย่กว่าการไม่เห็นป้ายบางอัน
 * แถวเมนูจึงตัดที่ 3 ป้าย ส่วนหน้าต่างสั่งโชว์ครบเพราะมีที่พอ
 *
 * ── ทำไมป้ายขายดีมาก่อนเสมอ ───────────────────────────────
 * เป็นป้ายเดียวที่ช่วยคนที่ยัง "เลือกไม่ถูก" ส่วนป้ายอื่นช่วยคนที่
 * รู้อยู่แล้วว่าตัวเองกินอะไรไม่ได้ ซึ่งเขาจะกวาดหาเองอยู่แล้ว
 */
import { computed } from 'vue'
import { Flame } from 'lucide-vue-next'
import type { DietTag } from '@/types'

const props = withDefaults(
    defineProps<{
        tags?: DietTag[]
        bestSeller?: boolean
        /** โชว์ได้สูงสุดกี่ป้าย — null = ไม่จำกัด ใช้ในหน้าต่างสั่ง */
        limit?: number | null
        size?: 'sm' | 'md'
    }>(),
    { tags: () => [], bestSeller: false, limit: 3, size: 'sm' },
)

/** สีแยกตามกลุ่ม ไม่ใช่แยกตามป้าย เพื่อให้ "ของที่ต้องระวัง" สะดุดตาเป็นชุดเดียวกัน */
const KIND_CLASS: Record<string, string> = {
    heat: 'bg-[var(--status-critical)]/12 text-[var(--status-critical)]',
    diet: 'bg-[var(--status-good)]/12 text-[var(--status-good)]',
    allergen: 'bg-[var(--status-warning)]/15 text-[var(--status-warning)]',
}

const shown = computed(() =>
    props.limit === null ? props.tags : props.tags.slice(0, Math.max(0, props.limit)),
)

const hidden = computed(() => Math.max(0, props.tags.length - shown.value.length))

const pad = computed(() => (props.size === 'md' ? 'px-2 py-0.5 text-xs' : 'px-1.5 py-0.5 text-[10px]'))
</script>

<template>
    <div v-if="bestSeller || tags.length" class="flex flex-wrap items-center gap-1">
        <span
            v-if="bestSeller"
            class="inline-flex items-center gap-1 rounded-full bg-[var(--series-1)]/12 font-semibold text-[var(--series-1)]"
            :class="pad"
        >
            <Flame class="size-3 shrink-0" />
            ขายดี
        </span>

        <span
            v-for="tag in shown"
            :key="tag.value"
            class="inline-block rounded-full"
            :class="[pad, KIND_CLASS[tag.kind] ?? 'bg-muted text-muted-foreground']"
        >
            {{ tag.label }}
        </span>

        <span v-if="hidden > 0" class="text-[10px] text-muted-foreground">+{{ hidden }}</span>
    </div>
</template>
