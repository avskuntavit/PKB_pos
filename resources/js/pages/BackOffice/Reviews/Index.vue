<script setup lang="ts">
import { ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { MessageSquareReply } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import FilterBar from '@/components/backoffice/FilterBar.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import StatCard from '@/components/backoffice/StatCard.vue'
import StarRating from '@/components/storefront/StarRating.vue'
import Pagination from '@/components/ui/Pagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import { dateTime, money, number } from '@/lib/format'
import type { PageProps, Paginated } from '@/types'

const props = defineProps<{
    filters: { from: string; to: string; rating?: number | string | null }
    reviews: Paginated<Record<string, any>>
    stats: {
        total: number
        average: number
        distribution: Record<string, number>
        top_tags: Array<{ tag: string; count: number }>
    }
}>()

const page = usePage<PageProps>()

const replying = ref<Record<string, any> | null>(null)
const replyText = ref('')

function filterByRating(rating: number | null) {
    router.get(
        '/backoffice/reviews',
        { ...props.filters, rating: rating ?? undefined },
        { preserveState: true, replace: true },
    )
}

function submitReply() {
    if (!replying.value || !replyText.value.trim()) return

    router.post(
        `/backoffice/reviews/${replying.value.id}/reply`,
        { reply: replyText.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                replying.value = null
                replyText.value = ''
            },
        },
    )
}

/** สัดส่วนของแต่ละดาว ใช้วาดแถบ */
function barWidth(rating: number): string {
    const count = props.stats.distribution[String(rating)] ?? 0
    return props.stats.total > 0 ? `${(count / props.stats.total) * 100}%` : '0%'
}
</script>

<template>
    <Head title="ความพึงพอใจ" />

    <BackOfficeLayout title="ความพึงพอใจ">
        <FilterBar :branches="page.props.branches" :filters="filters" action="/backoffice/reviews" />

        <div class="grid gap-4 lg:grid-cols-3">
            <SectionCard title="คะแนนเฉลี่ย" content-class="space-y-3">
                <div class="text-center">
                    <p class="tabular text-4xl font-bold">{{ stats.average || '-' }}</p>
                    <StarRating :model-value="Math.round(stats.average)" readonly size="sm" class="mt-1" />
                    <p class="mt-1 text-xs text-muted-foreground">จาก {{ number(stats.total) }} รีวิว</p>
                </div>

                <ul class="space-y-1.5">
                    <li v-for="r in [5, 4, 3, 2, 1]" :key="r" class="flex items-center gap-2 text-xs">
                        <button
                            class="w-6 shrink-0 text-right hover:underline"
                            @click="filterByRating(r)"
                        >
                            {{ r }}★
                        </button>
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                            <span
                                class="block h-full rounded-full bg-[var(--status-warning)]"
                                :style="{ width: barWidth(r) }"
                            />
                        </span>
                        <span class="tabular w-8 shrink-0 text-right text-muted-foreground">
                            {{ stats.distribution[String(r)] ?? 0 }}
                        </span>
                    </li>
                </ul>

                <Button v-if="filters.rating" variant="outline" size="sm" class="w-full" @click="filterByRating(null)">
                    ล้างตัวกรองดาว
                </Button>
            </SectionCard>

            <SectionCard title="สิ่งที่ลูกค้าพูดถึงบ่อย" class="lg:col-span-2">
                <ul v-if="stats.top_tags.length" class="flex flex-wrap gap-2">
                    <li
                        v-for="tag in stats.top_tags"
                        :key="tag.tag"
                        class="rounded-full border px-3 py-1.5 text-sm"
                    >
                        {{ tag.tag }}
                        <span class="tabular ml-1 text-muted-foreground">{{ tag.count }}</span>
                    </li>
                </ul>
                <EmptyState v-else description="ยังไม่มีแท็กจากลูกค้าในช่วงนี้" />
            </SectionCard>
        </div>

        <SectionCard title="รีวิวทั้งหมด" content-class="p-0">
            <ul v-if="reviews.data.length" class="divide-y">
                <li v-for="review in reviews.data" :key="review.id" class="px-4 py-3">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <StarRating :model-value="review.rating" readonly size="sm" />
                                <span class="text-sm font-medium">
                                    {{ review.customer?.name ?? 'ลูกค้าทั่วไป' }}
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                บิล {{ review.order?.order_no }} · {{ money(review.order?.grand_total ?? 0) }} ฿ ·
                                {{ dateTime(review.created_at) }}
                            </p>
                        </div>

                        <Button
                            v-if="!review.reply"
                            variant="outline"
                            size="sm"
                            @click="replying = review"
                        >
                            <MessageSquareReply />
                            ตอบกลับ
                        </Button>
                    </div>

                    <div v-if="review.tags?.length" class="mt-2 flex flex-wrap gap-1.5">
                        <Badge v-for="tag in review.tags" :key="tag" variant="outline">{{ tag }}</Badge>
                    </div>

                    <p v-if="review.comment" class="mt-2 text-sm">{{ review.comment }}</p>

                    <div v-if="review.reply" class="mt-2 rounded-lg border-l-2 border-[var(--series-1)] bg-muted/40 px-3 py-2">
                        <p class="text-xs font-medium text-[var(--series-1)]">
                            ร้านตอบกลับ · {{ review.replied_by?.name }}
                        </p>
                        <p class="text-sm">{{ review.reply }}</p>
                    </div>
                </li>
            </ul>
            <EmptyState v-else title="ยังไม่มีรีวิว" description="ลูกค้าให้คะแนนได้หลังชำระเงินเรียบร้อย" />

            <Pagination :links="reviews.links" :total="reviews.total" />
        </SectionCard>

        <Modal
            :open="replying !== null"
            title="ตอบกลับรีวิว"
            description="ลูกค้าจะเห็นคำตอบนี้ในประวัติการสั่งของตัวเอง"
            @update:open="(v) => !v && (replying = null)"
        >
            <div class="space-y-3">
                <p v-if="replying?.comment" class="rounded-lg bg-muted/40 p-3 text-sm">
                    "{{ replying.comment }}"
                </p>

                <Input v-model="replyText" class="h-11" maxlength="500" placeholder="ขอบคุณสำหรับคำแนะนำครับ..." />

                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="replying = null">ยกเลิก</Button>
                    <Button variant="brand" :disabled="!replyText.trim()" @click="submitReply">ส่งคำตอบ</Button>
                </div>
            </div>
        </Modal>
    </BackOfficeLayout>
</template>
