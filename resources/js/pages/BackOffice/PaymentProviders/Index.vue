<script setup lang="ts">
/**
 * บัญชีรับเงินผ่าน QR ของสาขา
 *
 * ── กฎเหล็กของหน้านี้ ────────────────────────────────────────
 * ค่าจริงของกุญแจไม่เคยถูกส่งมาที่หน้านี้ มีแค่สี่ตัวท้ายกับธงว่ามีค่าอยู่แล้ว
 * ช่องกรอกจึงว่างเปล่าเสมอ และ **ช่องว่าง = ไม่แก้** ไม่ใช่ลบ
 * การลบมีปุ่มของตัวเองแยกไว้
 *
 * ── ทำไมเปิดใช้งานได้เจ้าเดียว ───────────────────────────────
 * QR ใบถัดไปจะออกจากเจ้าไหนต้องเดาไม่ได้ เพราะเป็นเรื่องเงิน
 * กดเปิดเจ้าหนึ่งแล้วเจ้าอื่นถูกปิดให้เอง (กุญแจยังอยู่ ใบเก่ายังถูกไล่ถามต่อ)
 */
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { CircleCheck, KeyRound, Power, QrCode, Save, ShieldAlert, Trash2, TriangleAlert } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'

interface CredentialField {
    key: string
    label: string
    hint: string
    secret: boolean
    required: boolean
    confirmed: boolean
}

interface Provider {
    value: string
    label: string
    is_gateway: boolean
    fields: CredentialField[]
    fields_confirmed: boolean
    has_connector: boolean
}

interface AccountRow {
    provider: string
    mode: string
    is_active: boolean
    activated_at: string | null
    note: string | null
    /** ค่าจริงไม่เคยมาถึงที่นี่ — hint คือสี่ตัวท้าย */
    credentials: Record<string, { filled: boolean; hint: string | null }>
    missing_keys: string[]
    updated_at: string | null
}

const props = defineProps<{
    branch: { id: number; name: string }
    providers: Provider[]
    accounts: AccountRow[]
    activeProvider: string
    activeProviderLabel: string
}>()

const accountOf = (provider: string) => props.accounts.find((a) => a.provider === provider) ?? null

/** เจ้าที่เปิดใช้งานอยู่ตอนนี้เป็นเกตเวย์จริงหรือยังเป็น QR ของร้าน */
const usingShopQr = computed(() => props.activeProvider === 'static')

/* ---------- ฟอร์มรายเจ้า ---------- */

const open = ref<string | null>(null)

/** ฟอร์มเปล่าของเจ้าหนึ่ง — ช่องกุญแจว่างเสมอ เพราะไม่มีค่าเดิมให้ใส่ */
function blankFor(provider: Provider): ProviderForm {
    const account = accountOf(provider.value)
    const credentials: Record<string, string> = {}

    for (const field of provider.fields) credentials[field.key] = ''

    return {
        mode: account?.mode ?? 'test',
        note: account?.note ?? '',
        credentials,
    }
}

/** รูปของฟอร์มหนึ่งเจ้า — ประกาศไว้เพื่อให้ชนิดไม่หายตอนเก็บลง map */
interface ProviderForm {
    mode: string
    note: string
    credentials: Record<string, string>
}

const forms: Record<string, ReturnType<typeof useForm<ProviderForm>>> = {}

function formFor(provider: Provider) {
    forms[provider.value] ??= useForm<ProviderForm>(blankFor(provider))

    return forms[provider.value]
}

function save(provider: Provider) {
    formFor(provider).put(`/backoffice/settings/payment-providers/${provider.value}`, {
        preserveScroll: true,
        // ล้างช่องกุญแจทิ้งหลังบันทึก ไม่ให้ค้างอยู่ในหน้าจอที่เปิดทิ้งไว้
        onSuccess: () => formFor(provider).reset('credentials'),
    })
}

function activate(provider: Provider) {
    router.post(`/backoffice/settings/payment-providers/${provider.value}/activate`, {}, { preserveScroll: true })
}

function deactivate(provider: Provider) {
    router.post(`/backoffice/settings/payment-providers/${provider.value}/deactivate`, {}, { preserveScroll: true })
}

const confirmingClear = ref<string | null>(null)

function clearCredentials(provider: Provider) {
    router.delete(`/backoffice/settings/payment-providers/${provider.value}/credentials`, {
        preserveScroll: true,
        onFinish: () => (confirmingClear.value = null),
    })
}
</script>

<template>
    <Head title="บัญชีรับเงินผ่าน QR" />

    <BackOfficeLayout title="บัญชีรับเงินผ่าน QR">
        <!-- เจ้าที่ใบใหม่จะออกจากตอนนี้ — คำตอบจากฝั่งเซิร์ฟเวอร์ ไม่ใช่หน้าจอเดา -->
        <SectionCard title="ตอนนี้ QR ใบใหม่ออกจาก">
            <div class="flex flex-wrap items-center gap-3">
                <span class="flex items-center gap-2 text-lg font-semibold">
                    <QrCode class="size-5 text-[var(--series-1)]" />
                    {{ activeProviderLabel }}
                </span>
                <Badge :variant="usingShopQr ? 'warning' : 'success'">
                    {{ usingShopQr ? 'ตรวจยอดอัตโนมัติไม่ได้' : 'ตรวจยอดอัตโนมัติได้' }}
                </Badge>
            </div>

            <p v-if="usingShopQr" class="mt-3 flex items-start gap-2 rounded-lg bg-[var(--status-warning)]/12 p-3 text-sm">
                <TriangleAlert class="mt-0.5 size-4 shrink-0 text-[var(--status-warning)]" />
                <span>
                    ยังใช้ QR พร้อมเพย์ของร้านเอง เงินเข้าบัญชีร้านตรงและไม่มีค่าธรรมเนียม
                    แต่ <strong>ระบบไม่มีทางรู้ว่าเงินเข้าหรือยัง</strong> พนักงานต้องขอดูสลิปเอง
                    เปิดใช้งานเกตเวย์ด้านล่างเพื่อให้ระบบปิดบิลให้เองได้
                </span>
            </p>
        </SectionCard>

        <SectionCard
            v-for="provider in providers"
            :key="provider.value"
            :title="provider.label"
        >
            <template #actions>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge v-if="accountOf(provider.value)?.is_active" variant="success">
                        <CircleCheck class="size-3" />
                        เปิดใช้งาน
                    </Badge>
                    <Badge v-else-if="accountOf(provider.value)" variant="secondary">ปิดอยู่</Badge>
                    <Badge v-else variant="outline">ยังไม่ได้ตั้ง</Badge>

                    <Badge v-if="accountOf(provider.value)?.mode === 'live'" variant="danger">ใช้เงินจริง</Badge>
                    <Badge v-else-if="accountOf(provider.value)" variant="secondary">โหมดทดสอบ</Badge>

                    <Button variant="outline" size="sm" @click="open = open === provider.value ? null : provider.value">
                        <KeyRound />
                        {{ open === provider.value ? 'ปิด' : 'ตั้งค่ากุญแจ' }}
                    </Button>
                </div>
            </template>

            <!-- ยังไม่มีตัวเชื่อม — บอกตรง ๆ ว่ากรอกไปก็ยังใช้ไม่ได้ -->
            <p
                v-if="!provider.has_connector"
                class="flex items-start gap-2 rounded-lg bg-[var(--status-critical)]/10 p-3 text-sm"
            >
                <ShieldAlert class="mt-0.5 size-4 shrink-0 text-[var(--status-critical)]" />
                <span>
                    <strong>ยังไม่ได้เขียนตัวเชื่อมของเจ้านี้</strong> — กรอกกุญแจเก็บไว้ล่วงหน้าได้
                    แต่เปิดใช้งานยังไม่ได้ เพราะเปิดแล้วจะออก QR ไม่ได้เลยทั้งสาขา
                </span>
            </p>

            <!-- ชื่อช่องยังไม่ได้เทียบกับเอกสารจริง -->
            <p
                v-if="!provider.fields_confirmed"
                class="mt-2 flex items-start gap-2 rounded-lg bg-muted/60 p-3 text-xs"
            >
                <TriangleAlert class="mt-0.5 size-3.5 shrink-0 text-[var(--status-warning)]" />
                <span>
                    ชื่อช่องข้างล่างเป็นการคาดไว้ก่อน ยังไม่ได้เทียบกับเอกสารของเจ้านี้จริง
                    จะยืนยันตอนเขียนตัวเชื่อม — ถ้าเอกสารเรียกชื่ออื่น จะมาแก้ให้ตรงกัน
                </span>
            </p>

            <!-- สรุปสิ่งที่เก็บไว้แล้ว -->
            <dl v-if="accountOf(provider.value)" class="mt-3 grid gap-2 sm:grid-cols-2">
                <div
                    v-for="field in provider.fields"
                    :key="field.key"
                    class="rounded-lg border bg-card p-3"
                >
                    <dt class="text-xs text-muted-foreground">
                        {{ field.label }}
                        <span v-if="!field.required" class="text-[11px]">(ไม่บังคับ)</span>
                    </dt>
                    <dd
                        class="tabular mt-1 text-sm"
                        :class="!accountOf(provider.value)!.credentials[field.key]?.filled
                            && field.required && 'text-[var(--status-critical)]'"
                    >
                        {{
                            accountOf(provider.value)!.credentials[field.key]?.hint
                                ?? (field.required ? 'ยังไม่ได้กรอก' : '—')
                        }}
                    </dd>
                </div>
            </dl>

            <p v-if="accountOf(provider.value)?.note" class="mt-2 text-sm text-muted-foreground">
                {{ accountOf(provider.value)!.note }}
            </p>

            <!-- ฟอร์มกรอกกุญแจ -->
            <form
                v-if="open === provider.value"
                class="mt-4 space-y-3 rounded-xl border bg-muted/30 p-4"
                @submit.prevent="save(provider)"
            >
                <p class="text-xs text-muted-foreground">
                    ช่องที่เว้นว่างไว้ = <strong>ไม่แก้ค่าเดิม</strong> · ค่าที่เก็บไว้แสดงได้แค่สี่ตัวท้าย
                    เพราะกุญแจที่ถูกส่งมาแสดงบนหน้าเว็บคือกุญแจที่หลุดไปแล้ว
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label :for="`mode-${provider.value}`">โหมด</Label>
                        <Select :id="`mode-${provider.value}`" v-model="formFor(provider).mode">
                            <option value="test">ทดสอบ (ไม่ใช้เงินจริง)</option>
                            <option value="live">ใช้เงินจริง</option>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            กุญแจทดสอบที่หลุดไปใช้จริง = บิลที่ไม่มีเงินเข้า
                        </p>
                    </div>
                    <div class="space-y-1">
                        <Label :for="`note-${provider.value}`">หมายเหตุ</Label>
                        <Input
                            :id="`note-${provider.value}`"
                            v-model="formFor(provider).note"
                            placeholder="เช่น บัญชีของสาขานี้ ติดต่อคุณ A"
                        />
                    </div>
                </div>

                <div v-for="field in provider.fields" :key="field.key" class="space-y-1">
                    <Label :for="`${provider.value}-${field.key}`">
                        {{ field.label }}
                        <span v-if="!field.required" class="text-xs text-muted-foreground">(ไม่บังคับ)</span>
                    </Label>
                    <Input
                        :id="`${provider.value}-${field.key}`"
                        v-model="formFor(provider).credentials[field.key]"
                        :type="field.secret ? 'password' : 'text'"
                        autocomplete="off"
                        :placeholder="accountOf(provider.value)?.credentials[field.key]?.filled
                            ? 'เก็บไว้แล้ว — เว้นว่างถ้าไม่แก้'
                            : 'ยังไม่ได้กรอก'"
                    />
                    <p class="text-xs text-muted-foreground">{{ field.hint }}</p>
                    <p
                        v-if="formFor(provider).errors[`credentials.${field.key}`]"
                        class="text-xs text-[var(--status-critical)]"
                    >
                        {{ formFor(provider).errors[`credentials.${field.key}`] }}
                    </p>
                </div>

                <div class="flex flex-wrap justify-end gap-2 pt-1">
                    <Button
                        v-if="accountOf(provider.value)"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="confirmingClear = provider.value"
                    >
                        <Trash2 />
                        ลบกุญแจ
                    </Button>
                    <Button type="submit" variant="brand" size="sm" :disabled="formFor(provider).processing">
                        <Save />
                        บันทึก
                    </Button>
                </div>

                <!-- ยืนยันก่อนลบ — ลบแล้วต้องไปขอกุญแจใหม่จากเกตเวย์ -->
                <div
                    v-if="confirmingClear === provider.value"
                    class="space-y-2 rounded-lg bg-[var(--status-critical)]/10 p-3 text-sm"
                >
                    <p>
                        ลบกุญแจของ {{ provider.label }} ทั้งชุด และปิดใช้งานไปด้วย
                        ระบบจะถอยไปใช้ QR ของร้านซึ่งตรวจยอดอัตโนมัติไม่ได้
                    </p>
                    <div class="flex justify-end gap-2">
                        <Button type="button" variant="outline" size="sm" @click="confirmingClear = null">
                            ยกเลิก
                        </Button>
                        <Button type="button" variant="danger" size="sm" @click="clearCredentials(provider)">
                            ลบเลย
                        </Button>
                    </div>
                </div>
            </form>

            <!-- เปิด/ปิดใช้งาน -->
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t pt-3">
                <Button
                    v-if="!accountOf(provider.value)?.is_active"
                    variant="brand"
                    size="sm"
                    :disabled="!accountOf(provider.value) || !provider.has_connector"
                    @click="activate(provider)"
                >
                    <Power />
                    เปิดใช้งานเจ้านี้
                </Button>
                <Button v-else variant="outline" size="sm" @click="deactivate(provider)">
                    <Power />
                    ปิดใช้งาน
                </Button>

                <span v-if="accountOf(provider.value)?.missing_keys?.length" class="text-xs text-[var(--status-critical)]">
                    ยังกรอกไม่ครบ: {{ accountOf(provider.value)!.missing_keys.join(', ') }}
                </span>
                <span v-else-if="!accountOf(provider.value)" class="text-xs text-muted-foreground">
                    บันทึกกุญแจก่อนจึงเปิดใช้งานได้
                </span>
            </div>
        </SectionCard>
    </BackOfficeLayout>
</template>
