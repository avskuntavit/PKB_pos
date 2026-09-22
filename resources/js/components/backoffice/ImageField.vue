<script setup lang="ts">
/**
 * ช่องอัปโหลดรูปที่ใช้ร่วมกันทุกหน้าในหลังบ้าน
 *
 * โชว์ตัวอย่างรูปตามอัตราส่วนที่หน้าบ้านใช้จริง คนอัปโหลดจะได้เห็นเลย
 * ว่ารูปจะโดนครอบตัดตรงไหน ไม่ใช่ไปเซอร์ไพรส์ตอนเปิดหน้าลูกค้า
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { ImagePlus, X } from 'lucide-vue-next'
import Label from '@/components/ui/Label.vue'

interface Spec {
    label: string
    width: number
    height: number
    ratio: string
    max_mb: number
    formats: string
    hint: string
}

const props = withDefaults(
    defineProps<{
        /** รูปเดิมที่เก็บไว้ (path จากเซิร์ฟเวอร์) */
        existing?: string | null
        spec: Spec
        label?: string
        error?: string
        /** ทรงกรอบตัวอย่าง — ตามที่หน้าบ้านแสดงจริง */
        shape?: 'square' | 'wide' | 'round'
    }>(),
    { existing: null, shape: 'square' },
)

const file = defineModel<File | null>('file', { default: null })
const removed = defineModel<boolean>('removed', { default: false })

const input = ref<HTMLInputElement | null>(null)
const objectUrl = ref<string | null>(null)

function revoke() {
    if (objectUrl.value) {
        URL.revokeObjectURL(objectUrl.value)
        objectUrl.value = null
    }
}

// เผื่อหน้าแม่สั่ง reset ฟอร์ม ต้องเคลียร์ตัวอย่างตามด้วย
watch(file, (f) => {
    if (!f) {
        revoke()
        if (input.value) input.value.value = ''
    }
})

onBeforeUnmount(revoke)

const preview = computed(() => {
    if (objectUrl.value) return objectUrl.value
    if (removed.value) return null
    return props.existing
})

function onPick(e: Event) {
    const picked = (e.target as HTMLInputElement).files?.[0] ?? null

    revoke()
    file.value = picked
    removed.value = false

    if (picked) objectUrl.value = URL.createObjectURL(picked)
}

function drop() {
    revoke()
    file.value = null
    // มีรูปเดิมอยู่ต้องบอกเซิร์ฟเวอร์ให้ลบ ไม่งั้นรูปเดิมยังอยู่
    removed.value = Boolean(props.existing)
    if (input.value) input.value.value = ''
}

const frameClass = computed(() => {
    if (props.shape === 'wide') return 'aspect-video w-full max-w-sm'
    if (props.shape === 'round') return 'aspect-square w-24 rounded-full'
    return 'aspect-square w-24'
})
</script>

<template>
    <div class="space-y-1.5">
        <Label>{{ label ?? spec.label }}</Label>

        <div class="flex flex-wrap items-start gap-3">
            <div class="relative shrink-0">
                <img
                    v-if="preview"
                    :src="preview"
                    :alt="`ตัวอย่าง${spec.label}`"
                    class="rounded-lg border object-cover"
                    :class="frameClass"
                />
                <div
                    v-else
                    class="grid place-items-center rounded-lg border border-dashed text-xs text-muted-foreground"
                    :class="frameClass"
                >
                    ไม่มีรูป
                </div>

                <button
                    v-if="preview"
                    type="button"
                    class="absolute -right-2 -top-2 grid size-6 place-items-center rounded-full border bg-background shadow-sm hover:bg-accent"
                    :aria-label="`เอา${spec.label}ออก`"
                    @click="drop"
                >
                    <X class="size-3.5" />
                </button>
            </div>

            <div class="min-w-0 flex-1 space-y-1">
                <label
                    class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-input px-3 text-sm hover:bg-accent"
                >
                    <ImagePlus class="size-4" />
                    {{ preview ? 'เปลี่ยนรูป' : 'เลือกรูป' }}
                    <input
                        ref="input"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="hidden"
                        @change="onPick"
                    />
                </label>

                <p class="text-xs text-muted-foreground">{{ spec.hint }}</p>
                <p class="text-xs text-muted-foreground">
                    รูปที่อัตราส่วนไม่ตรงจะถูกครอบตัดจากตรงกลาง · ระบบย่อไฟล์ให้อัตโนมัติหลังอัปโหลด
                </p>
                <p v-if="error" class="text-xs text-[var(--status-critical)]">{{ error }}</p>
            </div>
        </div>
    </div>
</template>
