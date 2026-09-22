<script setup lang="ts">
/**
 * แผ่นเลือกตัวเลือก + จำนวน ก่อนใส่ตะกร้า (ฝั่งลูกค้า)
 *
 * เลื่อนขึ้นมาจากขอบล่างแบบ bottom sheet ใช้ร่วมกันทั้ง
 * หน้าเว็บสั่งอาหาร (/order) และหน้าลูกค้าสแกน QR ที่โต๊ะ (/t/{token})
 *
 * รูปเมนูอยู่บนสุดและเลื่อนหายไปพร้อมเนื้อหา พอเลื่อนพ้นรูปแล้ว
 * แถบหัวแบบย่อจะโผล่มาแทน ชื่อเมนูจึงไม่หายไปจากสายตา
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Check, CircleCheck, Minus, Plus, Share2, X } from 'lucide-vue-next'
import DietBadges from '@/components/storefront/DietBadges.vue'
import { money, number } from '@/lib/format'
import type { ModifierGroup, Product } from '@/types'

const props = withDefaults(
    defineProps<{
        product: Product
        /**
         * โหมดฝังในหน้าอื่น — ใช้กับแผ่นตัวอย่างในหลังบ้าน
         *
         * ปิดการ teleport ไป body, ไม่มีฉากหลังดำ, ไม่ล็อกการเลื่อนของหน้าแม่
         * ที่เหลือคือมาร์กอัปและตรรกะชุดเดียวกับที่ลูกค้าเห็นทุกบรรทัด
         * จงใจไม่ทำคอมโพเนนต์ตัวอย่างแยก เพราะมันจะเพี้ยนจากของจริงภายในสัปดาห์เดียว
         */
        embedded?: boolean
    }>(),
    { embedded: false },
)

const emit = defineEmits<{
    close: []
    add: [
        product: Product,
        qty: number,
        modifierIds: number[],
        modifierNames: string[],
        note: string | null,
        unitPrice: number,
    ]
}>()

/* ---------- เปิด/ปิดพร้อมอนิเมชัน ---------- */

const open = ref(false)
const scrolled = ref(false)

/** ปิดแล้วรอให้อนิเมชันเลื่อนลงจบก่อนค่อยบอกหน้าแม่ให้ถอดคอมโพเนนต์ */
function dismiss() {
    open.value = false
}

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') dismiss()
}

onMounted(() => {
    open.value = true

    if (props.embedded) return

    // ล็อกไม่ให้หน้าหลังเลื่อนตามนิ้ว ไม่งั้นปิดแผ่นแล้วหน้าเมนูเด้งไปที่อื่น
    document.documentElement.style.overflow = 'hidden'
    window.addEventListener('keydown', onEscape)
})

onBeforeUnmount(() => {
    if (props.embedded) return

    document.documentElement.style.overflow = ''
    window.removeEventListener('keydown', onEscape)
})

function onScroll(e: Event) {
    scrolled.value = (e.target as HTMLElement).scrollTop > 150
}

/* ---------- ตัวเลือก ---------- */

const qty = ref(1)
const note = ref('')

/** ตัวเลือกที่ติ๊ก default ไว้ในหลังบ้าน ให้เลือกไว้ให้เลยตั้งแต่เปิด */
const selected = ref<Record<number, number[]>>(
    Object.fromEntries(
        props.product.modifier_groups.map((g) => [
            g.id,
            g.modifiers.filter((m) => m.is_default).slice(0, g.max_select).map((m) => m.id),
        ]),
    ),
)

function isOn(groupId: number, modifierId: number): boolean {
    return (selected.value[groupId] ?? []).includes(modifierId)
}

function toggle(group: ModifierGroup, modifierId: number) {
    const current = selected.value[group.id] ?? []

    if (current.includes(modifierId)) {
        // กลุ่มที่บังคับเลือกและเลือกได้อันเดียว ห้ามปลดจนว่าง ไม่งั้นสั่งไม่ได้
        if (group.max_select === 1 && minFor(group) > 0) return
        selected.value[group.id] = current.filter((id) => id !== modifierId)
        return
    }

    selected.value[group.id] =
        group.max_select === 1
            ? [modifierId]
            : current.length < group.max_select
              ? [...current, modifierId]
              : current
}

function minFor(group: ModifierGroup): number {
    return group.is_required ? Math.max(1, group.min_select) : group.min_select
}

/** ป้ายมุมขวา — บอกแค่ว่าต้องเลือกไหม */
function isRequired(group: ModifierGroup): boolean {
    return minFor(group) > 0
}

/** บรรทัดรองใต้ชื่อกลุ่ม — บอกจำนวนที่เลือกได้ */
function ruleLabel(group: ModifierGroup): string {
    const min = minFor(group)

    if (min > 0) {
        return group.max_select > min ? `เลือก ${min}–${group.max_select}` : `เลือก ${min}`
    }

    return group.max_select > 1 ? `เลือกได้ถึง ${group.max_select}` : 'เลือกได้ 1'
}

function isFull(group: ModifierGroup): boolean {
    return group.max_select > 1 && (selected.value[group.id]?.length ?? 0) >= group.max_select
}

const selectedIds = computed(() => Object.values(selected.value).flat())

const selectedModifiers = computed(() =>
    props.product.modifier_groups
        .flatMap((g) => g.modifiers)
        .filter((m) => selectedIds.value.includes(m.id)),
)

const unitPrice = computed(
    () => Number(props.product.price) + selectedModifiers.value.reduce((sum, m) => sum + Number(m.price_delta), 0),
)

const missingRequired = computed(() =>
    props.product.modifier_groups.some((g) => (selected.value[g.id]?.length ?? 0) < minFor(g)),
)

/** กลุ่มแรกที่ยังเลือกไม่ครบ ไว้บอกลูกค้าว่าติดตรงไหน */
const missingLabel = computed(() => {
    const group = props.product.modifier_groups.find(
        (g) => (selected.value[g.id]?.length ?? 0) < minFor(g),
    )
    return group ? `เลือก "${group.name}" ก่อน` : ''
})

/** ราคาเต็มบาทไม่ต้องโชว์ .00 ให้รก */
function price(value: number | string): string {
    const n = Number(value)
    return Number.isInteger(n) ? number(n) : money(n)
}

function confirm() {
    if (missingRequired.value) return

    emit(
        'add',
        props.product,
        qty.value,
        selectedIds.value,
        selectedModifiers.value.map((m) => m.name),
        note.value.trim() || null,
        unitPrice.value,
    )
}

/* ---------- รูปสำรองตอนร้านยังไม่ได้อัปโหลดรูป ---------- */

const palette = ['var(--series-1)', 'var(--series-2)', 'var(--series-3)', 'var(--series-4)', 'var(--series-5)']

const tone = computed(() => {
    let hash = 0
    for (let i = 0; i < props.product.name.length; i++) {
        hash = (hash * 31 + props.product.name.charCodeAt(i)) >>> 0
    }
    return palette[hash % palette.length]
})

const canShare = typeof navigator !== 'undefined' && 'share' in navigator

async function share() {
    try {
        await navigator.share({ title: props.product.name, url: window.location.href })
    } catch {
        // ผู้ใช้กดยกเลิกเอง ไม่ต้องทำอะไร
    }
}
</script>

<template>
    <Teleport to="body" :disabled="embedded">
        <!-- ฉากหลัง -->
        <Transition
            appear
            enter-active-class="transition-opacity duration-300 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-200 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="open && !embedded" class="fixed inset-0 z-40 bg-black/50" @click="dismiss" />
        </Transition>

        <!--
          แผ่นเลื่อนขึ้นจากด้านล่าง

          จอใหญ่ขึ้นก็ยังเลื่อนขึ้นจากล่างเหมือนเดิม แค่ลอยพ้นขอบจอและมนทุกมุม
          จงใจไม่ย้ายไปกลางจอ เพราะคลาสอนิเมชัน translate-y-full จะตีกับการจัดกึ่งกลางแนวตั้ง
          และบนแท็บเล็ตที่ถือด้วยมือ ปุ่มที่อยู่ล่างจอกดถนัดกว่าปุ่มกลางจออยู่แล้ว
        -->
        <Transition
            :appear="!embedded"
            :enter-active-class="embedded ? '' : 'transition-transform duration-300 ease-out'"
            :enter-from-class="embedded ? '' : 'translate-y-full'"
            :leave-active-class="embedded ? '' : 'transition-transform duration-200 ease-in'"
            :leave-to-class="embedded ? '' : 'translate-y-full'"
            @after-leave="emit('close')"
        >
            <div
                v-if="open"
                class="flex w-full flex-col overflow-hidden bg-background"
                :class="
                    embedded
                        ? 'relative max-h-[30rem] rounded-xl border'
                        : 'fixed inset-x-0 bottom-0 z-50 mx-auto max-h-[92dvh] max-w-2xl rounded-t-2xl shadow-2xl sm:bottom-4 sm:max-h-[86dvh] sm:rounded-2xl'
                "
                role="dialog"
                aria-modal="true"
                :aria-label="product.name"
            >
                <!-- แถบหัวแบบย่อ โผล่มาตอนเลื่อนพ้นรูปแล้ว -->
                <Transition
                    enter-active-class="transition-all duration-200 ease-out"
                    enter-from-class="-translate-y-full opacity-0"
                    leave-active-class="transition-all duration-150 ease-in"
                    leave-to-class="-translate-y-full opacity-0"
                >
                    <div
                        v-if="scrolled"
                        class="absolute inset-x-0 top-0 z-20 flex h-14 items-center gap-2 border-b bg-background/95 px-2 backdrop-blur"
                    >
                        <button
                            v-if="!embedded"
                            type="button"
                            class="grid size-10 shrink-0 place-items-center rounded-full transition-colors hover:bg-accent"
                            aria-label="ปิด"
                            @click="dismiss"
                        >
                            <X class="size-5" />
                        </button>

                        <h2 class="min-w-0 flex-1 truncate text-base font-bold">{{ product.name }}</h2>

                        <button
                            v-if="canShare && !embedded"
                            type="button"
                            class="grid size-10 shrink-0 place-items-center rounded-full transition-colors hover:bg-accent"
                            aria-label="แชร์เมนูนี้"
                            @click="share"
                        >
                            <Share2 class="size-5" />
                        </button>
                    </div>
                </Transition>

                <!-- เนื้อหาเลื่อนได้ -->
                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain" @scroll.passive="onScroll">
                    <!-- รูปเมนู -->
                    <div class="relative">
                        <img
                            v-if="product.image_path"
                            :src="product.image_path"
                            :alt="product.name"
                            class="h-56 w-full object-cover sm:h-64"
                        />
                        <div
                            v-else
                            class="grid h-40 w-full place-items-center text-6xl font-bold text-white/90"
                            :style="{ background: tone }"
                            aria-hidden="true"
                        >
                            {{ product.name.trim().charAt(0) }}
                        </div>

                        <!-- ปุ่มลอยบนรูป -->
                        <button
                            v-if="!embedded"
                            type="button"
                            class="absolute left-3 top-3 grid size-10 place-items-center rounded-full bg-background/90 shadow-md backdrop-blur transition-transform active:scale-95"
                            aria-label="ปิด"
                            @click="dismiss"
                        >
                            <X class="size-5" />
                        </button>

                        <button
                            v-if="canShare && !embedded"
                            type="button"
                            class="absolute right-3 top-3 grid size-10 place-items-center rounded-full bg-background/90 shadow-md backdrop-blur transition-transform active:scale-95"
                            aria-label="แชร์เมนูนี้"
                            @click="share"
                        >
                            <Share2 class="size-5" />
                        </button>
                    </div>

                    <!-- ชื่อ + ราคา -->
                    <div class="flex items-start justify-between gap-4 px-4 py-4">
                        <div class="min-w-0">
                            <h1 class="text-2xl font-bold leading-snug">{{ product.name }}</h1>
                            <!-- ในหน้าต่างสั่งโชว์ป้ายครบ ไม่ตัด เพราะเป็นจังหวะสุดท้ายก่อนตัดสินใจ -->
                            <DietBadges
                                :tags="product.diet_tags"
                                :best-seller="product.is_best_seller"
                                :limit="null"
                                size="md"
                                class="mt-2"
                            />
                            <p v-if="product.description" class="mt-1 text-base text-muted-foreground">
                                ({{ product.description }})
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="tabular text-2xl font-bold leading-none">{{ price(product.price) }}</p>
                            <p v-if="product.modifier_groups.length" class="mt-1 text-xs text-muted-foreground">
                                ราคาเริ่มต้น
                            </p>
                        </div>
                    </div>

                    <!-- กลุ่มตัวเลือก -->
                    <section v-for="group in product.modifier_groups" :key="group.id">
                        <div class="h-2 bg-muted/60" />

                        <div class="flex items-start justify-between gap-3 px-4 pb-2 pt-4">
                            <div class="min-w-0">
                                <h3 class="text-lg font-bold">{{ group.name }}</h3>
                                <p class="mt-0.5 text-sm text-muted-foreground">{{ ruleLabel(group) }}</p>
                            </div>

                            <span
                                class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs"
                                :class="
                                    isRequired(group)
                                        ? 'bg-[var(--status-good)]/15 font-medium text-[var(--status-good)]'
                                        : 'bg-muted text-muted-foreground'
                                "
                            >
                                <CircleCheck v-if="isRequired(group)" class="size-3.5" />
                                {{ isRequired(group) ? 'ต้องระบุ' : 'ไม่จำเป็นต้องระบุ' }}
                            </span>
                        </div>

                        <ul>
                            <li v-for="m in group.modifiers" :key="m.id">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-colors active:bg-accent/60 disabled:opacity-40"
                                    :disabled="!isOn(group.id, m.id) && isFull(group)"
                                    :aria-pressed="isOn(group.id, m.id)"
                                    @click="toggle(group, m.id)"
                                >
                                    <!-- เลือกได้อันเดียวใช้วงกลม เลือกได้หลายอันใช้สี่เหลี่ยม -->
                                    <span
                                        class="grid size-6 shrink-0 place-items-center border-2 transition-colors"
                                        :class="[
                                            group.max_select === 1 ? 'rounded-full' : 'rounded-md',
                                            isOn(group.id, m.id)
                                                ? 'border-[var(--series-1)] bg-[var(--series-1)]'
                                                : 'border-input',
                                        ]"
                                    >
                                        <span
                                            v-if="isOn(group.id, m.id) && group.max_select === 1"
                                            class="size-2.5 rounded-full bg-white"
                                        />
                                        <Check
                                            v-else-if="isOn(group.id, m.id)"
                                            class="size-4 text-white"
                                            stroke-width="3"
                                        />
                                    </span>

                                    <span class="min-w-0 flex-1 text-base">{{ m.name }}</span>

                                    <span
                                        v-if="Number(m.price_delta) !== 0"
                                        class="tabular shrink-0 text-base text-muted-foreground"
                                    >
                                        {{ Number(m.price_delta) > 0 ? '+' : '-' }}{{ price(Math.abs(Number(m.price_delta))) }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </section>

                    <!-- หมายเหตุ -->
                    <div class="h-2 bg-muted/60" />

                    <div class="px-4 pb-2 pt-4">
                        <div class="flex items-center justify-between gap-3 pb-2">
                            <h3 class="text-lg font-bold">หมายเหตุถึงร้านอาหาร</h3>
                            <span class="shrink-0 rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground">
                                ไม่จำเป็นต้องระบุ
                            </span>
                        </div>

                        <textarea
                            v-model="note"
                            rows="3"
                            maxlength="120"
                            class="w-full resize-none rounded-xl border border-input bg-background px-3 py-2.5 text-base placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            placeholder="ระบุรายละเอียดคำขอ (ขึ้นอยู่กับดุลยพินิจของร้าน)"
                        />
                    </div>

                    <div class="h-4" />
                </div>

                <!-- ปุ่มใส่ตะกร้า ติดขอบล่างตลอด -->
                <div
                    class="shrink-0 border-t bg-background px-4 pt-3"
                    :style="{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom, 0px))' }"
                >
                    <div class="flex items-center gap-3">
                        <!-- ปุ่มจำนวนอยู่คู่ปุ่มสั่ง นิ้วโป้งเอื้อมถึงทั้งคู่ -->
                        <div class="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                class="grid size-10 place-items-center rounded-full bg-muted text-foreground transition-transform active:scale-90 disabled:opacity-40"
                                :disabled="qty <= 1"
                                aria-label="ลดจำนวน"
                                @click="qty = Math.max(1, qty - 1)"
                            >
                                <Minus class="size-5" />
                            </button>

                            <span class="tabular w-8 text-center text-lg font-semibold" aria-live="polite">{{ qty }}</span>

                            <button
                                type="button"
                                class="grid size-10 place-items-center rounded-full bg-muted text-foreground transition-transform active:scale-90"
                                aria-label="เพิ่มจำนวน"
                                @click="qty++"
                            >
                                <Plus class="size-5" />
                            </button>
                        </div>

                        <button
                            type="button"
                            class="flex h-14 min-w-0 flex-1 items-center justify-between gap-2 rounded-full px-5 text-base font-semibold transition-colors"
                            :class="
                                missingRequired
                                    ? 'cursor-not-allowed bg-muted text-muted-foreground'
                                    : 'bg-[var(--series-1)] text-white active:brightness-110'
                            "
                            :disabled="missingRequired"
                            @click="confirm"
                        >
                            <span class="truncate">
                                {{ missingRequired ? missingLabel : 'เพิ่มลงตะกร้า' }}
                            </span>
                            <span v-if="!missingRequired" class="tabular shrink-0">฿{{ price(unitPrice * qty) }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
