<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { ArrowDown, ArrowUp, Building2, ImagePlus, MapPin, Pencil, Plus, Power, Trash2 } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Modal from '@/components/ui/Modal.vue'
import Select from '@/components/ui/Select.vue'
import type { Option } from '@/types'

interface StationImage {
    id: number
    path: string
    caption: string | null
}

interface Station {
    id: number
    code: string
    name: string
    store_type: string | null
    store_type_label: string | null
    theme_color: string | null
    promo_title: string | null
    phone: string | null
    address: string | null
    intro: string | null
    /** ส่งมาเป็นข้อความตาม cast decimal เพื่อไม่ให้ทศนิยมท้าย ๆ เพี้ยน */
    latitude: string | null
    longitude: string | null
    has_coordinates: boolean
    logo_path: string | null
    cover_path: string | null
    is_active: boolean
    counts: {
        tables: number
        staff: number
        own_products: number
        images: number
    }
    images: StationImage[]
}

interface ImageSpec {
    label: string
    hint: string
}

const props = defineProps<{
    stations: Station[]
    storeTypes: Option[]
    copyOptions: Option[]
    imageSpecs: Record<string, ImageSpec>
    maxImages: number
}>()

const activeStations = computed(() => props.stations.filter((s) => s.is_active))

/* ---------- เปิดสถานีใหม่ ---------- */

const createOpen = ref(false)

const createForm = useForm({
    code: '',
    name: '',
    store_type: '',
    theme_color: '#2a78d6',
    promo_title: '',
    phone: '',
    address: '',
    intro: '',
    latitude: '',
    longitude: '',
    copy_from: activeStations.value[0]?.id ?? null,
    // ติ๊กทุกหมวดไว้ก่อน — สถานีที่เปิดมาแล้วไม่มีสูตรคือกรณีที่พังเงียบที่สุด
    copy: props.copyOptions.map((option) => option.value),
})

function openCreate() {
    createForm.reset()
    createForm.clearErrors()
    createForm.copy_from = activeStations.value[0]?.id ?? null
    createForm.copy = props.copyOptions.map((option) => option.value)
    createOpen.value = true
}

function submitCreate() {
    createForm.post('/backoffice/stations', {
        preserveScroll: true,
        onSuccess: () => {
            createOpen.value = false
        },
    })
}

/* ---------- แก้ข้อมูลแบรนด์ ---------- */

const editing = ref<Station | null>(null)

const editForm = useForm({
    _method: 'put',
    name: '',
    store_type: '',
    theme_color: '',
    promo_title: '',
    phone: '',
    address: '',
    intro: '',
    latitude: '',
    longitude: '',
    logo: null as File | null,
    cover: null as File | null,
    remove_logo: false as boolean,
    remove_cover: false as boolean,
})

function openEdit(station: Station) {
    editing.value = station
    editForm.clearErrors()
    editForm.defaults({
        _method: 'put',
        name: station.name,
        store_type: station.store_type ?? '',
        theme_color: station.theme_color ?? '',
        promo_title: station.promo_title ?? '',
        phone: station.phone ?? '',
        address: station.address ?? '',
        intro: station.intro ?? '',
        latitude: station.latitude ?? '',
        longitude: station.longitude ?? '',
        logo: null,
        cover: null,
        remove_logo: false,
        remove_cover: false,
    })
    editForm.reset()
}

function submitEdit() {
    if (!editing.value) return

    // ไฟล์แนบต้องส่งเป็น multipart และ Inertia ส่ง PUT พร้อมไฟล์ตรง ๆ ไม่ได้
    // จึงยิง POST แล้วให้ Laravel อ่าน _method เป็น PUT แทน
    editForm.post(`/backoffice/stations/${editing.value.id}`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            editing.value = null
        },
    })
}

function pickFile(event: Event, field: 'logo' | 'cover') {
    const input = event.target as HTMLInputElement
    editForm[field] = input.files?.[0] ?? null
}

/* ---------- เปิด/ปิดใช้งาน ---------- */

function toggleActive(station: Station) {
    const question = station.is_active
        ? `ปิดใช้งาน ${station.name} ใช่ไหม? ข้อมูลเก่ายังอยู่ครบและเปิดกลับมาได้ตลอด`
        : `เปิดใช้งาน ${station.name} อีกครั้งใช่ไหม?`

    if (!window.confirm(question)) return

    router.post(`/backoffice/stations/${station.id}/active`, {}, { preserveScroll: true })
}

/* ---------- รูปบรรยากาศร้าน ---------- */

const gallery = ref<Station | null>(null)

const imageForm = useForm({
    image: null as File | null,
    caption: '',
})

function openGallery(station: Station) {
    gallery.value = station
    imageForm.reset()
    imageForm.clearErrors()
}

/** การ์ดในหน้าอาจถูก Inertia วาดใหม่หลังบันทึก ต้องอ่านแถวล่าสุดเสมอ ไม่ใช่ค่าที่จำไว้ตอนเปิด */
const galleryStation = computed(() =>
    gallery.value ? (props.stations.find((s) => s.id === gallery.value?.id) ?? null) : null,
)

function pickGalleryFile(event: Event) {
    const input = event.target as HTMLInputElement
    imageForm.image = input.files?.[0] ?? null
}

function submitImage() {
    if (!galleryStation.value) return

    imageForm.post(`/backoffice/stations/${galleryStation.value.id}/images`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => imageForm.reset(),
    })
}

function deleteImage(image: StationImage) {
    if (!galleryStation.value) return
    if (!window.confirm('ลบรูปนี้ใช่ไหม?')) return

    router.delete(`/backoffice/stations/${galleryStation.value.id}/images/${image.id}`, {
        preserveScroll: true,
    })
}

function moveImage(index: number, direction: -1 | 1) {
    const station = galleryStation.value
    if (!station) return

    const order = station.images.map((image) => image.id)
    const target = index + direction

    if (target < 0 || target >= order.length) return

    ;[order[index], order[target]] = [order[target], order[index]]

    router.put(`/backoffice/stations/${station.id}/images/order`, { order }, { preserveScroll: true })
}
</script>

<template>
    <Head title="สถานีทั้งหมด" />

    <BackOfficeLayout title="สถานีทั้งหมด">
        <div class="space-y-4">
            <SectionCard title="สถานีในระบบ">
                <template #actions>
                    <Button size="sm" @click="openCreate">
                        <Plus />
                        เปิดสถานีใหม่
                    </Button>
                </template>

                <p class="text-xs text-muted-foreground">
                    เมนู หมวด เซ็ตตัวเลือก และของในคลังเป็นของกลางอยู่แล้ว สถานีใหม่เห็นทันที
                    ส่วนสูตรอาหาร ราคาเฉพาะสถานี โต๊ะ และช่องทางชำระเงิน เป็นของรายสถานี
                    จึงเลือกคัดลอกจากสถานีเดิมได้ตอนเปิด
                    <span class="font-medium text-foreground">ยอดคงเหลือในคลังไม่เคยคัดลอก — เริ่มที่ 0 เสมอ</span>
                </p>
            </SectionCard>

            <EmptyState
                v-if="stations.length === 0"
                title="ยังไม่มีสถานีในระบบ"
                description="กดปุ่มเปิดสถานีใหม่เพื่อเริ่มต้น"
            />

            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="station in stations"
                    :key="station.id"
                    class="flex flex-col gap-3 rounded-xl border bg-background p-4"
                    :class="{ 'opacity-60': !station.is_active }"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg text-white"
                            :style="{ backgroundColor: station.theme_color || '#2a78d6' }"
                        >
                            <img
                                v-if="station.logo_path"
                                :src="station.logo_path"
                                :alt="station.name"
                                class="size-10 rounded-lg object-cover"
                            />
                            <Building2 v-else class="size-5" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="truncate text-sm font-semibold">{{ station.name }}</p>
                                <Badge variant="outline">{{ station.code }}</Badge>
                                <Badge v-if="!station.is_active" variant="danger">ปิดใช้งาน</Badge>
                            </div>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ station.store_type_label ?? 'ยังไม่ได้ระบุประเภทร้าน' }}
                            </p>
                        </div>
                    </div>

                    <dl class="grid grid-cols-4 gap-2 rounded-lg bg-muted/50 p-2 text-center">
                        <div>
                            <dt class="text-[11px] text-muted-foreground">โต๊ะ</dt>
                            <dd class="text-sm font-semibold">{{ station.counts.tables }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] text-muted-foreground">พนักงาน</dt>
                            <dd class="text-sm font-semibold">{{ station.counts.staff }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] text-muted-foreground">เมนูเฉพาะ</dt>
                            <dd class="text-sm font-semibold">{{ station.counts.own_products }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] text-muted-foreground">รูป</dt>
                            <dd class="text-sm font-semibold">{{ station.counts.images }}</dd>
                        </div>
                    </dl>

                    <p v-if="station.address" class="line-clamp-2 text-xs text-muted-foreground">
                        {{ station.address }}
                    </p>

                    <p v-if="station.has_coordinates" class="flex items-center gap-1 text-xs text-muted-foreground">
                        <MapPin class="size-3.5" />
                        {{ station.latitude }}, {{ station.longitude }}
                    </p>

                    <div class="mt-auto flex flex-wrap gap-1.5">
                        <Button variant="outline" size="sm" @click="openEdit(station)">
                            <Pencil />
                            แก้ข้อมูล
                        </Button>
                        <Button variant="outline" size="sm" @click="openGallery(station)">
                            <ImagePlus />
                            รูปร้าน
                        </Button>
                        <Button
                            :variant="station.is_active ? 'ghost' : 'secondary'"
                            size="sm"
                            @click="toggleActive(station)"
                        >
                            <Power />
                            {{ station.is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- เปิดสถานีใหม่ -->
        <Modal
            v-model:open="createOpen"
            title="เปิดสถานีใหม่"
            description="กรอกเฉพาะข้อมูลที่จำเป็น ค่าตั้งการขาย (VAT ค่าบริการ ตัดรอบวัน) ตั้งได้ทีหลังที่หน้าตั้งค่าสาขา"
            class="max-w-2xl"
        >
            <form class="space-y-4" @submit.prevent="submitCreate">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="station-code">รหัสสถานี</Label>
                        <Input id="station-code" v-model="createForm.code" maxlength="20" placeholder="BR02" />
                        <p class="text-[11px] text-muted-foreground">
                            อยู่ใน URL ที่ลูกค้าเปิด (/order/รหัส) — ใช้ได้เฉพาะ a-z A-Z 0-9 _ -
                        </p>
                        <p v-if="createForm.errors.code" class="text-xs text-[var(--status-critical)]">
                            {{ createForm.errors.code }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <Label for="station-name">ชื่อสถานี</Label>
                        <Input id="station-name" v-model="createForm.name" maxlength="255" placeholder="สาขาสอง" />
                        <p v-if="createForm.errors.name" class="text-xs text-[var(--status-critical)]">
                            {{ createForm.errors.name }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <Label for="station-type">ประเภทร้าน</Label>
                        <Select id="station-type" v-model="createForm.store_type">
                            <option value="">ยังไม่ระบุ</option>
                            <option v-for="type in storeTypes" :key="type.value" :value="type.value">
                                {{ type.label }}
                            </option>
                        </Select>
                    </div>

                    <div class="space-y-1">
                        <Label for="station-color">สีประจำสถานี</Label>
                        <div class="flex gap-2">
                            <input
                                id="station-color"
                                v-model="createForm.theme_color"
                                type="color"
                                class="h-9 w-12 shrink-0 rounded-md border border-input bg-background"
                            />
                            <Input v-model="createForm.theme_color" maxlength="30" placeholder="#2a78d6" />
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label for="station-phone">เบอร์โทร</Label>
                        <Input id="station-phone" v-model="createForm.phone" maxlength="30" />
                    </div>

                    <div class="space-y-1">
                        <Label for="station-intro">คำโปรยสั้น</Label>
                        <Input id="station-intro" v-model="createForm.intro" maxlength="255" />
                    </div>

                    <div class="space-y-1 sm:col-span-2">
                        <Label for="station-address">ที่อยู่</Label>
                        <Input id="station-address" v-model="createForm.address" maxlength="500" />
                    </div>

                    <div class="space-y-1">
                        <Label for="station-lat">ละติจูด</Label>
                        <Input id="station-lat" v-model="createForm.latitude" placeholder="13.7563" />
                        <p v-if="createForm.errors.latitude" class="text-xs text-[var(--status-critical)]">
                            {{ createForm.errors.latitude }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <Label for="station-lng">ลองจิจูด</Label>
                        <Input id="station-lng" v-model="createForm.longitude" placeholder="100.5018" />
                        <p v-if="createForm.errors.longitude" class="text-xs text-[var(--status-critical)]">
                            {{ createForm.errors.longitude }}
                        </p>
                    </div>
                </div>

                <div v-if="activeStations.length > 0" class="space-y-2 rounded-lg border p-3">
                    <div class="space-y-1">
                        <Label for="station-copy-from">คัดลอกข้อมูลตั้งต้นจาก</Label>
                        <Select id="station-copy-from" v-model="createForm.copy_from">
                            <option :value="null">ไม่คัดลอก เริ่มจากศูนย์</option>
                            <option v-for="station in activeStations" :key="station.id" :value="station.id">
                                {{ station.name }}
                            </option>
                        </Select>
                    </div>

                    <div v-if="createForm.copy_from" class="space-y-1.5 pt-1">
                        <label
                            v-for="option in copyOptions"
                            :key="option.value"
                            class="flex items-start gap-2 text-xs"
                        >
                            <input
                                v-model="createForm.copy"
                                type="checkbox"
                                :value="option.value"
                                class="mt-0.5 size-4 shrink-0 rounded border-input"
                            />
                            <span>{{ option.label }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" @click="createOpen = false">ยกเลิก</Button>
                    <Button type="submit" :disabled="createForm.processing">เปิดสถานี</Button>
                </div>
            </form>
        </Modal>

        <!-- แก้ข้อมูลแบรนด์ -->
        <Modal
            :open="editing !== null"
            :title="editing ? `แก้ข้อมูล ${editing.name}` : ''"
            description="รหัสสถานีแก้ไม่ได้ เพราะมันอยู่ใน QR และลิงก์ที่แจกออกไปแล้ว"
            class="max-w-2xl"
            @update:open="(value: boolean) => { if (!value) editing = null }"
        >
            <form v-if="editing" class="space-y-4" @submit.prevent="submitEdit">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="edit-name">ชื่อสถานี</Label>
                        <Input id="edit-name" v-model="editForm.name" maxlength="255" />
                        <p v-if="editForm.errors.name" class="text-xs text-[var(--status-critical)]">
                            {{ editForm.errors.name }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <Label for="edit-type">ประเภทร้าน</Label>
                        <Select id="edit-type" v-model="editForm.store_type">
                            <option value="">ยังไม่ระบุ</option>
                            <option v-for="type in storeTypes" :key="type.value" :value="type.value">
                                {{ type.label }}
                            </option>
                        </Select>
                    </div>

                    <div class="space-y-1">
                        <Label for="edit-color">สีประจำสถานี</Label>
                        <div class="flex gap-2">
                            <input
                                id="edit-color"
                                v-model="editForm.theme_color"
                                type="color"
                                class="h-9 w-12 shrink-0 rounded-md border border-input bg-background"
                            />
                            <Input v-model="editForm.theme_color" maxlength="30" placeholder="#2a78d6" />
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label for="edit-promo">หัวข้อแถบโปรโมทบนหน้าสั่งอาหาร</Label>
                        <Input id="edit-promo" v-model="editForm.promo_title" maxlength="60" placeholder="สำหรับคุณ" />
                    </div>

                    <div class="space-y-1">
                        <Label for="edit-phone">เบอร์โทร</Label>
                        <Input id="edit-phone" v-model="editForm.phone" maxlength="30" />
                    </div>

                    <div class="space-y-1">
                        <Label for="edit-intro">คำโปรยสั้น</Label>
                        <Input id="edit-intro" v-model="editForm.intro" maxlength="255" />
                    </div>

                    <div class="space-y-1 sm:col-span-2">
                        <Label for="edit-address">ที่อยู่</Label>
                        <Input id="edit-address" v-model="editForm.address" maxlength="500" />
                    </div>

                    <div class="space-y-1">
                        <Label for="edit-lat">ละติจูด</Label>
                        <Input id="edit-lat" v-model="editForm.latitude" />
                        <p v-if="editForm.errors.latitude" class="text-xs text-[var(--status-critical)]">
                            {{ editForm.errors.latitude }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <Label for="edit-lng">ลองจิจูด</Label>
                        <Input id="edit-lng" v-model="editForm.longitude" />
                        <p v-if="editForm.errors.longitude" class="text-xs text-[var(--status-critical)]">
                            {{ editForm.errors.longitude }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <Label for="edit-logo">{{ imageSpecs.logo.label }}</Label>
                        <input
                            id="edit-logo"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="w-full text-xs"
                            @change="(event: Event) => pickFile(event, 'logo')"
                        />
                        <p class="text-[11px] text-muted-foreground">{{ imageSpecs.logo.hint }}</p>
                        <label v-if="editing.logo_path" class="flex items-center gap-1.5 text-xs">
                            <input v-model="editForm.remove_logo" type="checkbox" class="size-3.5" />
                            เอาโลโก้เดิมออก
                        </label>
                    </div>

                    <div class="space-y-1">
                        <Label for="edit-cover">{{ imageSpecs.cover.label }}</Label>
                        <input
                            id="edit-cover"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="w-full text-xs"
                            @change="(event: Event) => pickFile(event, 'cover')"
                        />
                        <p class="text-[11px] text-muted-foreground">{{ imageSpecs.cover.hint }}</p>
                        <label v-if="editing.cover_path" class="flex items-center gap-1.5 text-xs">
                            <input v-model="editForm.remove_cover" type="checkbox" class="size-3.5" />
                            เอารูปปกเดิมออก
                        </label>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" @click="editing = null">ยกเลิก</Button>
                    <Button type="submit" :disabled="editForm.processing">บันทึก</Button>
                </div>
            </form>
        </Modal>

        <!-- รูปบรรยากาศร้าน -->
        <Modal
            :open="gallery !== null"
            :title="galleryStation ? `รูปบรรยากาศ ${galleryStation.name}` : ''"
            :description="`เก็บได้สูงสุด ${maxImages} รูป · ลำดับบนสุดคือรูปแรกที่ลูกค้าเห็น`"
            class="max-w-2xl"
            @update:open="(value: boolean) => { if (!value) gallery = null }"
        >
            <div v-if="galleryStation" class="space-y-4">
                <form class="space-y-2 rounded-lg border p-3" @submit.prevent="submitImage">
                    <Label for="gallery-file">{{ imageSpecs.gallery.label }}</Label>
                    <input
                        id="gallery-file"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="w-full text-xs"
                        @change="pickGalleryFile"
                    />
                    <p class="text-[11px] text-muted-foreground">{{ imageSpecs.gallery.hint }}</p>
                    <p v-if="imageForm.errors.image" class="text-xs text-[var(--status-critical)]">
                        {{ imageForm.errors.image }}
                    </p>

                    <Input v-model="imageForm.caption" maxlength="120" placeholder="คำบรรยายสั้น (ไม่ใส่ก็ได้)" />

                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="imageForm.processing || !imageForm.image || galleryStation.images.length >= maxImages"
                        >
                            <ImagePlus />
                            เพิ่มรูป
                        </Button>
                    </div>
                </form>

                <EmptyState
                    v-if="galleryStation.images.length === 0"
                    title="ยังไม่มีรูปบรรยากาศ"
                    description="รูปชุดนี้ใช้โชว์บนหน้าเลือกร้านของลูกค้า"
                />

                <ul v-else class="space-y-2">
                    <li
                        v-for="(image, index) in galleryStation.images"
                        :key="image.id"
                        class="flex items-center gap-3 rounded-lg border p-2"
                    >
                        <img :src="image.path" alt="" class="size-14 shrink-0 rounded-md object-cover" />

                        <p class="min-w-0 flex-1 truncate text-xs">
                            {{ image.caption || 'ไม่มีคำบรรยาย' }}
                        </p>

                        <div class="flex shrink-0 gap-1">
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="เลื่อนขึ้น"
                                :disabled="index === 0"
                                @click="moveImage(index, -1)"
                            >
                                <ArrowUp />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="เลื่อนลง"
                                :disabled="index === galleryStation.images.length - 1"
                                @click="moveImage(index, 1)"
                            >
                                <ArrowDown />
                            </Button>
                            <Button variant="ghost" size="icon" aria-label="ลบรูป" @click="deleteImage(image)">
                                <Trash2 />
                            </Button>
                        </div>
                    </li>
                </ul>
            </div>
        </Modal>
    </BackOfficeLayout>
</template>
