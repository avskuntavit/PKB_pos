<script setup lang="ts">
/**
 * ให้คะแนนบิล
 *
 * ถามดาวก่อน แล้วค่อยโชว์แท็กที่ตรงกับอารมณ์นั้น
 * (ให้ 5 ดาวแล้วเจอตัวเลือก "รอนาน" มันขัดกัน)
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/components/ui/Modal.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import StarRating from '@/components/storefront/StarRating.vue'

const props = defineProps<{ token: string }>()
const open = defineModel<boolean>('open', { default: false })

const rating = ref(0)
const tags = ref<string[]>([])
const comment = ref('')
const sending = ref(false)

const positive = ['อาหารอร่อย', 'เสิร์ฟเร็ว', 'พนักงานบริการดี', 'ร้านสะอาด', 'คุ้มราคา']
const negative = ['รอนาน', 'รสชาติไม่ถูกปาก', 'อาหารไม่ร้อน', 'สั่งผิดรายการ', 'ร้านไม่สะอาด']

const options = computed(() => (rating.value >= 4 ? positive : rating.value > 0 ? negative : []))

function toggle(tag: string) {
    tags.value = tags.value.includes(tag) ? tags.value.filter((t) => t !== tag) : [...tags.value, tag]
}

function submit() {
    sending.value = true

    router.post(
        `/order/track/${props.token}/review`,
        { rating: rating.value, tags: tags.value, comment: comment.value || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false
                rating.value = 0
                tags.value = []
                comment.value = ''
            },
            onFinish: () => (sending.value = false),
        },
    )
}
</script>

<template>
    <Modal v-model:open="open" title="ให้คะแนนมื้อนี้" description="ความเห็นของคุณช่วยร้านปรับปรุงได้จริง">
        <div class="space-y-4">
            <div class="py-2">
                <StarRating v-model="rating" />
            </div>

            <div v-if="options.length" class="space-y-2">
                <p class="text-sm font-medium">
                    {{ rating >= 4 ? 'อะไรที่ชอบเป็นพิเศษ' : 'ตรงไหนที่อยากให้ปรับปรุง' }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="tag in options"
                        :key="tag"
                        type="button"
                        class="min-h-10 rounded-full border px-3 text-sm transition-colors"
                        :class="
                            tags.includes(tag)
                                ? 'border-[var(--series-1)] bg-[var(--series-1)]/10 text-[var(--series-1)]'
                                : 'hover:bg-accent'
                        "
                        @click="toggle(tag)"
                    >
                        {{ tag }}
                    </button>
                </div>
            </div>

            <div v-if="rating > 0" class="space-y-1">
                <Input v-model="comment" class="h-11" maxlength="500" placeholder="อยากบอกอะไรร้านเพิ่มไหม (ไม่บังคับ)" />
            </div>

            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="open = false">ไว้ก่อน</Button>
                <Button variant="brand" :disabled="rating === 0 || sending" @click="submit">
                    {{ sending ? 'กำลังส่ง...' : 'ส่งคะแนน' }}
                </Button>
            </div>
        </div>
    </Modal>
</template>
