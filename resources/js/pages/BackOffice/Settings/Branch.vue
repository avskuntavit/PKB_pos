<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import { Check, Palette, Plus, Save, Sparkles, Trash2, TriangleAlert } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import CopyField from '@/components/backoffice/CopyField.vue'
import ImageField from '@/components/backoffice/ImageField.vue'
import StorefrontPreview from '@/components/backoffice/StorefrontPreview.vue'

interface PaymentRow {
    method: string
    label: string
    is_enabled: boolean
    show_on_storefront: boolean
    sort_order: number
    label_override: string | null
    note: string | null
    is_government_scheme: boolean
}

interface ImageSpec {
    label: string
    width: number
    height: number
    ratio: string
    max_mb: number
    formats: string
    hint: string
}

const props = defineProps<{
    branch: Record<string, any>
    paymentMethods: PaymentRow[]
    imageSpecs: { cover: ImageSpec; logo: ImageSpec }
    /** ลิงก์ประจำสาขา — เซิร์ฟเวอร์สร้างให้ตาม APP_URL จะได้ไม่ต้องเดาโดเมนฝั่งนี้ */
    links: { order: string; queue: string }
}>()

/** ตัดวินาทีออกจากเวลาที่มาจากฐานข้อมูล (09:00:00 -> 09:00) */
const hhmm = (value: string | null) => (value ? String(value).slice(0, 5) : '')

const form = useForm({
    name: props.branch.name ?? '',
    tax_id: props.branch.tax_id ?? '',
    phone: props.branch.phone ?? '',
    address: props.branch.address ?? '',
    intro: props.branch.intro ?? '',

    vat_rate: Number(props.branch.vat_rate ?? 7),
    vat_included: Boolean(props.branch.vat_included),
    service_charge_rate: Number(props.branch.service_charge_rate ?? 0),
    rounding_mode: Number(props.branch.rounding_mode ?? 0),
    business_day_start: hhmm(props.branch.business_day_start) || '05:00',

    open_time: hhmm(props.branch.open_time) || '09:00',
    close_time: hhmm(props.branch.close_time) || '21:00',
    prep_minutes: Number(props.branch.prep_minutes ?? 20),
    is_accepting_online_orders: Boolean(props.branch.is_accepting_online_orders),
    award_points_online: Boolean(props.branch.award_points_online),
    qr_requires_open_table: Boolean(props.branch.qr_requires_open_table),

    promptpay_id: props.branch.promptpay_id ?? '',
    promptpay_name: props.branch.promptpay_name ?? '',

    staff_benefit_enabled: Boolean(props.branch.staff_benefit_enabled),
    staff_benefit_monthly_cap: Number(props.branch.staff_benefit_monthly_cap ?? 0),
    staff_benefit_exclude_alcohol: Boolean(props.branch.staff_benefit_exclude_alcohol),

    restrict_alcohol_hours: Boolean(props.branch.restrict_alcohol_hours),
    alcohol_hours: (props.branch.alcohol_hours ?? []) as Array<{ from: string; to: string }>,

    payment_methods: props.paymentMethods.map((m, i) => ({ ...m, sort_order: m.sort_order || i })),

    theme_color: props.branch.theme_color || '#2a78d6',

    cover: null as File | null,
    logo: null as File | null,
    remove_cover: false,
    remove_logo: false,
})

const THEME_PALETTES = [
    { label: 'น้ำเงินคลาสสิก (Classic)', value: '#2a78d6' },
    { label: 'ม่วงอินดิโก้ (Indigo)', value: '#6366f1' },
    { label: 'ม่วงไวโอเล็ต (Violet)', value: '#8b5cf6' },
    { label: 'เขียวมรกต (Emerald)', value: '#10b981' },
    { label: 'เขียวสดใส (Green)', value: '#16a34a' },
    { label: 'ฟ้าทะเล (Cyan)', value: '#06b6d4' },
    { label: 'ส้มสดใส (Orange)', value: '#f97316' },
    { label: 'แดงกุหลาบ (Rose)', value: '#f43f5e' },
    { label: 'ชมพูฟูเชีย (Pink)', value: '#d946ef' },
    { label: 'เหลืองอำพัน (Amber)', value: '#f59e0b' },
    { label: 'เทาดำมินิมอล (Slate)', value: '#334155' },
]

/* ---------- ตัวอย่างหน้าร้านแบบสด ---------- */

const coverPreview = ref<string | null>(null)
const logoPreview = ref<string | null>(null)

/** รูปที่จะโชว์ในตัวอย่าง — ไฟล์ที่เพิ่งเลือก > รูปเดิม > ไม่มี */
function previewOf(local: string | null, removed: boolean, existing: string | null): string | null {
    if (local) return local
    return removed ? null : existing
}

const shownCover = computed(() => previewOf(coverPreview.value, form.remove_cover, props.branch.cover_path ?? null))
const shownLogo = computed(() => previewOf(logoPreview.value, form.remove_logo, props.branch.logo_path ?? null))

/** ImageField สร้าง object URL ของมันเอง ตรงนี้สร้างอีกชุดไว้ให้แผ่นตัวอย่าง */
watch(
    () => form.cover,
    (f) => {
        if (coverPreview.value) URL.revokeObjectURL(coverPreview.value)
        coverPreview.value = f ? URL.createObjectURL(f) : null
    },
)

watch(
    () => form.logo,
    (f) => {
        if (logoPreview.value) URL.revokeObjectURL(logoPreview.value)
        logoPreview.value = f ? URL.createObjectURL(f) : null
    },
)

onBeforeUnmount(() => {
    if (coverPreview.value) URL.revokeObjectURL(coverPreview.value)
    if (logoPreview.value) URL.revokeObjectURL(logoPreview.value)
})

const enabledCount = computed(() => form.payment_methods.filter((m) => m.is_enabled).length)

function addAlcoholWindow() {
    form.alcohol_hours.push({ from: '11:00', to: '14:00' })
}

function submit() {
    // มีไฟล์แนบ จึงต้องส่งเป็น multipart ซึ่งใช้ PUT ตรง ๆ ไม่ได้
    form.transform((data) => ({ ...data, _method: 'put' }))
    form.post('/backoffice/settings/branch', { forceFormData: true, preserveScroll: true })
}
</script>

<template>
    <Head title="ตั้งค่าสาขา" />

    <BackOfficeLayout title="ตั้งค่าสาขา">
        <!-- อยู่นอกฟอร์มโดยตั้งใจ — เป็นข้อมูลอ่านอย่างเดียว ไม่เกี่ยวกับปุ่มบันทึก -->
        <SectionCard title="ลิงก์ของสาขานี้">
            <div class="space-y-4">
                <CopyField
                    id="link-order"
                    label="ลิงก์สั่งอาหาร"
                    :value="links.order"
                    openable
                    hint="ส่งให้ลูกค้า แปะในเพจ หรือเอาไปทำ QR วางหน้าร้าน — สแกนแล้วเข้าเมนูสาขานี้เลยโดยไม่ต้องเลือกร้าน"
                />

                <CopyField
                    id="link-queue"
                    label="ลิงก์จอแสดงคิว"
                    :value="links.queue"
                    openable
                    hint="เปิดค้างบนทีวีหรือแท็บเล็ตหน้าร้าน ไม่ต้องล็อกอิน แสดงแค่เลขคิวกับชื่อต้นของลูกค้า"
                />
            </div>
        </SectionCard>

        <form class="space-y-4" @submit.prevent="submit">
            <SectionCard title="ข้อมูลร้าน">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="name">ชื่อสาขา</Label>
                        <Input id="name" v-model="form.name" required />
                    </div>
                    <div class="space-y-1">
                        <Label for="tax">เลขประจำตัวผู้เสียภาษี</Label>
                        <Input id="tax" v-model="form.tax_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="phone">เบอร์โทร</Label>
                        <Input id="phone" v-model="form.phone" />
                    </div>
                    <div class="space-y-1">
                        <Label for="intro">คำโปรยบนหน้าร้านออนไลน์</Label>
                        <Input id="intro" v-model="form.intro" />
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <Label for="address">ที่อยู่</Label>
                        <Input id="address" v-model="form.address" />
                    </div>
                </div>
            </SectionCard>

            <SectionCard title="รูปหน้าร้านออนไลน์">
                <div class="grid gap-5 lg:grid-cols-[1fr_20rem]">
                    <div class="space-y-4">
                        <ImageField
                            v-model:file="form.cover"
                            v-model:removed="form.remove_cover"
                            :existing="branch.cover_path ?? null"
                            :spec="imageSpecs.cover"
                            :error="form.errors.cover"
                            shape="wide"
                        />

                        <ImageField
                            v-model:file="form.logo"
                            v-model:removed="form.remove_logo"
                            :existing="branch.logo_path ?? null"
                            :spec="imageSpecs.logo"
                            :error="form.errors.logo"
                            shape="round"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <p class="text-sm font-medium">ตัวอย่างหน้าร้าน</p>
                        <StorefrontPreview
                            :name="form.name"
                            :intro="form.intro || null"
                            :phone="form.phone || null"
                            :address="form.address || null"
                            :open-time="form.open_time"
                            :close-time="form.close_time"
                            :prep-minutes="form.prep_minutes"
                            :accepting-orders="form.is_accepting_online_orders"
                            :cover="shownCover"
                            :logo="shownLogo"
                        />
                        <p class="text-xs text-muted-foreground">
                            ขยับตามที่พิมพ์ทันที ยังไม่บันทึกจนกว่าจะกดปุ่มด้านล่าง
                        </p>
                    </div>
                </div>
            </SectionCard>

            <SectionCard title="ธีมและแถบสีประจำสถานี (BackOffice)">
                <p class="mb-4 text-xs text-muted-foreground">
                    กำหนดแถบสีประจำสถานีนี้เพื่อช่วยให้ผู้จัดการและพนักงานแยกแยะสถานีที่กำลังเปิดดูในระบบหลังบ้านได้อย่างชัดเจน (การตั้งค่านี้มีผลเฉพาะ layout หน้าจอหลังบ้านเท่านั้น ไม่กระทบหน้าขาย POS หรือหน้าร้านออนไลน์)
                </p>

                <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
                    <div class="space-y-4">
                        <div>
                            <Label class="text-xs font-semibold text-foreground">เลือกชุดสีประจำสถานี</Label>
                            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                                <button
                                    v-for="color in THEME_PALETTES"
                                    :key="color.value"
                                    type="button"
                                    class="flex items-center gap-2.5 rounded-lg border p-2.5 text-left text-xs transition-all hover:bg-accent"
                                    :class="
                                        form.theme_color?.toLowerCase() === color.value.toLowerCase()
                                            ? 'border-primary ring-2 ring-primary/20 bg-primary/5 font-semibold'
                                            : 'border-border'
                                    "
                                    @click="form.theme_color = color.value"
                                >
                                    <span class="size-4 shrink-0 rounded-full shadow-xs" :style="{ backgroundColor: color.value }" />
                                    <span class="truncate">{{ color.label.split(' ')[0] }}</span>
                                    <Check v-if="form.theme_color?.toLowerCase() === color.value.toLowerCase()" class="ml-auto size-3.5 text-primary" />
                                </button>
                            </div>
                        </div>

                        <!-- Custom Color Picker -->
                        <div class="flex items-center gap-3 pt-2">
                            <div class="space-y-1">
                                <Label for="custom-color" class="text-xs text-muted-foreground">กำหนดรหัสสีเอง (Hex Code)</Label>
                                <div class="flex items-center gap-2">
                                    <input
                                        id="custom-color-picker"
                                        v-model="form.theme_color"
                                        type="color"
                                        class="size-9 cursor-pointer rounded-md border p-0.5 bg-card"
                                    />
                                    <Input
                                        id="custom-color"
                                        v-model="form.theme_color"
                                        placeholder="#2a78d6"
                                        class="w-32 uppercase font-mono text-xs"
                                        maxlength="7"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Live BackOffice Layout Mockup Preview -->
                    <div class="space-y-1.5">
                        <p class="text-sm font-medium">ตัวอย่างแถบสีหลังบ้าน</p>
                        <div class="overflow-hidden rounded-xl border bg-card shadow-xs text-xs">
                            <!-- Top Color Strip Preview -->
                            <div class="h-1.5 w-full shadow-xs" :style="{ backgroundColor: form.theme_color || '#2a78d6' }" />

                            <!-- Mock Header -->
                            <div class="flex items-center justify-between border-b bg-background/80 px-3 py-2">
                                <div class="flex items-center gap-1.5 font-bold text-[11px]">
                                    <span class="grid size-4 place-items-center rounded bg-primary text-[9px] text-primary-foreground">F</span>
                                    FoodPOS
                                </div>
                                <!-- Station tag with theme color -->
                                <div class="flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-[10px] bg-card">
                                    <img v-if="shownLogo" :src="shownLogo" class="size-3.5 rounded object-cover" />
                                    <span v-else class="size-2 rounded-full" :style="{ backgroundColor: form.theme_color || '#2a78d6' }" />
                                    <span class="font-medium truncate max-w-[90px]">{{ form.name || 'ชื่อสาขา' }}</span>
                                </div>
                            </div>

                            <!-- Mock Body -->
                            <div class="grid grid-cols-[3.5rem_1fr] h-24 bg-muted/20">
                                <!-- Mini Sidebar -->
                                <div class="border-r bg-card/50 p-1.5 space-y-1">
                                    <div class="h-4 rounded px-1 flex items-center text-[9px] font-medium" :style="{ backgroundColor: (form.theme_color || '#2a78d6') + '22', color: form.theme_color || '#2a78d6' }">
                                        ● แดชบอร์ด
                                    </div>
                                    <div class="h-4 rounded px-1 flex items-center text-[9px] text-muted-foreground">
                                        รายงาน
                                    </div>
                                    <div class="h-4 rounded px-1 flex items-center text-[9px] text-muted-foreground">
                                        สินค้า
                                    </div>
                                </div>
                                <!-- Mini Content Area -->
                                <div class="p-2 space-y-1.5">
                                    <div class="h-3 w-16 rounded bg-muted animate-pulse" />
                                    <div class="grid grid-cols-2 gap-1">
                                        <div class="h-10 rounded border bg-card p-1">
                                            <span class="block text-[8px] text-muted-foreground">ยอดขาย</span>
                                            <span class="font-bold text-[10px]" :style="{ color: form.theme_color || '#2a78d6' }">฿12,450</span>
                                        </div>
                                        <div class="h-10 rounded border bg-card p-1">
                                            <span class="block text-[8px] text-muted-foreground">ออเดอร์</span>
                                            <span class="font-bold text-[10px]">48 บิล</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            แถบสีด้านบนสุด และไฮไลต์เมนูจะปรับตามสีที่เลือกนี้
                        </p>
                    </div>
                </div>
            </SectionCard>

            <SectionCard title="ภาษีและการคิดเงิน">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-1">
                        <Label for="vat">ภาษีมูลค่าเพิ่ม (%)</Label>
                        <Input id="vat" v-model="form.vat_rate" type="number" step="0.01" min="0" max="30" />
                    </div>
                    <div class="space-y-1">
                        <Label for="service">ค่าบริการ (%)</Label>
                        <Input id="service" v-model="form.service_charge_rate" type="number" step="0.01" min="0" max="30" />
                    </div>
                    <div class="space-y-1">
                        <Label for="rounding">การปัดเศษท้ายบิล</Label>
                        <Select id="rounding" v-model.number="form.rounding_mode">
                            <option :value="0">ไม่ปัด</option>
                            <option :value="1">ปัดขึ้นเป็นจำนวนเต็ม</option>
                            <option :value="2">ปัดลง</option>
                            <option :value="3">ปัดใกล้สุด</option>
                        </Select>
                    </div>
                    <div class="space-y-1">
                        <Label for="bizday">เวลาตัดรอบวันขาย</Label>
                        <Input id="bizday" v-model="form.business_day_start" type="time" />
                    </div>
                </div>

                <label class="mt-3 flex items-start gap-2 text-sm">
                    <input v-model="form.vat_included" type="checkbox" class="mt-0.5 size-4" />
                    <span>
                        ราคาเมนูรวม VAT แล้ว
                        <span class="block text-xs text-muted-foreground">
                            ติ๊กไว้ = แยกภาษีออกมาโชว์เฉย ๆ / ไม่ติ๊ก = บวกภาษีเพิ่มจากราคาเมนู
                        </span>
                    </span>
                </label>
            </SectionCard>

            <SectionCard title="หน้าร้านออนไลน์">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="space-y-1">
                        <Label for="open">เวลาเปิด</Label>
                        <Input id="open" v-model="form.open_time" type="time" />
                    </div>
                    <div class="space-y-1">
                        <Label for="close">เวลาปิด</Label>
                        <Input id="close" v-model="form.close_time" type="time" />
                    </div>
                    <div class="space-y-1">
                        <Label for="prep">เวลาเตรียมอาหาร (นาที)</Label>
                        <Input id="prep" v-model="form.prep_minutes" type="number" min="1" max="240" />
                    </div>
                </div>

                <div class="mt-3 space-y-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_accepting_online_orders" type="checkbox" class="size-4" />
                        เปิดรับออเดอร์ล่วงหน้าจากหน้าร้านออนไลน์
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.award_points_online" type="checkbox" class="mt-0.5 size-4" />
                        <span>
                            ให้แต้มสะสมกับออเดอร์ออนไลน์
                            <span class="block text-xs text-muted-foreground">
                                ออเดอร์ออนไลน์ยืนยันด้วยเบอร์อย่างเดียว ไม่มี OTP ตอนสั่ง ปิดได้ถ้าไม่สบายใจ
                            </span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.qr_requires_open_table" type="checkbox" class="mt-0.5 size-4" />
                        <span>
                            QR บนโต๊ะสั่งได้เฉพาะตอนพนักงานเปิดโต๊ะแล้ว
                            <span class="block text-xs text-muted-foreground">
                                กันคนถ่ายรูป QR กลับบ้านแล้วสั่งเล่น — ลูกค้าที่สแกนตอนโต๊ะยังว่างจะดูเมนูได้
                                แต่สั่งเข้าโต๊ะไม่ได้จนกว่าพนักงานจะเปิดบิลให้โต๊ะนั้น
                                ปิดข้อนี้ถ้าร้านให้ลูกค้าสแกนสั่งเองโดยไม่ต้องรอพนักงาน
                            </span>
                        </span>
                    </label>
                </div>
            </SectionCard>

            <SectionCard title="พร้อมเพย์">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="ppid">เลขพร้อมเพย์</Label>
                        <Input id="ppid" v-model="form.promptpay_id" placeholder="เบอร์มือถือ หรือเลข 13 หลัก" />
                        <p class="text-xs text-muted-foreground">ใช้สร้าง QR ให้ลูกค้าสแกนจ่าย</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="ppname">ชื่อร้านใน QR</Label>
                        <Input id="ppname" v-model="form.promptpay_name" placeholder="ภาษาอังกฤษเท่านั้น" />
                        <p class="text-xs text-muted-foreground">มาตรฐาน QR รองรับแค่ ASCII ระบบตัดอักษรไทยออกให้เอง</p>
                    </div>
                </div>
            </SectionCard>

            <SectionCard title="ช่องทางชำระเงินที่สาขานี้รับ">
                <template #actions>
                    <Badge :variant="enabledCount > 0 ? 'success' : 'danger'">
                        เปิดอยู่ {{ enabledCount }} ช่องทาง
                    </Badge>
                </template>

                <p class="mb-3 text-xs text-muted-foreground">
                    ช่องทางที่ปิดจะไม่ขึ้นบนหน้าจอรับเงิน และเซิร์ฟเวอร์จะปฏิเสธถ้ามีคนพยายามใช้
                </p>

                <ul class="space-y-2">
                    <li
                        v-for="(method, i) in form.payment_methods"
                        :key="method.method"
                        class="rounded-lg border p-3"
                        :class="method.is_enabled ? '' : 'opacity-60'"
                    >
                        <div class="flex flex-wrap items-center gap-3">
                            <label class="flex items-center gap-2 text-sm font-medium">
                                <input v-model="method.is_enabled" type="checkbox" class="size-4" />
                                {{ method.label }}
                            </label>

                            <Badge v-if="method.is_government_scheme" variant="outline">โครงการรัฐ</Badge>

                            <label class="ml-auto flex items-center gap-2 text-xs text-muted-foreground">
                                <input
                                    v-model="method.show_on_storefront"
                                    type="checkbox"
                                    class="size-4"
                                    :disabled="!method.is_enabled"
                                />
                                ให้ลูกค้าเลือกตอนสั่งล่วงหน้า
                            </label>

                            <Input
                                v-model.number="method.sort_order"
                                type="number"
                                class="h-8 w-20"
                                aria-label="ลำดับ"
                            />
                        </div>

                        <Input
                            v-model="method.note"
                            class="mt-2 h-9"
                            placeholder="ข้อความเตือนพนักงาน เช่น ต้องขอสลิปทุกครั้ง (ไม่บังคับ)"
                            :disabled="!method.is_enabled"
                        />
                    </li>
                </ul>
            </SectionCard>

            <SectionCard title="สวัสดิการพนักงานองค์กร">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.staff_benefit_enabled" type="checkbox" class="size-4" />
                    เปิดสิทธิ์ราคาพนักงาน
                </label>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="cap">เพดานวงเงินต่อคนต่อเดือน (บาท)</Label>
                        <Input
                            id="cap"
                            v-model="form.staff_benefit_monthly_cap"
                            type="number"
                            step="0.01"
                            min="0"
                            :disabled="!form.staff_benefit_enabled"
                        />
                        <p class="text-xs text-muted-foreground">ใส่ 0 = ไม่จำกัด</p>
                    </div>
                </div>

                <label class="mt-3 flex items-center gap-2 text-sm">
                    <input
                        v-model="form.staff_benefit_exclude_alcohol"
                        type="checkbox"
                        class="size-4"
                        :disabled="!form.staff_benefit_enabled"
                    />
                    ไม่ให้สิทธิ์กับเครื่องดื่มแอลกอฮอล์
                </label>

                <p class="mt-3 border-t pt-3 text-xs text-muted-foreground">
                    ส่วนลดคิดจาก "ราคาพนักงาน" ที่ตั้งไว้รายเมนู เมนูที่ไม่ได้ตั้งราคาพนักงานจะไม่ถูกลด
                </p>
            </SectionCard>

            <SectionCard title="จำกัดเวลาขายแอลกอฮอล์">
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.restrict_alcohol_hours" type="checkbox" class="mt-0.5 size-4" />
                    <span>
                        เปิดการจำกัดช่วงเวลาขาย
                        <span class="block text-xs text-muted-foreground">
                            ระบบไม่ได้ฝังกฎหมายไว้ — ร้านต้องตั้งช่วงเวลาเองให้ตรงกับระเบียบที่บังคับใช้อยู่
                        </span>
                    </span>
                </label>

                <div v-if="form.restrict_alcohol_hours" class="mt-3 space-y-2">
                    <div
                        v-for="(window, i) in form.alcohol_hours"
                        :key="i"
                        class="flex flex-wrap items-end gap-2 rounded-lg border p-3"
                    >
                        <div class="space-y-1">
                            <Label :for="`aw-from-${i}`">ขายได้ตั้งแต่</Label>
                            <Input :id="`aw-from-${i}`" v-model="window.from" type="time" class="w-32" />
                        </div>
                        <div class="space-y-1">
                            <Label :for="`aw-to-${i}`">ถึง</Label>
                            <Input :id="`aw-to-${i}`" v-model="window.to" type="time" class="w-32" />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="ml-auto text-[var(--status-critical)]"
                            aria-label="ลบช่วงเวลา"
                            @click="form.alcohol_hours.splice(i, 1)"
                        >
                            <Trash2 />
                        </Button>
                    </div>

                    <Button type="button" variant="outline" size="sm" @click="addAlcoholWindow">
                        <Plus />
                        เพิ่มช่วงเวลา
                    </Button>

                    <p
                        v-if="form.alcohol_hours.length === 0"
                        class="flex items-start gap-2 rounded-lg border border-[var(--status-warning)]/40 bg-[var(--status-warning)]/10 p-3 text-xs text-[var(--status-warning)]"
                    >
                        <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                        ยังไม่ได้ตั้งช่วงเวลา — ระบบจะถือว่าขายได้ตลอดเวลา
                    </p>
                </div>
            </SectionCard>

            <div class="sticky bottom-4 flex justify-end">
                <Button type="submit" variant="brand" size="lg" :disabled="form.processing">
                    <Save />
                    บันทึกการตั้งค่า
                </Button>
            </div>
        </form>
    </BackOfficeLayout>
</template>
