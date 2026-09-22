<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { BadgeCheck, ChevronLeft, Clock, IdCard, LogOut, Star } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import StarRating from '@/components/storefront/StarRating.vue'
import ReviewSheet from '@/components/storefront/ReviewSheet.vue'
import { dateTime, money, number } from '@/lib/format'
import type { PageProps } from '@/types'

const props = defineProps<{
    branch: { code: string; name: string }
    customer: {
        name: string
        phone: string
        points: number
        tier: string
        visit_count: number
        total_spent: number
        employee_code: string | null
        employee_status: string | null
        employee_status_label: string | null
        employee_note: string | null
    }
    benefit: {
        enabled: boolean
        cap: number
        used: number
        remaining: number | null
        unlimited: boolean
        period: string
        excludes_alcohol: boolean
    }
    orders: Array<{
        id: number
        order_no: string
        branch: string | null
        status: string
        status_label: string
        type_label: string
        grand_total: number
        staff_discount: number
        opened_at: string | null
        track_token: string | null
        rating: number | null
        can_review: boolean
    }>
}>()

const page = usePage<PageProps>()

const showEmployeeForm = ref(false)
const reviewToken = ref<string | null>(null)

const employeeCode = ref(props.customer.employee_code ?? '')
const department = ref('')

const isApproved = computed(() => props.customer.employee_status === 'approved')
const isPending = computed(() => props.customer.employee_status === 'pending')
const isRejected = computed(() => props.customer.employee_status === 'rejected')

/** ใช้ไปกี่เปอร์เซ็นต์ของวงเงินเดือนนี้ */
const capUsedPercent = computed(() => {
    if (!props.benefit.cap) return 0
    return Math.min(100, Math.round((props.benefit.used / props.benefit.cap) * 100))
})

function submitEmployeeRequest() {
    router.post(
        '/order/account/employee',
        { employee_code: employeeCode.value, employee_department: department.value || null },
        { preserveScroll: true, onSuccess: () => (showEmployeeForm.value = false) },
    )
}
</script>

<template>
    <Head title="บัญชีของฉัน" />

    <div class="min-h-dvh bg-muted/40 pb-10">
        <header class="sticky top-0 z-20 flex h-14 items-center gap-2 border-b bg-card px-3">
            <Link href="/order" class="rounded-md p-2 text-muted-foreground hover:bg-accent" aria-label="ย้อนกลับ">
                <ChevronLeft class="size-5" />
            </Link>
            <h1 class="text-base font-semibold">บัญชีของฉัน</h1>

            <button
                class="ml-auto rounded-md p-2 text-muted-foreground hover:bg-accent"
                aria-label="ออกจากระบบ"
                @click="router.post('/order/logout')"
            >
                <LogOut class="size-4" />
            </button>
        </header>

        <div
            v-if="page.props.flash.success"
            class="border-b border-[var(--status-good)]/30 bg-[var(--status-good)]/10 px-4 py-2 text-center text-sm text-[var(--status-good)]"
        >
            {{ page.props.flash.success }}
        </div>
        <div
            v-if="page.props.flash.error"
            class="border-b border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 px-4 py-2 text-center text-sm text-[var(--status-critical)]"
        >
            {{ page.props.flash.error }}
        </div>

        <div class="mx-auto max-w-2xl space-y-4 p-4">
            <!-- โปรไฟล์ -->
            <section class="rounded-xl border bg-card p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex items-center gap-1.5 text-lg font-semibold">
                            {{ customer.name }}
                            <BadgeCheck v-if="isApproved" class="size-4 text-[var(--series-1)]" />
                        </p>
                        <p class="tabular text-sm text-muted-foreground">{{ customer.phone }}</p>
                    </div>
                    <Badge v-if="isApproved" variant="success">พนักงานองค์กร</Badge>
                </div>

                <dl class="mt-3 grid grid-cols-3 gap-3 border-t pt-3 text-center">
                    <div>
                        <dt class="text-xs text-muted-foreground">แต้มสะสม</dt>
                        <dd class="tabular text-lg font-semibold">{{ number(customer.points) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">จำนวนครั้งที่สั่ง</dt>
                        <dd class="tabular text-lg font-semibold">{{ number(customer.visit_count) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">ยอดสะสม</dt>
                        <dd class="tabular text-lg font-semibold">{{ money(customer.total_spent) }}</dd>
                    </div>
                </dl>
            </section>

            <!-- สิทธิ์พนักงานองค์กร -->
            <section class="rounded-xl border bg-card p-4">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <IdCard class="size-4 text-muted-foreground" />
                    สิทธิ์พนักงานองค์กร
                </h2>

                <template v-if="isApproved">
                    <p class="mt-1 text-sm text-muted-foreground">
                        รหัสพนักงาน {{ customer.employee_code }} · ได้ราคาพนักงานในเมนูที่ร่วมรายการ
                    </p>

                    <div v-if="!benefit.unlimited" class="mt-3 space-y-1.5">
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">วงเงินสวัสดิการเดือน {{ benefit.period }}</span>
                            <span class="tabular font-medium">
                                {{ money(benefit.used) }} / {{ money(benefit.cap) }} ฿
                            </span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full transition-all"
                                :class="capUsedPercent >= 100 ? 'bg-[var(--status-critical)]' : 'bg-[var(--series-1)]'"
                                :style="{ width: `${capUsedPercent}%` }"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            เหลืออีก {{ money(benefit.remaining ?? 0) }} บาทในเดือนนี้
                        </p>
                    </div>
                    <p v-else class="mt-2 text-sm text-muted-foreground">วงเงินไม่จำกัด</p>

                    <p v-if="benefit.excludes_alcohol" class="mt-2 text-xs text-muted-foreground">
                        หมายเหตุ: เครื่องดื่มแอลกอฮอล์ไม่เข้าเงื่อนไขสวัสดิการ
                    </p>
                </template>

                <template v-else-if="isPending">
                    <p class="mt-1 text-sm text-[var(--status-warning)]">
                        ส่งคำขอแล้ว รอ HR ตรวจสอบ · รหัส {{ customer.employee_code }}
                    </p>
                </template>

                <template v-else>
                    <p class="mt-1 text-sm text-muted-foreground">
                        เป็นพนักงานในองค์กรใช่ไหม? กรอกรหัสพนักงานเพื่อขอราคาพนักงาน
                    </p>
                    <p
                        v-if="isRejected && customer.employee_note"
                        class="mt-2 rounded-lg border border-[var(--status-critical)]/30 bg-[var(--status-critical)]/10 px-3 py-2 text-xs text-[var(--status-critical)]"
                    >
                        คำขอก่อนหน้าไม่ผ่าน: {{ customer.employee_note }}
                    </p>

                    <Button variant="outline" size="lg" class="mt-3 w-full" @click="showEmployeeForm = true">
                        ขอสิทธิ์พนักงาน
                    </Button>
                </template>
            </section>

            <!-- ประวัติการสั่ง -->
            <section class="rounded-xl border bg-card">
                <h2 class="flex items-center gap-2 border-b px-4 py-3 text-sm font-semibold">
                    <Clock class="size-4 text-muted-foreground" />
                    ประวัติการสั่ง
                </h2>

                <ul v-if="orders.length" class="divide-y">
                    <li v-for="order in orders" :key="order.id" class="px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">{{ order.order_no }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ dateTime(order.opened_at) }} · {{ order.type_label }} · {{ order.status_label }}
                                </p>
                                <p v-if="order.staff_discount > 0" class="text-xs text-[var(--series-1)]">
                                    ใช้สิทธิ์พนักงาน ประหยัด {{ money(order.staff_discount) }} ฿
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="tabular text-sm font-semibold">{{ money(order.grand_total) }} ฿</p>

                                <StarRating
                                    v-if="order.rating"
                                    :model-value="order.rating"
                                    readonly
                                    size="sm"
                                    class="mt-1"
                                />
                                <button
                                    v-else-if="order.can_review && order.track_token"
                                    class="mt-1 inline-flex items-center gap-1 text-xs text-[var(--series-1)] hover:underline"
                                    @click="reviewToken = order.track_token"
                                >
                                    <Star class="size-3" />
                                    ให้คะแนน
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>

                <EmptyState v-else title="ยังไม่มีประวัติ" description="สั่งอาหารครั้งแรกแล้วจะเห็นที่นี่" />
            </section>
        </div>

        <!-- ขอสิทธิ์พนักงาน -->
        <Modal
            v-model:open="showEmployeeForm"
            title="ขอสิทธิ์พนักงานองค์กร"
            description="HR จะตรวจสอบรหัสก่อนอนุมัติ"
        >
            <form class="space-y-3" @submit.prevent="submitEmployeeRequest">
                <div class="space-y-1">
                    <Label for="ecode">รหัสพนักงาน</Label>
                    <Input id="ecode" v-model="employeeCode" class="h-11" required />
                </div>
                <div class="space-y-1">
                    <Label for="dept">แผนก (ไม่บังคับ)</Label>
                    <Input id="dept" v-model="department" class="h-11" />
                </div>

                <p class="text-xs text-muted-foreground">
                    ส่วนลดที่ได้จะถูกบันทึกเป็นสวัสดิการที่บริษัทออกให้ และมีเพดานวงเงินต่อเดือน
                </p>

                <div class="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" @click="showEmployeeForm = false">ยกเลิก</Button>
                    <Button type="submit" variant="brand" :disabled="!employeeCode.trim()">ส่งคำขอ</Button>
                </div>
            </form>
        </Modal>

        <ReviewSheet
            v-if="reviewToken"
            :token="reviewToken"
            :open="true"
            @update:open="(v) => !v && (reviewToken = null)"
        />
    </div>
</template>
