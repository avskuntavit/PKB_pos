<script setup lang="ts">
/**
 * ช่องแสดงลิงก์พร้อมปุ่มก๊อปปี้
 *
 * เรื่องที่ต้องระวัง: navigator.clipboard ใช้ได้เฉพาะบน https หรือ localhost
 * ร้านส่วนใหญ่เปิดหลังบ้านจากแท็บเล็ตผ่าน IP ในวง LAN (http://192.168.x.x)
 * ซึ่ง clipboard API จะไม่ทำงานเลย จึงต้องมีทางสำรองด้วย execCommand
 * และถ้ายังไม่ได้อีก ก็เลือกข้อความในช่องไว้ให้กด Ctrl+C เองได้ทันที
 */
import { onBeforeUnmount, ref } from 'vue'
import { Check, Copy, ExternalLink } from 'lucide-vue-next'
import Label from '@/components/ui/Label.vue'

const props = defineProps<{
    id: string
    label: string
    value: string
    hint?: string
    /** เปิดลิงก์ในแท็บใหม่ได้ไหม — ลิงก์ที่ต้องล็อกอินก็ยังเปิดได้ */
    openable?: boolean
}>()

const input = ref<HTMLInputElement | null>(null)
const copied = ref(false)

let timer: ReturnType<typeof setTimeout> | null = null

async function copy() {
    const ok = (await copyViaClipboard()) || copyViaSelection()

    // คัดลอกไม่ได้จริง ๆ ก็เลือกข้อความค้างไว้ ผู้ใช้กด Ctrl+C ต่อได้เลย
    if (!ok) {
        input.value?.select()

        return
    }

    copied.value = true

    if (timer) clearTimeout(timer)
    timer = setTimeout(() => (copied.value = false), 2000)
}

async function copyViaClipboard(): Promise<boolean> {
    try {
        if (!navigator.clipboard) return false

        await navigator.clipboard.writeText(props.value)

        return true
    } catch {
        return false
    }
}

function copyViaSelection(): boolean {
    try {
        const el = input.value
        if (!el) return false

        el.select()
        el.setSelectionRange(0, el.value.length)

        return document.execCommand('copy')
    } catch {
        return false
    }
}

onBeforeUnmount(() => {
    if (timer) clearTimeout(timer)
})
</script>

<template>
    <div class="space-y-1">
        <Label :for="id">{{ label }}</Label>

        <div class="flex gap-2">
            <input
                :id="id"
                ref="input"
                :value="value"
                type="text"
                readonly
                spellcheck="false"
                class="h-9 min-w-0 flex-1 rounded-md border border-input bg-muted/40 px-3 py-1 font-mono text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                @focus="input?.select()"
            />

            <button
                type="button"
                class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md border px-3 text-sm transition-colors"
                :class="
                    copied
                        ? 'border-[var(--status-good)] text-[var(--status-good)]'
                        : 'hover:bg-accent'
                "
                :aria-label="`ก๊อปปี้${label}`"
                @click="copy"
            >
                <component :is="copied ? Check : Copy" class="size-4" />
                <span class="hidden sm:inline">{{ copied ? 'ก๊อปแล้ว' : 'ก๊อปปี้' }}</span>
            </button>

            <a
                v-if="openable"
                :href="value"
                target="_blank"
                rel="noopener"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border transition-colors hover:bg-accent"
                :aria-label="`เปิด${label}ในแท็บใหม่`"
            >
                <ExternalLink class="size-4" />
            </a>
        </div>

        <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
