<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import {
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    DatabaseBackup,
    HardDriveDownload,
    RotateCcw,
    ShieldCheck,
    Timer,
} from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Pagination from '@/components/ui/Pagination.vue'
import { dateTime, number } from '@/lib/format'
import type { Paginated } from '@/types'

interface BackupSummary {
    kind: string
    label: string
    description: string
    last_at: string | null
    last_status: string | null
    age_hours: number | null
    size: string | null
    duration: string | null
    verified: boolean
    path: string | null
    is_stale: boolean
    last_error: string | null
}

interface BackupRunRow {
    id: number
    kind: string
    kind_label: string
    slot: string | null
    status: string
    size: string
    duration: string
    verified: boolean
    path: string | null
    error: string | null
    finished_at: string | null
    by: string | null
}

interface ErrorRow {
    id: number
    level: string
    class: string
    message: string
    location: string | null
    url: string | null
    method: string | null
    occurrences: number
    first_seen_at: string | null
    last_seen_at: string | null
    resolved_at: string | null
    trace: string | null
}

interface Warning {
    key: string
    label: string
    detail: string
    level: string
}

const props = defineProps<{
    backups: BackupSummary[]
    warnings: Warning[]
    runs: BackupRunRow[]
    errors: Paginated<ErrorRow>
    openErrorCount: number
    filters: { errors: string }
    scheduler: { last_ping_at: string | null; is_down: boolean; stale_minutes: number }
    settings: {
        enabled: boolean
        time: string
        sql_path: string
        files_path: string
        verify: boolean
        stale_hours: number
        kinds: Array<{ value: string; label: string; description: string }>
    }
}>()

/*
| ปุ่มสำรองยิงตรงไม่ผ่านคิว และฐานข้อมูลใหญ่ ๆ ใช้เวลาเป็นนาที
| จึงต้องล็อกปุ่มไว้ระหว่างรอ ไม่งั้นคนจะกดซ้ำแล้วสำรองซ้อนกันสองรอบ
*/
const running = ref<string | null>(null)

const expanded = ref<number | null>(null)

const staleCount = computed(() => props.backups.filter((b) => b.is_stale).length)

const showingAll = computed(() => props.filters.errors === 'all')

function runBackup(only: string | null) {
    if (running.value) return

    running.value = only ?? 'all'

    router.post(
        '/backoffice/health/backup',
        only ? { only } : {},
        {
            preserveScroll: true,
            onFinish: () => {
                running.value = null
            },
        },
    )
}

function resolve(id: number) {
    router.post(`/backoffice/health/errors/${id}/resolve`, {}, { preserveScroll: true })
}

function reopen(id: number) {
    router.post(`/backoffice/health/errors/${id}/reopen`, {}, { preserveScroll: true })
}

function ageLabel(hours: number | null): string {
    if (hours === null) return 'ยังไม่เคยสำรอง'
    if (hours < 1) return 'ไม่ถึงหนึ่งชั่วโมงที่แล้ว'
    if (hours < 48) return `${number(hours, 1)} ชั่วโมงที่แล้ว`

    return `${number(hours / 24, 1)} วันที่แล้ว`
}
</script>

<template>
    <Head title="สุขภาพระบบ" />

    <BackOfficeLayout title="สุขภาพระบบและสำรองข้อมูล">
        <!-- ค่าตั้งค่าที่อันตราย — ขึ้นก่อนทุกอย่างเพราะแก้ได้ทันทีและเสียหายมากถ้าปล่อยไว้ -->
        <SectionCard v-if="warnings.length" title="ต้องแก้" content-class="space-y-2 p-0">
            <div
                v-for="w in warnings"
                :key="w.key"
                class="flex items-start gap-3 border-b px-4 py-3 last:border-b-0"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0 text-[var(--status-critical)]" />
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-[var(--status-critical)]">{{ w.label }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ w.detail }}</p>
                </div>
            </div>
        </SectionCard>

        <!-- สถานะการสำรองรายชนิด -->
        <SectionCard title="การสำรองข้อมูล" content-class="p-0">
            <template #actions>
                <Button size="sm" :disabled="running !== null" @click="runBackup(null)">
                    <HardDriveDownload />
                    {{ running === 'all' ? 'กำลังสำรอง...' : 'สำรองเดี๋ยวนี้ทั้งหมด' }}
                </Button>
            </template>

            <div class="grid gap-px bg-border sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="b in backups" :key="b.kind" class="bg-card p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ b.label }}</p>
                            <p class="mt-0.5 text-[11px] text-muted-foreground">{{ b.description }}</p>
                        </div>
                        <Badge :variant="b.is_stale ? 'danger' : 'success'">
                            {{ b.is_stale ? 'ขาดการสำรอง' : 'ปกติ' }}
                        </Badge>
                    </div>

                    <div class="mt-3 space-y-1 text-xs">
                        <p class="flex items-center gap-1.5">
                            <DatabaseBackup class="size-3.5 shrink-0 text-muted-foreground" />
                            <span :class="b.is_stale ? 'font-semibold text-[var(--status-critical)]' : ''">
                                {{ ageLabel(b.age_hours) }}
                            </span>
                            <span v-if="b.last_at" class="text-muted-foreground">({{ dateTime(b.last_at) }})</span>
                        </p>
                        <p class="text-muted-foreground">
                            ขนาด {{ b.size ?? '—' }} · ใช้เวลา {{ b.duration ?? '—' }}
                        </p>
                        <p v-if="b.verified" class="flex items-center gap-1.5 text-[var(--status-good)]">
                            <ShieldCheck class="size-3.5 shrink-0" />
                            ตรวจแล้วว่าไฟล์กู้กลับได้
                        </p>
                        <p v-if="b.path" class="break-all font-mono text-[10px] text-muted-foreground">
                            {{ b.path }}
                        </p>
                        <p v-if="b.last_error" class="text-[var(--status-critical)]">
                            ครั้งล่าสุดที่ลองแล้วไม่ผ่าน: {{ b.last_error }}
                        </p>
                    </div>

                    <Button
                        variant="outline"
                        size="sm"
                        class="mt-3 w-full"
                        :disabled="running !== null"
                        @click="runBackup(b.kind)"
                    >
                        {{ running === b.kind ? 'กำลังสำรอง...' : 'สำรองเฉพาะอันนี้' }}
                    </Button>
                </div>
            </div>

            <div class="space-y-1 border-t px-4 py-3 text-xs text-muted-foreground">
                <p>
                    ตัวตั้งเวลาสำรองให้อัตโนมัติทุกวันเวลา {{ settings.time }}
                    <span v-if="!settings.enabled" class="font-semibold text-[var(--status-critical)]">
                        (ตอนนี้ปิดไว้ที่ BACKUP_ENABLED)
                    </span>
                </p>
                <p>
                    ไฟล์ฐานข้อมูลเขียนลง <span class="font-mono">{{ settings.sql_path }}</span>
                    <strong> บนเครื่อง SQL Server</strong> ไม่ใช่เครื่องที่ระบบนี้รันอยู่
                </p>
                <p>
                    ไฟล์ zip ของรูปและไฟล์บัญชีเขียนลง <span class="font-mono">{{ settings.files_path }}</span>
                </p>
                <p>
                    ไฟล์หมุนทับกันเอง — รายวัน 7 ไฟล์ (จันทร์–อาทิตย์) และรายเดือนอีก 12 ไฟล์
                    ย้อนได้ 7 วันล่าสุด กับสิ้นเดือนย้อนหลัง 1 ปี โดยไม่ต้องลบไฟล์เอง
                </p>
                <p v-if="staleCount" class="font-semibold text-[var(--status-critical)]">
                    ตอนนี้ขาดการสำรอง {{ staleCount }} รายการ (เกิน {{ settings.stale_hours }} ชั่วโมง)
                </p>
            </div>
        </SectionCard>

        <!-- ตัวตั้งเวลา -->
        <SectionCard title="ตัวตั้งเวลา">
            <div class="flex items-start gap-3">
                <component
                    :is="scheduler.is_down ? AlertTriangle : CheckCircle2"
                    class="mt-0.5 size-4 shrink-0"
                    :class="scheduler.is_down ? 'text-[var(--status-critical)]' : 'text-[var(--status-good)]'"
                />
                <div class="min-w-0 text-sm">
                    <p :class="scheduler.is_down ? 'font-semibold text-[var(--status-critical)]' : ''">
                        {{ scheduler.is_down ? 'หยุดทำงาน' : 'ทำงานปกติ' }}
                        <span v-if="scheduler.last_ping_at" class="font-normal text-muted-foreground">
                            — เคาะล่าสุด {{ dateTime(scheduler.last_ping_at) }}
                        </span>
                        <span v-else class="font-normal text-muted-foreground">
                            — ยังไม่เคยเคาะเลยตั้งแต่เริ่มระบบหรือล้างแคชครั้งล่าสุด
                        </span>
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        มันเคาะทุก 5 นาที เงียบเกิน {{ scheduler.stale_minutes }} นาทีถือว่าตาย
                        ถ้าตายแล้ว งานพิมพ์ที่ค้าง การส่งข้อมูลให้บัญชี และการสำรองข้อมูล จะหยุดพร้อมกัน
                        ทั้งที่หน้าเว็บยังใช้งานได้ตามปกติ — ตรวจ <span class="font-mono">program:schedule</span> ใน supervisor
                    </p>
                </div>
            </div>
        </SectionCard>

        <!-- ข้อผิดพลาด -->
        <SectionCard title="ข้อผิดพลาดของระบบ" content-class="p-0">
            <template #actions>
                <div class="flex gap-1">
                    <Link
                        href="/backoffice/health?errors=open"
                        preserve-scroll
                        class="rounded-md px-2.5 py-1 text-xs transition-colors"
                        :class="!showingAll ? 'bg-[var(--series-1)] text-white' : 'text-muted-foreground hover:bg-accent'"
                    >
                        ยังไม่ปิด ({{ openErrorCount }})
                    </Link>
                    <Link
                        href="/backoffice/health?errors=all"
                        preserve-scroll
                        class="rounded-md px-2.5 py-1 text-xs transition-colors"
                        :class="showingAll ? 'bg-[var(--series-1)] text-white' : 'text-muted-foreground hover:bg-accent'"
                    >
                        ทั้งหมด
                    </Link>
                </div>
            </template>

            <EmptyState
                v-if="!errors.data.length"
                title="ไม่มีข้อผิดพลาดค้างอยู่"
                description="ระบบเก็บเฉพาะความผิดพลาดของตัวระบบเอง ไม่เก็บกรณีที่ผู้ใช้กรอกไม่ครบหรือไม่มีสิทธิ์"
            />

            <div v-else class="divide-y">
                <div v-for="e in errors.data" :key="e.id" class="px-4 py-3">
                    <div class="flex flex-wrap items-start gap-2">
                        <Badge :variant="e.resolved_at ? 'secondary' : e.level === 'critical' ? 'danger' : 'warning'">
                            {{ e.resolved_at ? 'ปิดแล้ว' : e.level === 'critical' ? 'ร้ายแรง' : 'ผิดพลาด' }}
                        </Badge>
                        <span class="font-mono text-xs text-muted-foreground">{{ e.class }}</span>
                        <span class="ms-auto text-xs text-muted-foreground">
                            เกิด {{ number(e.occurrences) }} ครั้ง · ล่าสุด {{ dateTime(e.last_seen_at) }}
                        </span>
                    </div>

                    <p class="mt-1.5 break-words text-sm">{{ e.message }}</p>

                    <p class="mt-1 break-all font-mono text-[11px] text-muted-foreground">
                        {{ e.location }}
                        <span v-if="e.url"> · {{ e.method }} {{ e.url }}</span>
                    </p>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <Button
                            v-if="!e.resolved_at"
                            variant="outline"
                            size="sm"
                            @click="resolve(e.id)"
                        >
                            <CheckCircle2 />
                            ปิดเคส
                        </Button>
                        <Button v-else variant="ghost" size="sm" @click="reopen(e.id)">
                            <RotateCcw />
                            เปิดกลับมา
                        </Button>

                        <Button
                            v-if="e.trace"
                            variant="ghost"
                            size="sm"
                            @click="expanded = expanded === e.id ? null : e.id"
                        >
                            <component :is="expanded === e.id ? ChevronDown : ChevronRight" />
                            ดูที่มา
                        </Button>

                        <span v-if="e.first_seen_at" class="text-[11px] text-muted-foreground">
                            เจอครั้งแรก {{ dateTime(e.first_seen_at) }}
                        </span>
                    </div>

                    <pre
                        v-if="expanded === e.id && e.trace"
                        class="mt-2 max-h-72 overflow-auto rounded-md bg-muted p-3 text-[11px] leading-relaxed"
                    >{{ e.trace }}</pre>
                </div>
            </div>

            <Pagination :links="errors.links" :total="errors.total" />

            <p class="border-t px-4 py-3 text-xs text-muted-foreground">
                ปิดเคสแล้วแต่ยังเกิดซ้ำ ระบบจะเปิดกลับมาให้เองอัตโนมัติ
                เพราะบั๊กที่ยังทำงานผิดอยู่ไม่ควรหายไปจากสายตาเพราะมีคนกดปิด
            </p>
        </SectionCard>

        <!-- ประวัติการสำรอง -->
        <SectionCard title="ประวัติการสำรองล่าสุด" content-class="p-0">
            <EmptyState v-if="!runs.length" title="ยังไม่เคยสำรอง" />

            <div v-else class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="border-b bg-muted/40 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">เวลา</th>
                            <th class="px-4 py-2 font-medium">ชนิด</th>
                            <th class="px-4 py-2 font-medium">ช่อง</th>
                            <th class="px-4 py-2 font-medium">ผล</th>
                            <th class="px-4 py-2 text-right font-medium">ขนาด</th>
                            <th class="px-4 py-2 text-right font-medium">ใช้เวลา</th>
                            <th class="px-4 py-2 font-medium">สั่งโดย</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in runs" :key="r.id">
                            <td class="whitespace-nowrap px-4 py-2">{{ dateTime(r.finished_at) }}</td>
                            <td class="px-4 py-2">{{ r.kind_label }}</td>
                            <td class="px-4 py-2 font-mono">{{ r.slot }}</td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="
                                        r.status === 'success' ? 'success' : r.status === 'skipped' ? 'secondary' : 'danger'
                                    "
                                >
                                    {{ r.status === 'success' ? 'สำเร็จ' : r.status === 'skipped' ? 'ข้าม' : 'ล้มเหลว' }}
                                </Badge>
                                <span v-if="r.verified" class="ms-1 text-[10px] text-[var(--status-good)]">ตรวจแล้ว</span>
                                <p v-if="r.error" class="mt-1 max-w-md break-words text-[11px] text-muted-foreground">
                                    {{ r.error }}
                                </p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">{{ r.size }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">{{ r.duration }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">
                                <span class="inline-flex items-center gap-1">
                                    <Timer v-if="!r.by" class="size-3" />
                                    {{ r.by ?? 'ตัวตั้งเวลา' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </SectionCard>
    </BackOfficeLayout>
</template>
