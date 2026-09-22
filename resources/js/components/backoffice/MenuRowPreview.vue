<script setup lang="ts">
/**
 * ตัวอย่างเมนูหนึ่งรายการอย่างที่ลูกค้าเห็นบน /order
 *
 * ใช้คลาสชุดเดียวกับหน้าจริง สิ่งที่เห็นตรงนี้จึงเท่ากับหน้าลูกค้า
 * ทั้งการตัดบรรทัดชื่อยาว ๆ และการครอบตัดรูปที่อัตราส่วนไม่ตรง
 */
import { computed } from 'vue'
import { Plus } from 'lucide-vue-next'
import { money } from '@/lib/format'

const props = defineProps<{
    name: string
    description: string | null
    price: number | string
    staffPrice: number | string | null
    image: string | null
    promoLabel: string | null
    isActive: boolean
}>()

const priceNumber = computed(() => Number(props.price) || 0)

const staffNumber = computed(() => {
    if (props.staffPrice === null || props.staffPrice === '') return null
    const n = Number(props.staffPrice)
    return Number.isFinite(n) && n > 0 && n < priceNumber.value ? n : null
})

const initial = computed(() => props.name.trim().charAt(0) || '?')
</script>

<template>
    <div class="overflow-hidden rounded-xl border">
        <div class="flex items-center gap-3 bg-card px-4 py-3" :class="!isActive && 'opacity-50'">
            <img
                v-if="image"
                :src="image"
                :alt="name"
                class="size-28 shrink-0 rounded-xl object-cover"
            />
            <div
                v-else
                class="grid size-28 shrink-0 place-items-center rounded-xl bg-muted text-4xl font-semibold text-muted-foreground"
                aria-hidden="true"
            >
                {{ initial }}
            </div>

            <span class="min-w-0 flex-1 self-start py-1">
                <span v-if="promoLabel" class="mb-0.5 block text-xs font-medium text-[var(--status-good)]">
                    {{ promoLabel }}
                </span>
                <span class="block text-base font-medium">{{ name || 'ยังไม่ได้ตั้งชื่อเมนู' }}</span>
                <span v-if="description" class="mt-0.5 line-clamp-2 block text-xs text-muted-foreground">
                    {{ description }}
                </span>
                <span class="tabular mt-1.5 block text-lg font-semibold">
                    {{ money(staffNumber ?? priceNumber) }}
                    <template v-if="staffNumber !== null">
                        <span class="ms-1 text-sm font-normal text-muted-foreground line-through">
                            {{ money(priceNumber) }}
                        </span>
                        <span class="ms-1 text-xs font-normal text-[var(--series-1)]">ราคาพนักงาน</span>
                    </template>
                </span>
            </span>

            <span
                class="grid size-11 shrink-0 place-items-center self-center rounded-full bg-[var(--series-1)] text-white"
                aria-hidden="true"
            >
                <Plus class="size-5" />
            </span>
        </div>

        <p v-if="!isActive" class="bg-muted px-4 py-1.5 text-xs text-muted-foreground">
            ปิดขายอยู่ — เมนูนี้จะไม่แสดงบนหน้าลูกค้าเลย
        </p>
    </div>
</template>
