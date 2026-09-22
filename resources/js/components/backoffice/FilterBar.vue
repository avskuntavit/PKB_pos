<script setup lang="ts">
/**
 * แถบกรอง สาขา + ช่วงวันที่ — อยู่บนสุดของทุกหน้ารายงาน
 * กด "ตกลง" แล้วยิง Inertia visit พร้อม query string (preserveState ไว้กันหน้ากระพริบ)
 */
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { Search } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import type { Branch } from '@/types'

const props = defineProps<{
    branches: Branch[]
    filters: { from: string; to: string; branch_ids?: number[] }
    /** route ที่จะยิงกลับ — ปล่อยว่างจะใช้ URL ปัจจุบัน */
    action?: string
}>()

const from = ref(props.filters.from)
const to = ref(props.filters.to)
const branchId = ref<number | string>(props.filters.branch_ids?.[0] ?? '')

watch(
    () => props.filters,
    (f) => {
        from.value = f.from
        to.value = f.to
        branchId.value = f.branch_ids?.[0] ?? ''
    },
)

function apply() {
    router.get(
        props.action ?? window.location.pathname,
        {
            from: from.value,
            to: to.value,
            ...(branchId.value ? { branch_ids: [branchId.value] } : {}),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

/** ปุ่มลัดช่วงเวลาที่ใช้บ่อย */
function quickRange(days: number) {
    const end = new Date()
    const start = new Date()
    start.setDate(end.getDate() - days + 1)

    from.value = start.toLocaleDateString('sv-SE')
    to.value = end.toLocaleDateString('sv-SE')
    apply()
}
</script>

<template>
    <div class="rounded-xl border bg-card p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[180px] flex-1 space-y-1">
                <Label for="branch">สาขา</Label>
                <Select id="branch" v-model="branchId">
                    <option value="">ทุกสาขาที่เข้าถึงได้</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </Select>
            </div>

            <div class="space-y-1">
                <Label for="from">ตั้งแต่วันที่</Label>
                <Input id="from" v-model="from" type="date" class="w-[160px]" />
            </div>

            <div class="space-y-1">
                <Label for="to">ถึงวันที่</Label>
                <Input id="to" v-model="to" type="date" class="w-[160px]" />
            </div>

            <Button variant="brand" @click="apply">
                <Search />
                ตกลง
            </Button>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <Button variant="outline" size="sm" @click="quickRange(1)">วันนี้</Button>
            <Button variant="outline" size="sm" @click="quickRange(7)">7 วันล่าสุด</Button>
            <Button variant="outline" size="sm" @click="quickRange(30)">30 วันล่าสุด</Button>
        </div>
    </div>
</template>
