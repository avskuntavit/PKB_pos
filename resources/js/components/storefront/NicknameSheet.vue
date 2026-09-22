<script setup lang="ts">
/**
 * ถามชื่อเล่นของคนที่กำลังสั่ง
 *
 * ── ทำไมถามตอนหยิบของชิ้นแรก ไม่ใช่ตอนเปิดหน้า ─────────────
 * การกั้นไม่ให้ดูเมนูจนกว่าจะพิมพ์ชื่อ ทำให้คนที่แค่อยากดูราคาปิดหน้าไปเลย
 * พอหยิบของชิ้นแรกแล้วค่อยถาม คนถึงจะเห็นเหตุผลว่าถามไปทำไม
 *
 * ── ทำไมข้ามได้ ────────────────────────────────────────────
 * โต๊ะที่มีคนเดียวไม่ต้องใช้ชื่อเลย บังคับก็มีแต่จะกวน
 * ข้ามแล้วจานจะไม่มีชื่อกำกับ ซึ่งไม่ได้ทำให้อะไรพัง
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { UserRound } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Spinner from '@/components/ui/Spinner.vue'

const props = defineProps<{ tableName: string | null }>()

const emit = defineEmits<{ (e: 'done'): void }>()

const name = ref('')
const saving = ref(false)

function save() {
    const value = name.value.trim()

    if (value === '') {
        emit('done')

        return
    }

    saving.value = true

    router.post(
        '/order/guest-name',
        { name: value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => emit('done'),
            onFinish: () => (saving.value = false),
        },
    )
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end bg-black/40 sm:items-center sm:justify-center">
        <div class="w-full rounded-t-2xl bg-background p-5 sm:max-w-sm sm:rounded-2xl">
            <div class="flex items-center gap-3">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-[var(--series-1)] text-white">
                    <UserRound class="size-5" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-bold">เรียกคุณว่าอะไรดี</h2>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="tableName">โต๊ะ {{ tableName }} · </template>
                        ใส่ชื่อเล่นไว้ เวลาอาหารมาถึงจะได้รู้ว่าจานไหนของใคร
                    </p>
                </div>
            </div>

            <form class="mt-4 space-y-3" @submit.prevent="save">
                <Input
                    v-model="name"
                    placeholder="เช่น ต้น"
                    maxlength="30"
                    autofocus
                    aria-label="ชื่อเล่น"
                />

                <div class="flex gap-2">
                    <Button type="button" variant="outline" size="lg" class="flex-1" @click="emit('done')">
                        ข้ามไปก่อน
                    </Button>
                    <Button type="submit" variant="brand" size="lg" class="flex-1" :disabled="saving">
                        <Spinner v-if="saving" class="size-4" />
                        ใช้ชื่อนี้
                    </Button>
                </div>
            </form>
        </div>
    </div>
</template>
