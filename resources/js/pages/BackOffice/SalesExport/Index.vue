<script setup lang="ts">
/**
 * ส่งข้อมูลการขายให้ระบบบัญชี (SAM)
 *
 * ── หน้านี้ตอบสามคำถาม ─────────────────────────────────────
 *   วันไหนยังไม่ได้ส่ง · วันไหนส่งไม่สำเร็จ · วันไหนส่งแล้วแต่ข้อมูลเปลี่ยนทีหลัง
 *
 * ── ทำไมกล่อง "ยังไม่มีการส่งเลย" อยู่บนสุด ─────────────────
 * วันที่ส่งแล้วล้มเหลวอย่างน้อยมีร่องรอยให้ตาม ส่วนวันที่ไม่มีใครส่งเลย
 * จะไม่โผล่ในตารางข้างล่าง เพราะตารางไล่จากแถวการส่ง ไม่ใช่จากวันที่มีการขาย
 */
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { AlertTriangle, Download, RefreshCw, Search, Send } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { date, dateTime, money, number } from '@/lib/format'

interface ExportRow {
    id: number
    business_date: string
    status: string
    status_label: string
    driver: string
    format: string | null
    detail: string
    bill_count: number
    grand_total: number
    attempts: number
    reference: string | null
    files: string[]
    last_error: string | null
    sent_at: string | null
    created_by: string | null
    is_stale: boolean
}

const props = defineProps<{
    rows: ExportRow[]
    missing: Array<{ business_date: string; bill_count: number }>
    config: { driver: string; detail: string; format: string; disk: string; path: string }
    filters: { from: string; to: string }
}>()

const STATUS_CLASS: Record<string, string> = {
    pending: 'bg-muted text-muted-foreground',
    sent: 'bg-[var(--status-good)]/15 text-[var(--status-good)]',
    failed: 'bg-[var(--status-critical)]/15 text-[var(--status-critical)]',
}

const DETAIL_LABEL: Record<string, string> = {
    summary: 'ยอดสรุปรายวัน',
    bills: 'รายบิล',
    both: 'ทั้งสรุปและรายบิล',
}

/* ---------- ช่วงวันที่ ---------- */

const from = ref(props.filters.from)
const to = ref(props.filters.to)

function apply() {
    router.get(
        '/backoffice/sales-export',
        { from: from.value, to: to.value },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

/* ---------- ส่งข้อมูล ---------- */

const form = useForm({ business_date: '', force: false })
const sending = ref<string | null>(null)

function send(businessDate: string, force = false) {
    if (sending.value) return

    sending.value = businessDate
    form.business_date = businessDate
    form.force = force

    form.post('/backoffice/sales-export', {
        preserveScroll: true,
        onFinish: () => (sending.value = null),
    })
}

function fileUrl(row: ExportRow, path: string) {
    return `/backoffice/sales-export/${row.id}/download?path=${encodeURIComponent(path)}`
}

/** ชื่อไฟล์สั้น ๆ พอให้กดถูกใบ ไม่ต้องโชว์ทั้งเส้นทาง */
function fileName(path: string) {
    return path.split('/').pop() ?? path
}

const staleCount = computed(() => props.rows.filter((r) => r.is_stale).length)
const failedCount = computed(() => props.rows.filter((r) => r.status === 'failed').length)
</script>

<template>
    <Head title="ส่งข้อมูลให้ระบบบัญชี" />

    <BackOfficeLayout title="ส่งข้อมูลให้ระบบบัญชี">
        <div class="space-y-4">
            <!-- ตั้งค่าที่ใช้อยู่ -->
            <div class="rounded-xl border bg-card p-4 text-sm">
                <div class="flex flex-wrap gap-x-6 gap-y-1.5">
                    <span>
                        ช่องทางส่ง <span class="font-medium">{{ config.driver }}</span>
                    </span>
                    <span>
                        ระดับข้อมูล
                        <span class="font-medium">{{ DETAIL_LABEL[config.detail] ?? config.detail }}</span>
                    </span>
                    <span v-if="config.driver === 'file'">
                        รูปแบบ <span class="font-medium">{{ config.format }}</span>
                    </span>
                    <span v-if="config.driver === 'file'" class="text-muted-foreground">
                        เขียนลงที่ <span class="font-mono text-xs">{{ config.disk }}:{{ config.path }}</span>
                    </span>
                </div>
                <p class="mt-2 text-xs text-muted-foreground">
                    ปกติตัวตั้งเวลาเรียก <span class="font-mono">php artisan sales:export</span> ให้ทุกเช้า
                    ปุ่มในหน้านี้มีไว้สำหรับวันที่พลาด หรือวันที่ต้องส่งใหม่หลังแก้บิลย้อนหลัง
                </p>
            </div>

            <!-- ช่วงวันที่ -->
            <div class="rounded-xl border bg-card p-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="space-y-1">
                        <Label for="from">ตั้งแต่วันขาย</Label>
                        <Input id="from" v-model="from" type="date" class="w-[160px]" />
                    </div>
                    <div class="space-y-1">
                        <Label for="to">ถึงวันขาย</Label>
                        <Input id="to" v-model="to" type="date" class="w-[160px]" />
                    </div>
                    <Button variant="brand" @click="apply">
                        <Search />
                        ตกลง
                    </Button>
                </div>
            </div>

            <!-- วันที่มีการขายแต่ยังไม่มีการส่งเลย -->
            <div
                v-if="missing.length"
                class="rounded-xl border-2 border-[var(--status-critical)] bg-[var(--status-critical)]/8 p-4"
                role="alert"
            >
                <div class="flex items-start gap-2.5">
                    <AlertTriangle class="mt-0.5 size-5 shrink-0 text-[var(--status-critical)]" />
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-[var(--status-critical)]">
                            มี {{ missing.length }} วันที่มีการขายแต่ยังไม่เคยส่งข้อมูลเลย
                        </p>
                        <ul class="mt-2 space-y-1.5">
                            <li
                                v-for="m in missing"
                                :key="m.business_date"
                                class="flex flex-wrap items-center gap-2 text-sm"
                            >
                                <span class="w-[110px]">{{ date(m.business_date) }}</span>
                                <span class="tabular text-muted-foreground">{{ number(m.bill_count) }} บิล</span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="sending === m.business_date"
                                    @click="send(m.business_date)"
                                >
                                    <Spinner v-if="sending === m.business_date" class="size-3.5" />
                                    <Send v-else />
                                    ส่งเลย
                                </Button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- ส่งแล้วแต่ข้อมูลเปลี่ยนทีหลัง -->
            <div
                v-if="staleCount || failedCount"
                class="rounded-xl border-2 border-[var(--status-warning)] bg-[var(--status-warning)]/8 p-4"
                role="alert"
            >
                <div class="flex items-start gap-2.5">
                    <AlertTriangle class="mt-0.5 size-5 shrink-0 text-[var(--status-warning)]" />
                    <div class="min-w-0">
                        <p class="font-semibold">
                            <template v-if="staleCount">
                                {{ staleCount }} วันที่ส่งไปแล้วแต่มีการแก้บิลย้อนหลัง
                            </template>
                            <template v-if="staleCount && failedCount"> · </template>
                            <template v-if="failedCount">{{ failedCount }} วันที่ส่งไม่สำเร็จ</template>
                        </p>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            ข้อมูลที่ปลายทางถืออยู่ไม่ตรงกับของเราแล้ว กดส่งใหม่เพื่อให้ตรงกัน
                        </p>
                    </div>
                </div>
            </div>

            <p v-if="form.errors.business_date" class="text-sm text-[var(--status-critical)]">
                {{ form.errors.business_date }}
            </p>

            <SectionCard title="ประวัติการส่ง">
                <div v-if="rows.length" class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead>
                            <tr class="border-b text-left text-xs text-muted-foreground">
                                <th class="py-2 pe-3 font-medium">วันขาย</th>
                                <th class="py-2 pe-3 text-right font-medium">บิล</th>
                                <th class="py-2 pe-3 text-right font-medium">ยอดรวม</th>
                                <th class="py-2 pe-3 font-medium">สถานะ</th>
                                <th class="py-2 pe-3 font-medium">ไฟล์ / เลขอ้างอิง</th>
                                <th class="py-2 pe-3 font-medium">ส่งเมื่อ</th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in rows" :key="row.id">
                                <td class="py-2.5 pe-3">{{ date(row.business_date) }}</td>
                                <td class="tabular py-2.5 pe-3 text-right">{{ number(row.bill_count) }}</td>
                                <td class="tabular py-2.5 pe-3 text-right">{{ money(row.grand_total) }}</td>
                                <td class="py-2.5 pe-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs"
                                        :class="STATUS_CLASS[row.status] ?? 'bg-muted'"
                                    >
                                        {{ row.status_label }}
                                    </span>
                                    <span
                                        v-if="row.is_stale"
                                        class="ms-1 rounded-full bg-[var(--status-warning)]/15 px-2 py-0.5 text-xs text-[var(--status-warning)]"
                                    >
                                        ข้อมูลเปลี่ยนหลังส่ง
                                    </span>
                                    <span v-if="row.last_error" class="mt-0.5 block text-xs text-[var(--status-critical)]">
                                        {{ row.last_error }}
                                    </span>
                                    <span v-if="row.attempts > 1" class="mt-0.5 block text-xs text-muted-foreground">
                                        ส่งไปแล้ว {{ row.attempts }} ครั้ง
                                    </span>
                                </td>
                                <td class="py-2.5 pe-3">
                                    <template v-if="row.files.length">
                                        <a
                                            v-for="f in row.files"
                                            :key="f"
                                            :href="fileUrl(row, f)"
                                            class="flex items-center gap-1 text-xs text-[var(--series-1)] hover:underline"
                                        >
                                            <Download class="size-3" />
                                            {{ fileName(f) }}
                                        </a>
                                    </template>
                                    <span v-else class="text-xs text-muted-foreground">
                                        {{ row.reference ?? '-' }}
                                    </span>
                                </td>
                                <td class="py-2.5 pe-3 text-xs text-muted-foreground">
                                    {{ row.sent_at ? dateTime(row.sent_at) : '-' }}
                                    <span v-if="row.created_by" class="block">{{ row.created_by }}</span>
                                </td>
                                <td class="py-2.5">
                                    <div class="flex justify-end">
                                        <Button
                                            :variant="row.is_stale || row.status === 'failed' ? 'brand' : 'outline'"
                                            size="sm"
                                            :disabled="sending === row.business_date"
                                            @click="send(row.business_date, true)"
                                        >
                                            <Spinner v-if="sending === row.business_date" class="size-3.5" />
                                            <RefreshCw v-else />
                                            ส่งใหม่
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <EmptyState
                    v-else
                    title="ยังไม่มีประวัติการส่งในช่วงที่เลือก"
                    description="ข้อมูลจะขึ้นเมื่อมีการส่งครั้งแรก ไม่ว่าจากตัวตั้งเวลาหรือจากปุ่มในหน้านี้"
                />
            </SectionCard>
        </div>
    </BackOfficeLayout>
</template>
