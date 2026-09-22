<script setup lang="ts">
/**
 * เครื่องพิมพ์และคิวงานพิมพ์
 *
 * รวมสองเรื่องไว้หน้าเดียวโดยตั้งใจ — เวลากระดาษไม่ออก คนจะมาที่นี่แล้วอยากเห็น
 * พร้อมกันว่าตั้งค่าไว้ยังไง และใบไหนค้างเพราะอะไร ถ้าแยกหน้าต้องสลับไปมา
 * ตอนที่กำลังรีบที่สุด
 */
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { CircleAlert, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'

interface PrinterRow {
    id: number
    name: string
    host: string | null
    port: number
    columns: number
    print_groups: number[]
    prints_receipt: boolean
    opens_cash_drawer: boolean
    copies: number
    is_active: boolean
    sort_order: number
    is_configured: boolean
}

interface JobRow {
    id: number
    kind: string
    kind_label: string
    title: string | null
    printer: string | null
    status: string
    attempts: number
    max_attempts: number
    last_error: string | null
    printed_at: string | null
    created_at: string | null
    can_retry: boolean
}

const props = defineProps<{
    printers: PrinterRow[]
    jobs: JobRow[]
    stuck: number
    printGroups: Array<{ value: number; label: string }>
}>()

/* ---------- ฟอร์มเครื่องพิมพ์ ---------- */

const showForm = ref(false)
const editing = ref<PrinterRow | null>(null)
const confirmingDelete = ref(false)

const form = useForm({
    name: '',
    host: '',
    port: 9100,
    columns: 48,
    print_groups: [] as number[],
    prints_receipt: false,
    opens_cash_drawer: false,
    copies: 1,
    is_active: true,
    sort_order: 0,
})

function openCreate() {
    editing.value = null
    confirmingDelete.value = false
    form.clearErrors()
    form.defaults({
        name: '',
        host: '',
        port: 9100,
        columns: 48,
        print_groups: [],
        // เครื่องแรกที่ร้านซื้อมักตั้งไว้ที่เคาน์เตอร์ ออกใบเสร็จเป็นหลัก
        prints_receipt: props.printers.length === 0,
        opens_cash_drawer: false,
        copies: 1,
        is_active: true,
        sort_order: props.printers.length,
    })
    form.reset()
    showForm.value = true
}

function openEdit(p: PrinterRow) {
    editing.value = p
    confirmingDelete.value = false
    form.clearErrors()
    form.defaults({
        name: p.name,
        host: p.host ?? '',
        port: p.port,
        columns: p.columns,
        print_groups: [...p.print_groups],
        prints_receipt: p.prints_receipt,
        opens_cash_drawer: p.opens_cash_drawer,
        copies: p.copies,
        is_active: p.is_active,
        sort_order: p.sort_order,
    })
    form.reset()
    showForm.value = true
}

function submit() {
    const target = editing.value
    const done = { preserveScroll: true, onSuccess: () => (showForm.value = false) }

    if (target) {
        form.put(`/backoffice/printers/${target.id}`, done)
    } else {
        form.post('/backoffice/printers', done)
    }
}

function destroy() {
    if (!editing.value) return

    router.delete(`/backoffice/printers/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (showForm.value = false),
    })
}

function toggleGroup(value: number) {
    form.print_groups = form.print_groups.includes(value)
        ? form.print_groups.filter((g) => g !== value)
        : [...form.print_groups, value]
}

/* ---------- คิวงานพิมพ์ ---------- */

function test(p: PrinterRow) {
    router.post(`/backoffice/printers/${p.id}/test`, {}, { preserveScroll: true })
}

function retry(job: JobRow) {
    router.post(`/backoffice/print-jobs/${job.id}/retry`, {}, { preserveScroll: true })
}

function retryAll() {
    router.post('/backoffice/print-jobs/retry-all', {}, { preserveScroll: true })
}

const statusBadge: Record<string, { variant: string; label: string }> = {
    pending: { variant: 'warning', label: 'รอพิมพ์' },
    printing: { variant: 'default', label: 'กำลังพิมพ์' },
    done: { variant: 'success', label: 'พิมพ์แล้ว' },
    failed: { variant: 'danger', label: 'ไม่สำเร็จ' },
}

const groupLabel = (value: number) => props.printGroups.find((g) => g.value === value)?.label ?? String(value)

/** สรุปหน้าที่ของเครื่องหนึ่งเป็นข้อความเดียว ไม่งั้นตารางจะมีคอลัมน์ติ๊กถูกเต็มไปหมด */
function roles(p: PrinterRow): string {
    const bits = p.print_groups.map(groupLabel)

    if (p.prints_receipt) bits.push('ใบเสร็จ')
    if (p.opens_cash_drawer) bits.push('ลิ้นชัก')

    return bits.length ? bits.join(' · ') : 'ยังไม่ได้กำหนดหน้าที่'
}

const noPrinters = computed(() => props.printers.length === 0)
</script>

<template>
    <Head title="เครื่องพิมพ์" />

    <BackOfficeLayout title="เครื่องพิมพ์">
        <!-- ══ เครื่องพิมพ์ ══ -->
        <SectionCard title="เครื่องพิมพ์ของสาขานี้" content-class="p-0">
            <template #actions>
                <Button variant="brand" size="sm" @click="openCreate">
                    <Plus />
                    เพิ่มเครื่องพิมพ์
                </Button>
            </template>

            <p class="border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
                เครื่องพิมพ์ต้องอยู่ในวงเน็ตเวิร์กเดียวกับเครื่องที่รันระบบ —
                หมายเลขไอพีดูได้จากใบ self-test โดยกดปุ่ม FEED ค้างไว้แล้วเปิดเครื่อง
            </p>

            <DataTable v-if="!noPrinters">
                <thead>
                    <tr>
                        <th>ชื่อ</th>
                        <th>ที่อยู่</th>
                        <th>หน้าที่</th>
                        <th>กระดาษ</th>
                        <th>สถานะ</th>
                        <th class="text-right">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in printers" :key="p.id">
                        <td class="font-medium">{{ p.name }}</td>
                        <td class="tabular text-muted-foreground">
                            {{ p.host ?? '-' }}<span v-if="p.host">:{{ p.port }}</span>
                        </td>
                        <td class="text-muted-foreground">{{ roles(p) }}</td>
                        <td class="text-muted-foreground">{{ p.columns === 32 ? '58 มม.' : '80 มม.' }}</td>
                        <td>
                            <Badge v-if="!p.is_active" variant="secondary">ปิดใช้งาน</Badge>
                            <Badge v-else-if="!p.is_configured" variant="warning">ยังไม่ได้ตั้งไอพี</Badge>
                            <Badge v-else variant="success">พร้อมใช้</Badge>
                        </td>
                        <td>
                            <div class="flex justify-end gap-1">
                                <Button variant="outline" size="sm" :disabled="!p.is_configured" @click="test(p)">
                                    พิมพ์ทดสอบ
                                </Button>
                                <Button variant="ghost" size="icon" aria-label="แก้ไข" @click="openEdit(p)">
                                    <Pencil />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </DataTable>

            <EmptyState
                v-else
                title="ยังไม่ได้ตั้งเครื่องพิมพ์"
                description="ระบบยังขายได้ตามปกติ แค่ต้องบอกครัวเองและยังไม่มีใบเสร็จออกจากเครื่อง"
            />
        </SectionCard>

        <!-- ══ คิวงานพิมพ์ ══ -->
        <SectionCard title="งานพิมพ์ล่าสุด" content-class="p-0" class="mt-4">
            <template #actions>
                <Button v-if="stuck > 0" variant="brand" size="sm" @click="retryAll">
                    <RefreshCw />
                    ลองพิมพ์ใหม่ทั้งหมด ({{ stuck }})
                </Button>
            </template>

            <p
                v-if="stuck > 0"
                class="flex items-start gap-2 border-b border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 px-4 py-2 text-sm"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0 text-[var(--status-critical)]" />
                <span>
                    มี {{ stuck }} ใบที่พิมพ์ไม่สำเร็จ
                    <span class="block text-xs text-muted-foreground">
                        เช็กกระดาษ ฝาเครื่อง และสายแลนก่อน แล้วค่อยกดลองพิมพ์ใหม่
                    </span>
                </span>
            </p>

            <DataTable v-if="jobs.length">
                <thead>
                    <tr>
                        <th>ประเภท</th>
                        <th>รายละเอียด</th>
                        <th>เครื่อง</th>
                        <th>เวลา</th>
                        <th>สถานะ</th>
                        <th class="text-right">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="j in jobs" :key="j.id">
                        <td>{{ j.kind_label }}</td>
                        <td>
                            {{ j.title ?? '-' }}
                            <span v-if="j.last_error" class="block text-xs text-[var(--status-critical)]">
                                {{ j.last_error }}
                            </span>
                        </td>
                        <td class="text-muted-foreground">{{ j.printer ?? '-' }}</td>
                        <td class="text-muted-foreground">{{ j.printed_at ?? j.created_at ?? '-' }}</td>
                        <td>
                            <Badge :variant="(statusBadge[j.status]?.variant as any) ?? 'secondary'">
                                {{ statusBadge[j.status]?.label ?? j.status }}
                            </Badge>
                            <span v-if="j.attempts > 1" class="ms-1 text-xs text-muted-foreground">
                                ลอง {{ j.attempts }}/{{ j.max_attempts }} ครั้ง
                            </span>
                        </td>
                        <td>
                            <div class="flex justify-end">
                                <Button v-if="j.can_retry" variant="outline" size="sm" @click="retry(j)">
                                    พิมพ์ใหม่
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </DataTable>

            <EmptyState v-else description="ยังไม่มีงานพิมพ์" />
        </SectionCard>

        <!-- ══ ฟอร์ม ══ -->
        <Modal
            v-model:open="showForm"
            :title="editing ? 'แก้ไขเครื่องพิมพ์' : 'เพิ่มเครื่องพิมพ์'"
            class="max-h-[90dvh] max-w-xl overflow-y-auto"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="pname">ชื่อเครื่อง</Label>
                        <Input id="pname" v-model="form.name" placeholder="เครื่องครัว" required />
                        <p v-if="form.errors.name" class="text-xs text-[var(--status-critical)]">
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div class="space-y-1">
                        <Label for="pcolumns">ขนาดกระดาษ</Label>
                        <Select id="pcolumns" v-model="form.columns">
                            <option :value="48">80 มม. (48 ตัวอักษร)</option>
                            <option :value="42">80 มม. ตัวเล็ก (42 ตัวอักษร)</option>
                            <option :value="32">58 มม. (32 ตัวอักษร)</option>
                        </Select>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-[1fr_8rem]">
                    <div class="space-y-1">
                        <Label for="phost">หมายเลขไอพี</Label>
                        <Input id="phost" v-model="form.host" placeholder="192.168.1.50" required />
                        <p v-if="form.errors.host" class="text-xs text-[var(--status-critical)]">
                            {{ form.errors.host }}
                        </p>
                    </div>
                    <div class="space-y-1">
                        <Label for="pport">พอร์ต</Label>
                        <Input id="pport" v-model="form.port" type="number" min="1" max="65535" />
                        <p class="text-xs text-muted-foreground">ปกติ 9100</p>
                    </div>
                </div>

                <div class="space-y-2">
                    <Label>ใบสั่งครัวที่เครื่องนี้รับ</Label>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="g in printGroups"
                            :key="g.value"
                            type="button"
                            class="rounded-full border px-3 py-1 text-sm transition-colors"
                            :class="
                                form.print_groups.includes(g.value)
                                    ? 'border-[var(--series-1)] bg-[var(--series-1)]/10 font-medium text-[var(--series-1)]'
                                    : 'border-border hover:bg-accent'
                            "
                            @click="toggleGroup(g.value)"
                        >
                            {{ g.label }}
                        </button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        ไม่เลือกเลย = เครื่องนี้ไม่รับใบสั่งครัว (ใช้ออกใบเสร็จอย่างเดียว)
                    </p>
                </div>

                <div class="space-y-2">
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.prints_receipt" type="checkbox" class="mt-0.5 size-4" />
                        <span>
                            ออกใบเสร็จที่เครื่องนี้
                            <span class="block text-xs text-muted-foreground">
                                ติ๊กได้หลายเครื่อง ใบเสร็จจะออกทุกเครื่องที่ติ๊กไว้
                            </span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.opens_cash_drawer" type="checkbox" class="mt-0.5 size-4" />
                        <span>
                            ลิ้นชักเก็บเงินต่ออยู่ที่เครื่องนี้
                            <span class="block text-xs text-muted-foreground">
                                ลิ้นชักเปิดเฉพาะบิลที่มีเงินสด — จ่ายพร้อมเพย์หรือบัตรไม่เปิด
                            </span>
                        </span>
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="pcopies">พิมพ์กี่ใบต่องาน</Label>
                        <Input id="pcopies" v-model="form.copies" type="number" min="1" max="5" />
                    </div>
                    <div class="space-y-1">
                        <Label for="psort">ลำดับ</Label>
                        <Input id="psort" v-model="form.sort_order" type="number" min="0" />
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="size-4" />
                    เปิดใช้งานเครื่องนี้
                </label>

                <div class="flex items-center gap-2 pt-1">
                    <template v-if="editing">
                        <Button
                            v-if="!confirmingDelete"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="confirmingDelete = true"
                        >
                            <Trash2 />
                            ลบ
                        </Button>
                        <template v-else>
                            <span class="text-sm text-[var(--status-critical)]">ลบจริงไหม</span>
                            <Button type="button" variant="destructive" size="sm" @click="destroy">ยืนยันลบ</Button>
                            <Button type="button" variant="ghost" size="sm" @click="confirmingDelete = false">
                                ไม่ลบ
                            </Button>
                        </template>
                    </template>

                    <div class="ms-auto flex gap-2">
                        <Button type="button" variant="outline" @click="showForm = false">ยกเลิก</Button>
                        <Button type="submit" variant="brand" :disabled="form.processing">
                            {{ form.processing ? 'กำลังบันทึก…' : 'บันทึก' }}
                        </Button>
                    </div>
                </div>
            </form>
        </Modal>
    </BackOfficeLayout>
</template>
