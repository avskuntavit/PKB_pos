<script setup lang="ts">
/**
 * ตัวอย่างเมนูอย่างที่ลูกค้าเห็นจริง — ครบทั้ง 3 จุดที่เมนูหนึ่งรายการไปโผล่
 *
 *   1. การ์ดในรายการเมนูหน้า /order
 *   2. หน้าต่างเลือกตัวเลือก — กดเลือกได้จริง ราคาขยับจริง ปุ่มบังคับเลือกทำงานจริง
 *   3. รายการในตะกร้า — ตามตัวเลือกที่เพิ่งกดในข้อ 2
 *
 * ข้อ 2 ใช้ ProductSheet ตัวเดียวกับหน้าลูกค้าในโหมด embedded
 * และข้อ 3 ใช้ CartLineBody ตัวเดียวกับตะกร้าจริง
 * จึงไม่มีทางที่ตัวอย่างกับของจริงจะหลุดจากกัน เว้นแต่จะตั้งใจแก้ทั้งคู่
 *
 * ตัวเลือกมาจากเซิร์ฟเวอร์ (ของที่ "บันทึกไว้แล้ว") ส่วนชื่อ/ราคา/รูป
 * เอาค่าที่กำลังพิมพ์อยู่ในฟอร์มมาทับ ผู้ใช้จึงเห็นผลก่อนกดบันทึก
 */
import { computed, ref, watch } from 'vue'
import { CircleAlert, Layers, LoaderCircle, RotateCcw, ShoppingBag, SquareMenu } from 'lucide-vue-next'
import ProductSheet from '@/components/guest/ProductSheet.vue'
import CartLineBody from '@/components/storefront/CartLineBody.vue'
import MenuRowPreview from '@/components/backoffice/MenuRowPreview.vue'
import type { DietTag, Product } from '@/types'

const props = defineProps<{
    /** null = กำลังเพิ่มเมนูใหม่ ยังไม่มี id ให้ดึงตัวเลือก */
    productId: number | null
    name: string
    description: string | null
    price: number | string
    staffPrice: number | string | null
    image: string | null
    promoLabel: string | null
    isActive: boolean
    /**
     * ป้ายที่กำลังติ๊กอยู่ในฟอร์ม ยังไม่ได้บันทึก
     *
     * ต้องรับเข้ามาทับ ไม่ใช่ปล่อยให้ใช้ของที่เซิร์ฟเวอร์ส่งมา ไม่งั้นตัวอย่าง
     * จะโชว์ป้ายชุดเก่าระหว่างที่คนกำลังแก้ ซึ่งขัดกับเหตุผลที่มีตัวอย่างตั้งแต่แรก
     */
    dietTags?: DietTag[]
}>()

const loading = ref(false)
const failed = ref(false)

/** ตัวเลือกที่บันทึกไว้แล้วของเมนูนี้ — รูปแบบเดียวกับที่หน้า /order ได้รับ */
const saved = ref<Product | null>(null)

/** บังคับ ProductSheet ให้เริ่มใหม่ เมื่อข้อมูลเปลี่ยนจนตัวเลือกเดิมใช้ไม่ได้ */
const sheetKey = ref(0)

const picked = ref<{ names: string[]; note: string | null; qty: number; unitPrice: number } | null>(null)

async function load(id: number | null) {
    saved.value = null
    picked.value = null
    failed.value = false

    if (id === null) return

    loading.value = true

    try {
        const res = await fetch(`/backoffice/products/${id}/preview`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })

        if (!res.ok) throw new Error(String(res.status))

        saved.value = (await res.json()).product as Product
    } catch {
        failed.value = true
    } finally {
        loading.value = false
        sheetKey.value++
    }
}

watch(() => props.productId, load, { immediate: true })

const priceNumber = computed(() => Number(props.price) || 0)

/**
 * เมนูที่ป้อนให้ ProductSheet — ตัวเลือกจากเซิร์ฟเวอร์ + ค่าที่กำลังพิมพ์ทับลงไป
 *
 * ราคาต้องใช้ค่าในฟอร์ม ไม่ใช่ค่าที่บันทึกไว้ ไม่งั้นแก้ราคาแล้วตัวอย่างยังโชว์ราคาเก่า
 * ซึ่งเป็นสิ่งเดียวที่คนเปิดหน้านี้อยากเช็คมากที่สุด
 */
const previewProduct = computed<Product | null>(() => {
    if (!saved.value) return null

    return {
        ...saved.value,
        name: props.name || 'ยังไม่ได้ตั้งชื่อเมนู',
        description: props.description,
        price: priceNumber.value,
        image_path: props.image,
        promo_label: props.promoLabel,
        diet_tags: props.dietTags ?? [],
    } as Product
})

const groupCount = computed(() => saved.value?.modifier_groups.length ?? 0)

/** ราคาที่ไม่ได้เลือกอะไรเพิ่ม ใช้โชว์ในตะกร้าก่อนที่ผู้ใช้จะลองกด */
const fallbackLine = computed(() => ({
    names: [] as string[],
    note: null as string | null,
    qty: 1,
    unitPrice: priceNumber.value,
}))

const cartLine = computed(() => picked.value ?? fallbackLine.value)

// แก้ราคาแล้วแถวในตะกร้าที่ค้างอยู่จะยังเป็นราคาเก่า ล้างทิ้งให้คำนวณใหม่
watch(priceNumber, () => (picked.value = null))

function onAdd(
    _product: Product,
    qty: number,
    _ids: number[],
    modifierNames: string[],
    note: string | null,
    unitPrice: number,
) {
    picked.value = { names: modifierNames, note, qty, unitPrice }
}

function resetSheet() {
    picked.value = null
    sheetKey.value++
}
</script>

<template>
    <div class="space-y-4">
        <!-- 1. การ์ดในรายการเมนู -->
        <section class="space-y-1.5">
            <p class="flex items-center gap-1.5 text-sm font-medium">
                <SquareMenu class="size-4 text-muted-foreground" />
                การ์ดในรายการเมนู
            </p>
            <MenuRowPreview
                :name="name"
                :description="description"
                :price="price"
                :staff-price="staffPrice"
                :image="image"
                :promo-label="promoLabel"
                :is-active="isActive"
            />
        </section>

        <!-- 2. หน้าต่างเลือกตัวเลือก -->
        <section class="space-y-1.5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-1.5 text-sm font-medium">
                    <Layers class="size-4 text-muted-foreground" />
                    หน้าต่างเลือกตัวเลือก
                    <span v-if="saved" class="font-normal text-muted-foreground">
                        · {{ groupCount }} เซ็ต
                    </span>
                </p>

                <button
                    v-if="previewProduct"
                    type="button"
                    class="flex items-center gap-1 text-xs text-[var(--series-1)] hover:underline"
                    @click="resetSheet"
                >
                    <RotateCcw class="size-3.5" />
                    เริ่มใหม่
                </button>
            </div>

            <div
                v-if="loading"
                class="flex items-center justify-center gap-2 rounded-xl border border-dashed p-8 text-sm text-muted-foreground"
            >
                <LoaderCircle class="size-4 animate-spin" />
                กำลังโหลดตัวเลือก…
            </div>

            <p
                v-else-if="productId === null"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                บันทึกเมนูก่อน แล้วค่อยผูกเซ็ตตัวเลือก — ตัวอย่างหน้าต่างสั่งจะขึ้นให้ดูตรงนี้
            </p>

            <p
                v-else-if="failed"
                class="flex items-center gap-2 rounded-xl border border-[var(--status-warning)]/40 bg-[var(--status-warning)]/10 p-3 text-sm"
            >
                <CircleAlert class="size-4 shrink-0 text-[var(--status-warning)]" />
                โหลดตัวเลือกไม่สำเร็จ ลองปิดแล้วเปิดหน้าต่างนี้ใหม่
            </p>

            <template v-else-if="previewProduct">
                <!--
                  ProductSheet ตัวเดียวกับหน้าลูกค้า ไม่ใช่ของจำลอง
                  key บังคับให้สร้างใหม่เมื่อกด "เริ่มใหม่" หรือโหลดเมนูอื่น
                  เพราะตัวเลือกที่ติ๊กไว้เป็น state ภายในของมันเอง
                -->
                <ProductSheet
                    :key="sheetKey"
                    :product="previewProduct"
                    embedded
                    @add="onAdd"
                />

                <p v-if="groupCount === 0" class="text-xs text-muted-foreground">
                    เมนูนี้ยังไม่ได้ผูกเซ็ตตัวเลือก ลูกค้าจะเห็นแค่จำนวนกับหมายเหตุ
                </p>
            </template>
        </section>

        <!-- 3. รายการในตะกร้า -->
        <section class="space-y-1.5">
            <p class="flex items-center gap-1.5 text-sm font-medium">
                <ShoppingBag class="size-4 text-muted-foreground" />
                รายการในตะกร้า
            </p>

            <div class="rounded-xl border bg-card px-4 py-3">
                <CartLineBody
                    :qty="cartLine.qty"
                    :name="name || 'ยังไม่ได้ตั้งชื่อเมนู'"
                    :modifier-names="cartLine.names"
                    :note="cartLine.note"
                    :unit-price="cartLine.unitPrice"
                />
            </div>

            <p class="text-xs text-muted-foreground">
                {{
                    picked
                        ? 'ตามตัวเลือกที่เพิ่งกดด้านบน'
                        : 'กด "เพิ่มลงตะกร้า" ด้านบนเพื่อดูว่าตัวเลือกที่เลือกไปขึ้นในตะกร้าอย่างไร'
                }}
            </p>
        </section>
    </div>
</template>
