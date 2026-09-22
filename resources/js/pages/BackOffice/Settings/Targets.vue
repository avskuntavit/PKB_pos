<script setup lang="ts">
/**
 * ตั้งเป้ายอดขายรายเดือน
 *
 * กรอกปีละ 12 ช่อง จบ — ระบบเกลี่ยเป็นเป้ารายวันให้เอง
 * ยอดจริงของปีนี้กับปีที่แล้วอยู่ข้าง ๆ ช่องกรอกโดยตั้งใจ
 * เพราะเป้าที่ตั้งโดยไม่ดูของเดิมมักกลายเป็นเลขกลม ๆ ที่ไม่มีใครเชื่อ
 */
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { Wand2 } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import { money, moneyCompact, number } from '@/lib/format'

interface MonthRow {
    month: number
    label: string
    target_amount: number
    food_cost_percent: number | null
    note: string | null
    actual: number
    last_year: number
    percent: number | null
    avg_per_day: number
}

const props = defineProps<{
    branch: { id: number; name: string }
    branches: Array<{ id: number; name: string }>
    year: number
    years: number[]
    months: MonthRow[]
    /** rank = อันดับวันขายดี 1-3 · null = ไม่ติด 3 อันดับ หรือยังไม่มีข้อมูลพอ */
    weekdayWeights: Array<{ weekday: number; label: string; percent: number; rank: number | null }>
    historyWeeks: number
    minHistoryDays: number
}>()

const form = useForm({
    year: props.year,
    branch_id: props.branch.id,
    months: props.months.map((m) => ({
        month: m.month,
        target_amount: m.target_amount,
        food_cost_percent: m.food_cost_percent,
        note: m.note ?? '',
    })),
})

// เปลี่ยนปี/สาขาแล้วโหลดใหม่ ต้องรีเซ็ตฟอร์มตามข้อมูลชุดใหม่
watch(
    () => props.months,
    (months) => {
        form.defaults({
            year: props.year,
            branch_id: props.branch.id,
            months: months.map((m) => ({
                month: m.month,
                target_amount: m.target_amount,
                food_cost_percent: m.food_cost_percent,
                note: m.note ?? '',
            })),
        })
        form.reset()
    },
)

const totalTarget = computed(() =>
    form.months.reduce((sum, m) => sum + Number(m.target_amount || 0), 0),
)

const totalLastYear = computed(() => props.months.reduce((sum, m) => sum + m.last_year, 0))

const growth = computed(() =>
    totalLastYear.value > 0
        ? Math.round(((totalTarget.value - totalLastYear.value) / totalLastYear.value) * 1000) / 10
        : null,
)

/* ---------- ตัวช่วยกรอก ---------- */

const growthInput = ref(10)

/** ตั้งเป้าทั้งปีจากยอดจริงปีที่แล้ว + เปอร์เซ็นต์การเติบโตที่อยากได้ */
function fillFromLastYear() {
    const factor = 1 + growthInput.value / 100

    props.months.forEach((m, index) => {
        // เดือนที่ปีที่แล้วไม่มียอด (ร้านยังไม่เปิด) อย่าเดาให้ ปล่อยให้คนกรอกเอง
        if (m.last_year <= 0) return

        form.months[index].target_amount = Math.round((m.last_year * factor) / 100) * 100
    })
}

function clearAll() {
    form.months.forEach((m) => (m.target_amount = 0))
}

function switchContext(patch: Record<string, number>) {
    router.get(
        '/backoffice/settings/targets',
        { year: props.year, branch_id: props.branch.id, ...patch },
        { preserveScroll: true },
    )
}

/** ช่องว่างต้องกลายเป็น null ไม่ใช่ 0 — 0% แปลว่า "ไม่มีต้นทุนเลย" ซึ่งคนละเรื่องกับ "ยังไม่ตั้ง" */
function optionalNumber(value: unknown): number | null {
    if (value === null || value === undefined || value === '') return null

    const n = Number(value)

    return Number.isFinite(n) ? n : null
}

function submit() {
    form.transform((data) => ({
        ...data,
        months: data.months.map((m) => ({
            ...m,
            target_amount: Number(m.target_amount || 0),
            food_cost_percent: optionalNumber(m.food_cost_percent),
            note: m.note || null,
        })),
    })).put('/backoffice/settings/targets', { preserveScroll: true })
}

/**
 * ไล่เฉดเขียวตามอันดับวันขายดี — เข้มสุดคืออันดับ 1
 *
 * ใช้ opacity ของสีเดียวแทนการไล่คนละสี เพราะสายตาอ่าน "เข้ม = มากกว่า" ได้เอง
 * โดยไม่ต้องมีคำอธิบายสี และยังอ่านออกทั้งโหมดสว่างและมืด
 *
 * ตัวเลขอันดับเขียนกำกับไว้ด้วยเสมอ ไม่ปล่อยให้สีเป็นตัวบอกความหมายอย่างเดียว
 * เพราะคนตาบอดสีเขียว-แดงราว 8% ของผู้ชายจะแยกเฉดพวกนี้ไม่ออก
 */
const rankClass: Record<number, string> = {
    1: 'border-[var(--status-good)] bg-[var(--status-good)] text-white',
    2: 'border-[var(--status-good)] bg-[var(--status-good)]/35',
    3: 'border-[var(--status-good)]/60 bg-[var(--status-good)]/15',
}

/** ยังไม่มีวันไหนติดอันดับ = ประวัติยังไม่พอ ระบบเกลี่ยเท่ากันอยู่ */
const estimated = computed(() => props.weekdayWeights.every((w) => w.rank === null))

const isCurrentYear = computed(() => props.year === new Date().getFullYear())
const currentMonth = computed(() => new Date().getMonth() + 1)
</script>

<template>
    <Head title="เป้ายอดขาย" />

    <BackOfficeLayout title="เป้ายอดขาย">
        <div class="flex flex-wrap items-end gap-3">
            <div class="space-y-1">
                <Label for="branch">สาขา</Label>
                <select
                    id="branch"
                    class="h-9 rounded-md border bg-card px-3 text-sm"
                    :value="branch.id"
                    @change="switchContext({ branch_id: Number(($event.target as HTMLSelectElement).value) })"
                >
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <div class="space-y-1">
                <Label for="year">ปี</Label>
                <select
                    id="year"
                    class="h-9 rounded-md border bg-card px-3 text-sm"
                    :value="year"
                    @change="switchContext({ year: Number(($event.target as HTMLSelectElement).value) })"
                >
                    <option v-for="y in years" :key="y" :value="y">{{ y + 543 }} ({{ y }})</option>
                </select>
            </div>

            <div class="ml-auto text-right">
                <p class="text-xs text-muted-foreground">เป้าทั้งปี</p>
                <p class="tabular text-xl font-semibold">{{ money(totalTarget) }} บาท</p>
                <p v-if="growth !== null" class="text-xs text-muted-foreground">
                    ปีที่แล้วขายได้ {{ moneyCompact(totalLastYear) }} ·
                    <span :class="growth >= 0 ? 'text-[var(--status-good)]' : 'text-[var(--status-critical)]'">
                        {{ growth >= 0 ? '+' : '' }}{{ growth }}%
                    </span>
                </p>
            </div>
        </div>

        <!-- ตัวช่วยกรอก -->
        <div class="flex flex-wrap items-end gap-3 rounded-lg border bg-card p-4">
            <Wand2 class="mb-2 size-4 shrink-0 text-muted-foreground" />
            <div class="space-y-1">
                <Label for="growth">ตั้งจากยอดจริงปีที่แล้ว + โต (%)</Label>
                <Input id="growth" v-model.number="growthInput" type="number" step="1" class="h-9 w-28" />
            </div>
            <Button variant="outline" size="default" @click="fillFromLastYear">เติมให้ทั้งปี</Button>
            <Button variant="ghost" size="default" @click="clearAll">ล้างทั้งปี</Button>
            <p class="text-xs text-muted-foreground">
                เดือนที่ปีที่แล้วไม่มียอดขายจะถูกข้าม ให้กรอกเอง
            </p>
        </div>

        <SectionCard title="เป้ารายเดือน" content-class="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-xs text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">เดือน</th>
                            <th class="px-3 py-2 text-right font-medium">เป้ายอดขาย (บาท)</th>
                            <th class="px-3 py-2 text-right font-medium">เฉลี่ย/วัน</th>
                            <th class="px-3 py-2 text-right font-medium">ยอดจริง {{ year + 543 }}</th>
                            <th class="px-3 py-2 text-right font-medium">ยอดจริง {{ year + 542 }}</th>
                            <th class="px-3 py-2 text-right font-medium">เป้าต้นทุน (%)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="(row, index) in months"
                            :key="row.month"
                            :class="
                                isCurrentYear && row.month === currentMonth
                                    ? 'bg-[var(--series-1)]/5'
                                    : ''
                            "
                        >
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ row.label }}</span>
                                <span
                                    v-if="isCurrentYear && row.month === currentMonth"
                                    class="ml-2 rounded-full bg-[var(--series-1)]/15 px-2 py-0.5 text-xs text-[var(--series-1)]"
                                >
                                    เดือนนี้
                                </span>
                            </td>

                            <td class="px-3 py-2 text-right">
                                <Input
                                    v-model.number="form.months[index].target_amount"
                                    type="number"
                                    min="0"
                                    step="100"
                                    class="tabular h-9 w-40 text-right"
                                    :aria-label="`เป้ายอดขายเดือน${row.label}`"
                                />
                            </td>

                            <td class="tabular px-3 py-2 text-right text-muted-foreground">
                                {{
                                    form.months[index].target_amount > 0
                                        ? moneyCompact(
                                              form.months[index].target_amount /
                                                  new Date(year, row.month, 0).getDate(),
                                          )
                                        : '—'
                                }}
                            </td>

                            <td class="tabular px-3 py-2 text-right">
                                <span :class="row.actual > 0 ? '' : 'text-muted-foreground'">
                                    {{ row.actual > 0 ? money(row.actual) : '—' }}
                                </span>
                                <span
                                    v-if="row.percent !== null && row.actual > 0"
                                    class="ml-1 text-xs"
                                    :class="
                                        row.percent >= 100
                                            ? 'text-[var(--status-good)]'
                                            : 'text-[var(--status-warning)]'
                                    "
                                >
                                    {{ row.percent }}%
                                </span>
                            </td>

                            <td class="tabular px-3 py-2 text-right text-muted-foreground">
                                {{ row.last_year > 0 ? money(row.last_year) : '—' }}
                            </td>

                            <td class="px-3 py-2 text-right">
                                <Input
                                    v-model.number="form.months[index].food_cost_percent"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.5"
                                    placeholder="—"
                                    class="tabular h-9 w-24 text-right"
                                    :aria-label="`เป้าต้นทุนวัตถุดิบเดือน${row.label}`"
                                />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/40 font-medium">
                        <tr>
                            <td class="px-3 py-2">รวมทั้งปี</td>
                            <td class="tabular px-3 py-2 text-right">{{ money(totalTarget) }}</td>
                            <td colspan="4" />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </SectionCard>

        <div class="flex items-center gap-3">
            <Button variant="brand" size="lg" :disabled="form.processing" @click="submit">
                {{ form.processing ? 'กำลังบันทึก...' : 'บันทึกเป้า' }}
            </Button>
            <p v-if="form.isDirty" class="text-xs text-[var(--status-warning)]">ยังไม่ได้บันทึก</p>
        </div>

        <SectionCard title="ระบบเกลี่ยเป้าเป็นรายวันยังไง">
            <p class="text-sm text-muted-foreground">
                ไม่ได้เอาเป้าเดือนหารจำนวนวัน แต่ถ่วงน้ำหนักตามวันในสัปดาห์
                โดยดูจากยอดขายจริงย้อนหลัง {{ historyWeeks }} สัปดาห์ของสาขานี้
                วันที่ปกติขายดีจะได้รับเป้าสูงกว่า เพื่อไม่ให้วันอังคารดูพลาดเป้าตลอด
                และวันเสาร์ดูเข้าเป้าตลอดทั้งที่ทั้งคู่ขายได้ตามปกติ
            </p>

            <ul class="mt-3 grid gap-2 sm:grid-cols-7">
                <li
                    v-for="w in weekdayWeights"
                    :key="w.weekday"
                    class="relative rounded-lg border p-2 text-center transition-colors"
                    :class="w.rank ? rankClass[w.rank] : ''"
                >
                    <!-- เลขอันดับกำกับ เผื่อคนที่แยกเฉดเขียวไม่ออก -->
                    <span
                        v-if="w.rank"
                        class="absolute left-1 top-1 grid size-4 place-items-center rounded-full text-[0.625rem] font-bold"
                        :class="w.rank === 1 ? 'bg-white/25 text-white' : 'bg-[var(--status-good)] text-white'"
                        :aria-label="`ขายดีอันดับ ${w.rank}`"
                    >
                        {{ w.rank }}
                    </span>

                    <p class="text-xs" :class="w.rank === 1 ? 'text-white/80' : 'text-muted-foreground'">
                        {{ w.label }}
                    </p>
                    <p class="tabular text-base font-semibold">{{ number(w.percent, 1) }}%</p>
                </li>
            </ul>

            <p v-if="estimated" class="mt-2 text-xs text-muted-foreground">
                สาขานี้ยังขายไม่ถึง {{ minHistoryDays }} วัน ระบบจึงเกลี่ยเท่ากันทุกวัน (14.3% ต่อวัน)
                และยังบอกไม่ได้ว่าวันไหนขายดี — พอมีข้อมูลพอแล้วตัวเลขจะขยับเอง
            </p>
            <p v-else class="mt-2 text-xs text-muted-foreground">
                <span class="font-medium text-[var(--status-good)]">แถบสีเขียว</span>
                คือ 3 วันที่ขายดีที่สุด เข้มสุดคือดีที่สุด — วันพวกนี้จะได้รับเป้าสูงกว่าวันอื่นโดยอัตโนมัติ
            </p>
        </SectionCard>
    </BackOfficeLayout>
</template>
