<script setup lang="ts">
/**
 * เลือกเมนูและ/หรือหมวดหมู่
 *
 * เลือกหมวดกับเลือกรายเมนูต่างกันตรงที่หมวดหมายถึง "ทุกเมนูในหมวดนี้
 * รวมเมนูที่ออกใหม่ทีหลัง" ร้านจึงไม่ต้องกลับมาแก้โปรทุกครั้งที่เพิ่มเมนู
 * ข้อความใต้หัวข้อบอกเรื่องนี้ไว้ เพราะเป็นจุดที่คนตั้งโปรพลาดบ่อยที่สุด
 */
import { computed, ref } from 'vue'
import { Check, Search, X } from 'lucide-vue-next'
import { money } from '@/lib/format'

interface ProductRow {
    id: number
    name: string
    price: number | string
    category_id: number | null
}

const props = withDefaults(
    defineProps<{
        products: ProductRow[]
        categories: { id: number; name: string }[]
        /** ข้อความเมื่อยังไม่เลือกอะไรเลย — เช่น "ไม่เลือก = ทั้งร้านเข้าโปร" */
        emptyHint?: string | null
    }>(),
    { emptyHint: null },
)

// ห้ามตั้งชื่อ model ซ้ำกับ prop ด้านบน — Vue จะรวมสองตัวเข้าด้วยกัน
// กลายเป็น ProductRow[] & number[] ซึ่งไม่มีค่าไหนเป็นได้ทั้งสองอย่าง
const pickedProducts = defineModel<number[]>('selectedProducts', { default: () => [] })
const pickedCategories = defineModel<number[]>('selectedCategories', { default: () => [] })

const search = ref('')

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase()

    if (!term) return props.products

    return props.products.filter((p) => p.name.toLowerCase().includes(term))
})

const total = computed(() => pickedProducts.value.length + pickedCategories.value.length)

function toggle(list: number[], id: number): number[] {
    return list.includes(id) ? list.filter((x) => x !== id) : [...list, id]
}

function clearAll() {
    pickedProducts.value = []
    pickedCategories.value = []
}

/** เมนูที่ถูกครอบด้วยหมวดที่เลือกไว้แล้ว — ติ๊กซ้ำไม่ได้ช่วยอะไร บอกให้รู้ดีกว่า */
function coveredByCategory(product: ProductRow): boolean {
    return product.category_id !== null && pickedCategories.value.includes(product.category_id)
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center justify-between gap-2">
            <p class="text-sm text-muted-foreground">
                <template v-if="total">เลือกไว้ {{ total }} รายการ</template>
                <template v-else>{{ emptyHint ?? 'ยังไม่ได้เลือก' }}</template>
            </p>

            <button
                v-if="total"
                type="button"
                class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                @click="clearAll"
            >
                <X class="size-3.5" />
                ล้างทั้งหมด
            </button>
        </div>

        <!-- หมวดหมู่ -->
        <div v-if="categories.length" class="flex flex-wrap gap-1.5">
            <button
                v-for="c in categories"
                :key="c.id"
                type="button"
                class="flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs transition-colors"
                :class="
                    pickedCategories.includes(c.id)
                        ? 'border-[var(--series-1)] bg-[var(--series-1)]/10 font-medium text-[var(--series-1)]'
                        : 'border-border hover:bg-accent'
                "
                @click="pickedCategories = toggle(pickedCategories, c.id)"
            >
                <Check v-if="pickedCategories.includes(c.id)" class="size-3" />
                ทั้งหมวด {{ c.name }}
            </button>
        </div>

        <!-- ค้นหาเมนู -->
        <div class="relative">
            <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <input
                v-model="search"
                type="search"
                placeholder="ค้นหาเมนู"
                class="h-9 w-full rounded-md border bg-card pl-8 pr-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
        </div>

        <!-- รายเมนู -->
        <div class="max-h-56 overflow-y-auto overscroll-contain rounded-md border">
            <label
                v-for="p in filtered"
                :key="p.id"
                class="flex cursor-pointer items-center gap-2 border-b px-3 py-2 text-sm last:border-b-0 hover:bg-accent"
            >
                <input
                    type="checkbox"
                    class="size-4 shrink-0"
                    :checked="pickedProducts.includes(p.id)"
                    @change="pickedProducts = toggle(pickedProducts, p.id)"
                />
                <span class="min-w-0 flex-1 truncate">{{ p.name }}</span>
                <span v-if="coveredByCategory(p)" class="shrink-0 text-xs text-[var(--series-1)]">
                    อยู่ในหมวดที่เลือกแล้ว
                </span>
                <span class="tabular shrink-0 text-xs text-muted-foreground">{{ money(p.price) }}</span>
            </label>

            <p v-if="!filtered.length" class="px-3 py-6 text-center text-sm text-muted-foreground">
                ไม่พบเมนูที่ค้นหา
            </p>
        </div>
    </div>
</template>
