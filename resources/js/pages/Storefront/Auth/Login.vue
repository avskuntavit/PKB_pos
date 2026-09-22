<script setup lang="ts">
/**
 * ล็อกอินลูกค้า — เบอร์ + OTP
 *
 * ไม่มีหน้าสมัครสมาชิกแยก: ยืนยันเบอร์ครั้งแรกคือการสมัครไปในตัว
 * ลูกค้ากำลังหิวและยืนรออยู่ ทุกขั้นตอนที่ตัดได้ควรตัด
 */
import { computed, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ChevronLeft, MessageSquare, Smartphone } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import type { PageProps } from '@/types'

const props = defineProps<{
    branch: { code: string; name: string }
    redirectTo: string
    otpTtlMinutes: number
}>()

interface OtpFlash {
    phone: string
    expires_at: string
    channel: string
    dev_code: string | null
}

const page = usePage<PageProps & { flash: { otp?: OtpFlash | null } }>()

const step = ref<'phone' | 'code'>('phone')
const phone = ref('')
const code = ref('')
const name = ref('')
const sending = ref(false)
const cooldown = ref(0)

const otp = computed<OtpFlash | null>(() => (page.props.flash as any).otp ?? null)

// ระบบส่ง OTP กลับมาแปลว่าขั้นตอนแรกผ่านแล้ว
watch(otp, (value) => {
    if (value) {
        phone.value = value.phone
        step.value = 'code'
        startCooldown()
    }
})

function startCooldown() {
    cooldown.value = 60

    const timer = setInterval(() => {
        cooldown.value--
        if (cooldown.value <= 0) clearInterval(timer)
    }, 1000)
}

function requestCode() {
    sending.value = true

    router.post(
        '/order/login/request',
        { phone: phone.value, branch_code: props.branch.code },
        { preserveScroll: true, onFinish: () => (sending.value = false) },
    )
}

function verify() {
    sending.value = true

    router.post(
        '/order/login/verify',
        {
            phone: phone.value,
            code: code.value,
            name: name.value || null,
            branch_code: props.branch.code,
            redirect: props.redirectTo,
        },
        { onFinish: () => (sending.value = false) },
    )
}
</script>

<template>
    <Head title="เข้าสู่ระบบ" />

    <div class="flex min-h-dvh flex-col bg-muted/40">
        <header class="flex h-14 shrink-0 items-center gap-2 border-b bg-card px-3">
            <Link href="/order" class="rounded-md p-2 text-muted-foreground hover:bg-accent" aria-label="ย้อนกลับ">
                <ChevronLeft class="size-5" />
            </Link>
            <h1 class="text-base font-semibold">เข้าสู่ระบบ</h1>
        </header>

        <div
            v-if="page.props.flash.error"
            class="border-b border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 px-4 py-2 text-center text-sm text-[var(--status-critical)]"
        >
            {{ page.props.flash.error }}
        </div>

        <main class="mx-auto w-full max-w-md flex-1 p-4">
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">{{ branch.name }}</p>

                <!-- ขั้นที่ 1: กรอกเบอร์ -->
                <template v-if="step === 'phone'">
                    <h2 class="mt-1 text-lg font-semibold">ใส่เบอร์โทรเพื่อเข้าสู่ระบบ</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        ยังไม่เคยสมัคร? ใส่เบอร์แล้วยืนยันรหัสได้เลย ระบบสมัครให้อัตโนมัติ
                    </p>

                    <form class="mt-4 space-y-3" @submit.prevent="requestCode">
                        <div class="space-y-1">
                            <Label for="phone">เบอร์โทร</Label>
                            <div class="relative">
                                <Smartphone class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    id="phone"
                                    v-model="phone"
                                    type="tel"
                                    inputmode="tel"
                                    placeholder="08xxxxxxxx"
                                    class="h-12 pl-9 text-base"
                                    autofocus
                                />
                            </div>
                        </div>

                        <Button
                            type="submit"
                            variant="brand"
                            size="xl"
                            class="w-full"
                            :disabled="sending || phone.trim().length < 9"
                        >
                            {{ sending ? 'กำลังส่ง...' : 'ขอรหัสยืนยัน' }}
                        </Button>
                    </form>

                    <p class="mt-4 text-center text-xs text-muted-foreground">
                        ไม่อยากล็อกอินก็สั่งได้ —
                        <Link href="/order" class="underline underline-offset-2">สั่งแบบไม่ระบุตัวตน</Link>
                        <span class="mt-1 block">(แต่จะไม่ได้แต้มและสิทธิ์พนักงานองค์กร)</span>
                    </p>
                </template>

                <!-- ขั้นที่ 2: กรอกรหัส -->
                <template v-else>
                    <h2 class="mt-1 text-lg font-semibold">ใส่รหัสยืนยัน</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        ส่งไปที่ {{ phone }} · รหัสมีอายุ {{ otpTtlMinutes }} นาที
                    </p>

                    <!-- ยังไม่ได้ต่อ SMS: บอกตรง ๆ ว่ารหัสอยู่ที่ไหน -->
                    <div
                        v-if="otp?.dev_code"
                        class="mt-3 rounded-lg border border-[var(--status-warning)]/40 bg-[var(--status-warning)]/10 p-3 text-sm"
                    >
                        <p class="font-medium">โหมดทดสอบ — รหัสคือ <span class="tabular">{{ otp.dev_code }}</span></p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            ระบบยังไม่ได้ต่อบริการ SMS จริง ({{ otp.channel }})
                        </p>
                    </div>
                    <div
                        v-else-if="otp"
                        class="mt-3 flex items-start gap-2 rounded-lg border border-dashed p-3 text-xs text-muted-foreground"
                    >
                        <MessageSquare class="mt-0.5 size-3.5 shrink-0" />
                        ช่องทางส่งรหัสปัจจุบัน: {{ otp.channel }}
                    </div>

                    <form class="mt-4 space-y-3" @submit.prevent="verify">
                        <div class="space-y-1">
                            <Label for="code">รหัส 6 หลัก</Label>
                            <Input
                                id="code"
                                v-model="code"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                placeholder="------"
                                class="tabular h-14 text-center text-2xl tracking-[0.5em]"
                                autofocus
                            />
                        </div>

                        <div class="space-y-1">
                            <Label for="name">ชื่อที่ให้ร้านเรียก (ถ้าเพิ่งสมัครครั้งแรก)</Label>
                            <Input id="name" v-model="name" class="h-11" placeholder="เช่น คุณแนน" />
                        </div>

                        <Button
                            type="submit"
                            variant="brand"
                            size="xl"
                            class="w-full"
                            :disabled="sending || code.trim().length < 4"
                        >
                            {{ sending ? 'กำลังตรวจสอบ...' : 'ยืนยันและเข้าสู่ระบบ' }}
                        </Button>
                    </form>

                    <div class="mt-3 flex items-center justify-between text-sm">
                        <button class="text-muted-foreground hover:text-foreground" @click="step = 'phone'">
                            เปลี่ยนเบอร์
                        </button>
                        <button
                            class="text-[var(--series-1)] disabled:text-muted-foreground"
                            :disabled="cooldown > 0 || sending"
                            @click="requestCode"
                        >
                            {{ cooldown > 0 ? `ขอรหัสใหม่ได้ใน ${cooldown} วิ` : 'ขอรหัสใหม่' }}
                        </button>
                    </div>
                </template>
            </div>
        </main>
    </div>
</template>
