<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ArrowLeft, Printer, RotateCcw } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import QrCard from '@/components/backoffice/QrCard.vue'
import Button from '@/components/ui/Button.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

defineProps<{
    branch: { id: number; name: string; code: string } | null
    /** ลิงก์เปิดเมนูของร้านนี้ตรง ๆ — ใช้ทำ QR วางหน้าเคาน์เตอร์ */
    stationUrl: string
    tables: Array<{ id: number; name: string; zone: string | null; seats: number; url: string }>
}>()

function print() {
    window.print()
}

function regenerate(tableId: number, name: string) {
    if (!confirm(`ออก QR ใหม่ให้โต๊ะ ${name}? QR แผ่นเดิมที่ติดอยู่จะใช้ไม่ได้ทันที`)) return

    router.post(`/backoffice/tables/${tableId}/qr`, {}, { preserveScroll: true })
}
</script>

<template>
    <Head title="QR สั่งอาหารประจำโต๊ะ" />

    <BackOfficeLayout title="QR สั่งอาหารประจำโต๊ะ">
        <div class="no-print space-y-3">
            <Link
                href="/backoffice/tables"
                class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-4" />
                กลับไปจัดการโต๊ะ
            </Link>

            <div class="rounded-xl border bg-card p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium">พิมพ์แผ่น QR ไปตั้งที่ร้าน</p>
                        <p class="text-xs text-muted-foreground">
                            QR เป็นแบบถาวรต่อโต๊ะ — พิมพ์ครั้งเดียวใช้ได้ตลอด ไม่ต้องเปลี่ยนรายวัน
                        </p>
                    </div>
                    <Button variant="brand" @click="print">
                        <Printer />
                        พิมพ์ทั้งหมด
                    </Button>
                </div>

                <ul class="mt-3 space-y-1 border-t pt-3 text-xs text-muted-foreground">
                    <li>· <strong>QR ของร้าน</strong> วางหน้าเคาน์เตอร์หรือติดป้ายหน้าร้าน — สแกนแล้วเข้าเมนูร้านนี้เลย ไม่ต้องเลือกร้านเอง</li>
                    <li>· <strong>QR ประจำโต๊ะ</strong> สแกนแล้วได้เมนูหน้าเดียวกัน แต่ระบบรู้ว่านั่งโต๊ะไหน บิลจะวิ่งเข้าโต๊ะนั้นให้เอง</li>
                    <li>· ลูกค้าสั่งได้ทันทีโดยไม่ต้องล็อกอิน แต่จะไม่ได้แต้มและสิทธิ์เฉพาะสมาชิก</li>
                    <li>· ออเดอร์ที่ลูกค้าส่งเข้ามาต้องให้พนักงานกดรับก่อนเข้าครัว ดูที่หน้าออเดอร์ล่วงหน้า</li>
                    <li>· ถ้าสงสัยว่า QR โต๊ะหลุดออกไปข้างนอก กด "ออก QR ใหม่" แล้วพิมพ์ไปเปลี่ยนที่โต๊ะ</li>
                </ul>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 print:grid-cols-2 print:gap-3">
            <!-- QR ของร้าน — ไม่ผูกโต๊ะ ใช้สำหรับสั่งกลับบ้าน/มารับเอง -->
            <div class="space-y-2">
                <QrCard
                    heading="สั่งกลับบ้าน"
                    sub="วางหน้าเคาน์เตอร์ / ติดป้ายหน้าร้าน"
                    :branch-name="branch?.name ?? ''"
                    :url="stationUrl"
                    caption="สแกนเพื่อดูเมนูและสั่ง"
                    hint="ไม่ต้องติดตั้งแอป · สั่งแล้วรอรับเลขคิว"
                />
                <p class="no-print text-center text-xs text-muted-foreground">QR ของร้าน</p>
            </div>

            <div v-for="table in tables" :key="table.id" class="space-y-2">
                <QrCard
                    :heading="`โต๊ะ ${table.name}`"
                    :sub="table.zone"
                    :branch-name="branch?.name ?? ''"
                    :url="table.url"
                    hint="ไม่ต้องติดตั้งแอป · สั่งเพิ่มได้ตลอดจนกว่าจะเช็คบิล"
                />
                <Button
                    variant="ghost"
                    size="sm"
                    class="no-print w-full text-xs text-muted-foreground"
                    @click="regenerate(table.id, table.name)"
                >
                    <RotateCcw />
                    ออก QR ใหม่
                </Button>
            </div>
        </div>

        <EmptyState
            v-if="!tables.length"
            class="no-print"
            title="ยังไม่มีโต๊ะ"
            description="QR ของร้านใช้ได้เลย ส่วน QR ประจำโต๊ะต้องเพิ่มโต๊ะก่อน"
        />
    </BackOfficeLayout>
</template>
