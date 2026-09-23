<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { ArrowLeftRight, Ban, PackageCheck, Plus, Trash2 } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Pagination from '@/components/ui/Pagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Modal from '@/components/ui/Modal.vue'
import Select from '@/components/ui/Select.vue'
import { dateTime, money, number } from '@/lib/format'
import type { Option, Paginated } from '@/types'

interface TransferLine {
    id: number
    name: string | null
    unit: string | null
    qty_sent: number
    /** null = ปลายทางยังไม่ได้กดรับ (ต่างจาก 0 ที่แปลว่ารับแล้วแต่ไม่ได้ของ) */
    qty_received: number | null
    shortfall: number
    unit_cost: number
}

interface Transfer {
    id: number
    ref_no: string
    status: string
    status_label: string
    status_badge: string
    from: string | null
    to: string | null
    note: string | null
    cancel_reason: string | null
    sent_by: string | null
    sent_at: string | null
    received_by: string | null
    received_at: string | null
    business_date: string | null
    value: number
    has_shortfall: boolean
    can_receive: boolean
    can_cancel: boolean
    items: TransferLine[]
}

interface SendableItem {
    id: number
    name: string
    unit: string
    on_hand: number
}

const props = defineProps<{
    transfers: Paginated<Transfer>
    direction: 'in' | 'out'
    statuses: Option[]
    filters: Record<string, any>
    destinations: Array<{ id: number; name: string; code: string }>
    stockItems: SendableItem[]
    pendingIn: number
}>()

function badgeVariant(name: string): 'default' | 'secondary' | 'success' | 'warning' | 'danger' | 'outline' {
    switch (name) {
        case 'success':
            return 'success'
        case 'warning':
            return 'warning'
        case 'secondary':
            return 'secondary'
        default:
            return 'default'
    }
}

function go(params: Record<string, string | undefined>) {
    router.get('/backoffice/stock-transfers', { ...props.filters, ...params }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

/* ---------- ส่งของออก ---------- */

interface DraftLine {
    stock_item_id: number | null
    qty: string
}

const sendOpen = ref(false)

const sendForm = useForm({
    to_branch_id: null as number | null,
    note: '',
    items: [{ stock_item_id: null, qty: '' }] as DraftLine[],
})

function openSend() {
    sendForm.reset()
    sendForm.clearErrors()
    sendForm.to_branch_id = props.destinations[0]?.id ?? null
    sendForm.items = [{ stock_item_id: null, qty: '' }]
    sendOpen.value = true
}

function addLine() {
    sendForm.items.push({ stock_item_id: null, qty: '' })
}

function removeLine(index: number) {
    sendForm.items.splice(index, 1)

    if (sendForm.items.length === 0) addLine()
}

/** ยอดคงเหลือของบรรทัดนั้น ไว้โชว์ใต้ช่องกรอกจะได้ไม่ต้องเปิดหน้าคลังอีกจอ */
function onHand(line: DraftLine): SendableItem | null {
    return props.stockItems.find((item) => item.id === Number(line.stock_item_id)) ?? null
}

const overDrawn = computed(() =>
    sendForm.items.some((line) => {
        const item = onHand(line)
        return item !== null && line.qty !== '' && Number(line.qty) > item.on_hand
    }),
)

function submitSend() {
    sendForm
        .transform((data) => ({
            ...data,
            // ตัดบรรทัดว่างทิ้งก่อนส่ง ฝั่งเซิร์ฟเวอร์รวมบรรทัดซ้ำให้เองอีกชั้น
            items: data.items.filter((line) => line.stock_item_id && Number(line.qty) > 0),
        }))
        .post('/backoffice/stock-transfers', {
            preserveScroll: true,
            onSuccess: () => {
                sendOpen.value = false
            },
        })
}

/* ---------- รับของ ---------- */

const receiving = ref<Transfer | null>(null)

const receiveForm = useForm({
    received: {} as Record<number, string>,
    note: '',
})

function openReceive(transfer: Transfer) {
    receiving.value = transfer
    receiveForm.clearErrors()
    // เติมจำนวนที่ส่งมาไว้ให้ก่อน กรณีปกติคือรับครบ กดปุ่มเดียวจบ
    receiveForm.received = Object.fromEntries(
        transfer.items.map((line) => [line.id, String(line.qty_sent)]),
    )
    receiveForm.note = ''
}

function submitReceive() {
    if (!receiving.value) return

    receiveForm.post(`/backoffice/stock-transfers/${receiving.value.id}/receive`, {
        preserveScroll: true,
        onSuccess: () => {
            receiving.value = null
        },
    })
}

/* ---------- ยกเลิก ---------- */

const cancelling = ref<Transfer | null>(null)
const cancelForm = useForm({ reason: '' })

function openCancel(transfer: Transfer) {
    cancelling.value = transfer
    cancelForm.reset()
    cancelForm.clearErrors()
}

function submitCancel() {
    if (!cancelling.value) return

    cancelForm.post(`/backoffice/stock-transfers/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null
        },
    })
}
</script>

<template>
    <Head title="โอนของข้ามสถานี" />

    <BackOfficeLayout title="โอนของข้ามสถานี">
        <div class="space-y-4">
            <SectionCard title="ใบโอน">
                <template #actions>
                    <Button v-if="direction === 'out'" size="sm" :disabled="destinations.length === 0" @click="openSend">
                        <Plus />
                        ส่งของไปสถานีอื่น
                    </Button>
                </template>

                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex rounded-lg border p-0.5">
                            <button
                                type="button"
                                class="rounded-md px-3 py-1.5 text-xs font-medium"
                                :class="direction === 'out' ? 'bg-accent' : 'text-muted-foreground'"
                                @click="go({ direction: 'out' })"
                            >
                                ขาออก
                            </button>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium"
                                :class="direction === 'in' ? 'bg-accent' : 'text-muted-foreground'"
                                @click="go({ direction: 'in' })"
                            >
                                ขาเข้า
                                <Badge v-if="pendingIn > 0" variant="warning">{{ pendingIn }}</Badge>
                            </button>
                        </div>

                        <Select
                            class="w-auto min-w-40"
                            :model-value="filters.status ?? ''"
                            @update:model-value="(value: any) => go({ status: value || undefined })"
                        >
                            <option value="">ทุกสถานะ</option>
                            <option v-for="status in statuses" :key="status.value" :value="status.value">
                                {{ status.label }}
                            </option>
                        </Select>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        ของถูกตัดออกจากสถานีต้นทางทันทีที่กดส่ง และเข้าสต๊อกปลายทางเมื่อปลายทางกดรับ
                        ระหว่างนั้นของไม่ได้อยู่ในสต๊อกของใครเลย ซึ่งตรงกับความจริงว่ามันอยู่บนรถ
                    </p>
                </div>
            </SectionCard>

            <EmptyState
                v-if="transfers.data.length === 0"
                title="ยังไม่มีใบโอน"
                :description="direction === 'out' ? 'กดปุ่มส่งของไปสถานีอื่นเพื่อเริ่ม' : 'ยังไม่มีสถานีไหนส่งของมาให้'"
            />

            <div v-else class="space-y-3">
                <div v-for="transfer in transfers.data" :key="transfer.id" class="rounded-xl border bg-background">
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b px-4 py-3">
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-mono text-sm font-semibold">{{ transfer.ref_no }}</span>
                                <Badge :variant="badgeVariant(transfer.status_badge)">{{ transfer.status_label }}</Badge>
                                <Badge v-if="transfer.has_shortfall" variant="danger">รับได้ไม่ครบ</Badge>
                            </div>
                            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                {{ transfer.from }}
                                <ArrowLeftRight class="size-3.5" />
                                {{ transfer.to }}
                            </p>
                        </div>

                        <div class="text-right text-xs text-muted-foreground">
                            <p>มูลค่า {{ money(transfer.value) }}</p>
                            <p v-if="transfer.sent_at">ส่ง {{ dateTime(transfer.sent_at) }} · {{ transfer.sent_by }}</p>
                            <p v-if="transfer.received_at">
                                รับ {{ dateTime(transfer.received_at) }} · {{ transfer.received_by }}
                            </p>
                        </div>
                    </div>

                    <table class="w-full text-sm">
                        <thead class="text-xs text-muted-foreground">
                            <tr class="border-b">
                                <th class="px-4 py-2 text-left font-medium">ของ</th>
                                <th class="px-4 py-2 text-right font-medium">ส่ง</th>
                                <th class="px-4 py-2 text-right font-medium">รับ</th>
                                <th class="px-4 py-2 text-right font-medium">ขาด</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="line in transfer.items" :key="line.id">
                                <td class="px-4 py-2">{{ line.name ?? '(ถูกลบแล้ว)' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ number(line.qty_sent, 3) }} {{ line.unit }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    <span v-if="line.qty_received === null" class="text-muted-foreground">—</span>
                                    <span v-else>{{ number(line.qty_received, 3) }} {{ line.unit }}</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    <span v-if="line.shortfall > 0" class="text-[var(--status-critical)]">
                                        {{ number(line.shortfall, 3) }}
                                    </span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div
                        v-if="transfer.note || transfer.cancel_reason || transfer.can_receive || transfer.can_cancel"
                        class="flex flex-wrap items-center justify-between gap-2 border-t px-4 py-2.5"
                    >
                        <p class="min-w-0 flex-1 text-xs text-muted-foreground">
                            <span v-if="transfer.note">{{ transfer.note }}</span>
                            <span v-if="transfer.cancel_reason" class="text-[var(--status-critical)]">
                                เหตุผลที่ยกเลิก: {{ transfer.cancel_reason }}
                            </span>
                        </p>

                        <div class="flex shrink-0 gap-1.5">
                            <Button v-if="transfer.can_receive" size="sm" @click="openReceive(transfer)">
                                <PackageCheck />
                                รับของ
                            </Button>
                            <Button v-if="transfer.can_cancel" variant="outline" size="sm" @click="openCancel(transfer)">
                                <Ban />
                                ยกเลิก
                            </Button>
                        </div>
                    </div>
                </div>

                <Pagination :links="transfers.links" :total="transfers.total" />
            </div>
        </div>

        <!-- ส่งของไปสถานีอื่น -->
        <Modal v-model:open="sendOpen" title="ส่งของไปสถานีอื่น" class="max-w-2xl">
            <form class="space-y-4" @submit.prevent="submitSend">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="to-branch">สถานีปลายทาง</Label>
                        <Select id="to-branch" v-model="sendForm.to_branch_id">
                            <option v-for="branch in destinations" :key="branch.id" :value="branch.id">
                                {{ branch.name }}
                            </option>
                        </Select>
                        <p v-if="sendForm.errors.to_branch_id" class="text-xs text-[var(--status-critical)]">
                            {{ sendForm.errors.to_branch_id }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <Label for="transfer-note">หมายเหตุ</Label>
                        <Input id="transfer-note" v-model="sendForm.note" maxlength="255" placeholder="เช่น ฝากรถส่งของรอบเช้า" />
                    </div>
                </div>

                <div class="space-y-2">
                    <Label>รายการของ</Label>

                    <div v-for="(line, index) in sendForm.items" :key="index" class="flex items-start gap-2">
                        <div class="min-w-0 flex-1 space-y-1">
                            <Select v-model="line.stock_item_id">
                                <option :value="null">เลือกของ</option>
                                <option v-for="item in stockItems" :key="item.id" :value="item.id">
                                    {{ item.name }}
                                </option>
                            </Select>
                            <p v-if="onHand(line)" class="text-[11px] text-muted-foreground">
                                มีอยู่ {{ number(onHand(line)!.on_hand, 3) }} {{ onHand(line)!.unit }}
                            </p>
                        </div>

                        <div class="w-32 space-y-1">
                            <Input v-model="line.qty" type="number" step="0.001" min="0" placeholder="จำนวน" />
                            <p
                                v-if="onHand(line) && line.qty !== '' && Number(line.qty) > onHand(line)!.on_hand"
                                class="text-[11px] text-[var(--status-critical)]"
                            >
                                เกินที่มีอยู่
                            </p>
                        </div>

                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="เอาบรรทัดนี้ออก"
                            @click="removeLine(index)"
                        >
                            <Trash2 />
                        </Button>
                    </div>

                    <Button type="button" variant="outline" size="sm" @click="addLine">
                        <Plus />
                        เพิ่มบรรทัด
                    </Button>

                    <p v-if="sendForm.errors.items" class="text-xs text-[var(--status-critical)]">
                        {{ sendForm.errors.items }}
                    </p>
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" @click="sendOpen = false">ยกเลิก</Button>
                    <Button type="submit" :disabled="sendForm.processing || overDrawn">ส่งของ</Button>
                </div>
            </form>
        </Modal>

        <!-- ปลายทางกดรับ -->
        <Modal
            :open="receiving !== null"
            :title="receiving ? `รับของตามใบ ${receiving.ref_no}` : ''"
            description="กรอกจำนวนที่รับได้จริง ถ้าน้อยกว่าที่ส่งมา ส่วนต่างจะถูกบันทึกเป็นของที่หายระหว่างทาง"
            class="max-w-xl"
            @update:open="(value: boolean) => { if (!value) receiving = null }"
        >
            <form v-if="receiving" class="space-y-4" @submit.prevent="submitReceive">
                <div class="space-y-2">
                    <div v-for="line in receiving.items" :key="line.id" class="flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm">{{ line.name ?? '(ถูกลบแล้ว)' }}</p>
                            <p class="text-[11px] text-muted-foreground">
                                ส่งมา {{ number(line.qty_sent, 3) }} {{ line.unit }}
                            </p>
                        </div>
                        <Input
                            v-model="receiveForm.received[line.id]"
                            type="number"
                            step="0.001"
                            min="0"
                            :max="line.qty_sent"
                            class="w-32"
                        />
                    </div>
                </div>

                <div class="space-y-1">
                    <Label for="receive-note">หมายเหตุ</Label>
                    <Input id="receive-note" v-model="receiveForm.note" maxlength="255" />
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" @click="receiving = null">ปิด</Button>
                    <Button type="submit" :disabled="receiveForm.processing">รับเข้าสต๊อก</Button>
                </div>
            </form>
        </Modal>

        <!-- ยกเลิกใบโอน -->
        <Modal
            :open="cancelling !== null"
            :title="cancelling ? `ยกเลิกใบ ${cancelling.ref_no}` : ''"
            description="ของจะกลับเข้าสต๊อกต้นทางเต็มจำนวน ใช้กับกรณีที่ของยังไม่ได้ออกจริง หรือถูกตีกลับมาทั้งชุด"
            @update:open="(value: boolean) => { if (!value) cancelling = null }"
        >
            <form v-if="cancelling" class="space-y-4" @submit.prevent="submitCancel">
                <div class="space-y-1">
                    <Label for="cancel-reason">เหตุผล</Label>
                    <Input id="cancel-reason" v-model="cancelForm.reason" maxlength="255" placeholder="เช่น รถเสีย ส่งไม่ทัน" />
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" @click="cancelling = null">ปิด</Button>
                    <Button type="submit" variant="destructive" :disabled="cancelForm.processing">
                        ยกเลิกใบโอน
                    </Button>
                </div>
            </form>
        </Modal>
    </BackOfficeLayout>
</template>
