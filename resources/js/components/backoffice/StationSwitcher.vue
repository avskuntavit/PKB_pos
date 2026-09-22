<script setup lang="ts">
/**
 * สลับสถานีที่กำลังดูอยู่ พร้อมแสดงโลโก้สถานีและสีประจำสถานี
 *
 * ยิง POST แล้วให้ Inertia โหลดหน้าเดิมซ้ำ ข้อมูลทั้งหน้าจะเปลี่ยนเป็นของสถานีใหม่เอง
 * โดยที่แต่ละหน้าไม่ต้องเขียนอะไรเพิ่ม เพราะทุกหน้าอ่านสถานีจาก ResolveCurrentBranch ตัวเดียวกัน
 */
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { ChevronDown, Store } from 'lucide-vue-next'
import type { PageProps } from '@/types'

const page = usePage<PageProps>()

const branches = computed(() => page.props.branches ?? [])
const current = computed(() => page.props.currentBranch)

function switchTo(event: Event) {
    const id = Number((event.target as HTMLSelectElement).value)

    if (!id || id === current.value?.id) return

    router.post('/backoffice/station', { branch_id: id }, { preserveScroll: true })
}
</script>

<template>
    <!-- มีหลายสถานี ให้เลือกสลับได้พร้อมแสดงโลโก้ -->
    <div
        v-if="branches.length > 1"
        class="relative flex items-center gap-2 rounded-lg border bg-card/90 py-1 pl-1.5 pr-2.5 shadow-2xs transition-all hover:border-primary/40 hover:bg-card"
    >
        <!-- โลโก้สถานี หรือ Fallback Badge ตามสีประจำสถานี -->
        <img
            v-if="current?.logo_path"
            :src="current.logo_path"
            class="size-6 rounded-md object-cover border shrink-0 bg-background shadow-2xs"
            :alt="current.name"
        />
        <div
            v-else
            class="flex size-6 shrink-0 items-center justify-center rounded-md text-[11px] font-bold text-white shadow-2xs"
            :style="{ backgroundColor: current?.theme_color || 'var(--station-color, #2a78d6)' }"
        >
            {{ current?.name?.charAt(0) || 'S' }}
        </div>

        <div class="relative min-w-0">
            <select
                class="h-7 w-full appearance-none rounded bg-transparent pr-5 text-xs font-semibold text-foreground focus:outline-none cursor-pointer"
                :value="current?.id"
                aria-label="สลับสถานี"
                @change="switchTo"
            >
                <option v-for="b in branches" :key="b.id" :value="b.id" class="bg-card text-foreground py-1">
                    {{ b.name }}
                </option>
            </select>
            <ChevronDown class="pointer-events-none absolute right-0 top-1/2 -translate-y-1/2 size-3.5 text-muted-foreground" />
        </div>
    </div>

    <!-- มีสถานีเดียว แสดงโลโก้พร้อมชื่อสถานีแบบ compact -->
    <div
        v-else-if="current"
        class="hidden items-center gap-2 rounded-lg border bg-card/90 px-2 py-1 shadow-2xs sm:flex"
    >
        <img
            v-if="current.logo_path"
            :src="current.logo_path"
            class="size-6 rounded-md object-cover border shrink-0 bg-background shadow-2xs"
            :alt="current.name"
        />
        <div
            v-else
            class="flex size-6 shrink-0 items-center justify-center rounded-md text-[11px] font-bold text-white shadow-2xs"
            :style="{ backgroundColor: current.theme_color || 'var(--station-color, #2a78d6)' }"
        >
            {{ current.name.charAt(0) }}
        </div>
        <span class="text-xs font-semibold text-foreground truncate max-w-[160px]">{{ current.name }}</span>
    </div>
</template>
