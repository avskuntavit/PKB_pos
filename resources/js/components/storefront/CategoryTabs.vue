<script setup lang="ts">
/**
 * แถบหมวดหมู่แบบเลื่อนตาม — หมวดที่กำลังอยู่บนจอจะถูกไฮไลต์ให้เอง
 *
 * ใช้ IntersectionObserver แทนการฟัง scroll เพราะเบากว่ามาก
 * และแถบจะเลื่อนตัวเองให้เห็นหมวดที่กำลังใช้งานอยู่เสมอ ไม่ต้องให้ลูกค้าหา
 *
 * ปุ่ม ☰ เปิดรายการหมวดทั้งหมด เผื่อร้านที่มี 20 หมวดจะได้ไม่ต้องรูด
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { List, Search, X } from 'lucide-vue-next'

const props = defineProps<{
    categories: Array<{ id: number; name: string; color: string }>
    /** id ของ element แต่ละหมวดบนหน้า — ใช้ค่าเดียวกับที่หน้าแม่ตั้งไว้ */
    sectionId: (categoryId: number) => string
}>()

const active = defineModel<number | null>('active', { default: null })
const search = defineModel<string>('search', { default: '' })

const showSearch = ref(false)
const showList = ref(false)
const bar = ref<HTMLElement | null>(null)

let observer: IntersectionObserver | null = null

/** หมวดที่มองเห็นอยู่ตอนนี้ เรียงตามลำดับบนหน้า */
const visible = new Set<number>()

function refreshActive() {
    if (visible.size === 0) return

    const first = props.categories.find((c) => visible.has(c.id))
    if (first && first.id !== active.value) active.value = first.id
}

function observe() {
    observer?.disconnect()
    visible.clear()

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                const id = Number((entry.target as HTMLElement).dataset.categoryId)
                if (Number.isNaN(id)) continue
                entry.isIntersecting ? visible.add(id) : visible.delete(id)
            }
            refreshActive()
        },
        {
            // นับว่า "อยู่บนจอ" เฉพาะช่วงใต้แถบหมวดลงมา ไม่งั้นหมวดที่เพิ่งเลื่อนพ้นยังถูกนับ
            rootMargin: '-120px 0px -65% 0px',
            threshold: 0,
        },
    )

    for (const c of props.categories) {
        const el = document.getElementById(props.sectionId(c.id))
        if (el) observer.observe(el)
    }
}

onMounted(() => nextTick(observe))
onBeforeUnmount(() => observer?.disconnect())

// หมวดเปลี่ยนเมื่อค้นหา ต้องผูก observer ใหม่กับ element ชุดใหม่
watch(() => props.categories.map((c) => c.id).join(','), () => nextTick(observe))

/** เลื่อนแถบให้เห็นปุ่มที่ active เสมอ */
watch(active, async (id) => {
    if (id === null) return
    await nextTick()
    bar.value?.querySelector<HTMLElement>(`[data-tab="${id}"]`)?.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'center',
    })
})

function goTo(categoryId: number) {
    showList.value = false
    active.value = categoryId

    const el = document.getElementById(props.sectionId(categoryId))
    if (!el) return

    // เผื่อที่ให้แถบหมวดที่ลอยอยู่ ไม่งั้นหัวข้อหมวดจะโดนบัง
    const top = el.getBoundingClientRect().top + window.scrollY - 104
    window.scrollTo({ top, behavior: 'smooth' })
}

function toggleSearch() {
    showSearch.value = !showSearch.value
    if (!showSearch.value) search.value = ''
}
</script>

<template>
    <div class="sticky top-0 z-30 border-b bg-background/95 backdrop-blur">
        <!--
          ที่ว่างให้หน้าแม่แปะอะไรที่ต้อง "ค้างอยู่บนจอ" ไปพร้อมแท็บหมวด
          ตอนนี้คือแถบเลขโต๊ะ — วางไว้ในนี้แทนที่จะทำ sticky อีกชั้น
          เพราะ sticky ซ้อน sticky ต้องคำนวณ top เองซึ่งพังทันทีที่ความสูงเปลี่ยน
        -->
        <slot name="lead" />

        <div class="mx-auto flex max-w-2xl items-center gap-1 px-2 lg:max-w-5xl">
            <button
                type="button"
                class="grid size-11 shrink-0 place-items-center rounded-full transition-colors active:bg-accent"
                aria-label="ดูหมวดหมู่ทั้งหมด"
                @click="showList = true"
            >
                <List class="size-5" />
            </button>

            <button
                type="button"
                class="grid size-11 shrink-0 place-items-center rounded-full transition-colors active:bg-accent"
                :class="showSearch && 'text-[var(--series-1)]'"
                aria-label="ค้นหาเมนู"
                @click="toggleSearch"
            >
                <Search class="size-5" />
            </button>

            <!-- แท็บหมวด เลื่อนแนวนอนได้ -->
            <div ref="bar" class="flex min-w-0 flex-1 gap-1 overflow-x-auto scroll-smooth">
                <button
                    v-for="c in categories"
                    :key="c.id"
                    type="button"
                    :data-tab="c.id"
                    class="relative shrink-0 whitespace-nowrap px-3 py-3 text-base transition-colors"
                    :class="
                        active === c.id
                            ? 'font-bold text-[var(--series-1)]'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="goTo(c.id)"
                >
                    {{ c.name }}
                    <span
                        v-if="active === c.id"
                        class="absolute inset-x-2 bottom-0 h-0.5 rounded-full bg-[var(--series-1)]"
                    />
                </button>
            </div>
        </div>

        <div v-if="showSearch" class="mx-auto max-w-2xl px-3 pb-2 lg:max-w-5xl">
            <input
                v-model="search"
                type="search"
                placeholder="ค้นหาเมนูในร้านนี้"
                class="h-11 w-full rounded-full border bg-card px-4 text-base focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label="ค้นหาเมนู"
            />
        </div>
    </div>

    <!-- รายการหมวดทั้งหมด -->
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="showList" class="fixed inset-0 z-40 bg-black/50" @click="showList = false" />
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-250 ease-out"
            enter-from-class="translate-y-full"
            leave-active-class="transition-transform duration-200 ease-in"
            leave-to-class="translate-y-full"
        >
            <div
                v-if="showList"
                class="fixed inset-x-0 bottom-0 z-50 mx-auto w-full max-w-2xl overflow-hidden rounded-t-2xl bg-background shadow-2xl sm:inset-x-4 sm:bottom-4 sm:w-auto sm:rounded-2xl"
                role="dialog"
                aria-modal="true"
                aria-label="หมวดหมู่ทั้งหมด"
            >
                <header class="flex h-14 items-center justify-between gap-2 border-b px-4">
                    <h2 class="text-lg font-bold">หมวดหมู่ทั้งหมด</h2>
                    <button
                        type="button"
                        class="grid size-10 place-items-center rounded-full transition-colors hover:bg-accent"
                        aria-label="ปิด"
                        @click="showList = false"
                    >
                        <X class="size-5" />
                    </button>
                </header>

                <ul class="max-h-[60dvh] divide-y overflow-y-auto overscroll-contain sm:grid sm:grid-cols-2 sm:gap-px sm:divide-y-0 sm:bg-border lg:grid-cols-3">
                    <li v-for="c in categories" :key="c.id" class="bg-background">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-colors active:bg-accent"
                            @click="goTo(c.id)"
                        >
                            <span class="size-2.5 shrink-0 rounded-full" :style="{ background: c.color }" />
                            <span
                                class="min-w-0 flex-1 text-base"
                                :class="active === c.id && 'font-bold text-[var(--series-1)]'"
                            >
                                {{ c.name }}
                            </span>
                        </button>
                    </li>
                </ul>

                <div :style="{ height: 'env(safe-area-inset-bottom, 0px)' }" />
            </div>
        </Transition>
    </Teleport>
</template>
