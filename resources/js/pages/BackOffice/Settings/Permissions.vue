<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { RotateCcw, ShieldCheck } from 'lucide-vue-next'
import BackOfficeLayout from '@/layouts/BackOfficeLayout.vue'
import SectionCard from '@/components/backoffice/SectionCard.vue'
import Button from '@/components/ui/Button.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import DataTable from '@/components/ui/DataTable.vue'

interface StaffRow {
    id: number
    name: string
    email: string
    role: string
    role_label: string
    is_active: boolean
    permissions: string[]
    overrides: { grant?: string[]; revoke?: string[] }
}

const props = defineProps<{
    users: StaffRow[]
    groups: Record<string, Array<{ value: string; label: string }>>
    roles: Array<{ value: string; label: string; defaults: string[] }>
}>()

const editing = ref<StaffRow | null>(null)
const role = ref('')
const isActive = ref(true)
const selected = ref<string[]>([])
const saving = ref(false)

const roleDefaults = computed(
    () => props.roles.find((r) => r.value === role.value)?.defaults ?? [],
)

/** ต่างจากค่าตั้งต้นของตำแหน่งกี่ข้อ — ช่วยให้เห็นว่าคนนี้ถูกปรับพิเศษไว้ */
const diffCount = computed(() => {
    const added = selected.value.filter((p) => !roleDefaults.value.includes(p)).length
    const removed = roleDefaults.value.filter((p) => !selected.value.includes(p)).length
    return added + removed
})

function open(user: StaffRow) {
    editing.value = user
    role.value = user.role
    isActive.value = user.is_active
    selected.value = [...user.permissions]
}

function toggle(value: string) {
    selected.value = selected.value.includes(value)
        ? selected.value.filter((p) => p !== value)
        : [...selected.value, value]
}

function resetToRoleDefaults() {
    selected.value = [...roleDefaults.value]
}

function save() {
    if (!editing.value) return

    saving.value = true

    router.put(
        `/backoffice/settings/permissions/${editing.value.id}`,
        { role: role.value, is_active: isActive.value, permissions: selected.value },
        {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
            onFinish: () => (saving.value = false),
        },
    )
}

function overrideCount(user: StaffRow): number {
    return (user.overrides?.grant?.length ?? 0) + (user.overrides?.revoke?.length ?? 0)
}
</script>

<template>
    <Head title="สิทธิ์พนักงาน" />

    <BackOfficeLayout title="สิทธิ์พนักงาน">
        <SectionCard title="พนักงานทั้งหมด" content-class="p-0">
            <p class="border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
                ตำแหน่งเป็นแค่ชุดสิทธิ์ตั้งต้น ปรับรายคนได้ —
                ระบบเก็บเฉพาะ "ส่วนต่าง" จากตำแหน่ง พอแก้ค่าตั้งต้นทีหลัง คนที่ไม่ได้ปรับพิเศษจะตามไปด้วยเอง
            </p>

            <DataTable>
                <thead>
                    <tr>
                        <th>ชื่อ</th>
                        <th>อีเมล</th>
                        <th>ตำแหน่ง</th>
                        <th class="text-right">สิทธิ์ทั้งหมด</th>
                        <th>สถานะ</th>
                        <th class="text-right">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id">
                        <td class="font-medium">{{ user.name }}</td>
                        <td class="text-muted-foreground">{{ user.email }}</td>
                        <td>
                            {{ user.role_label }}
                            <Badge v-if="overrideCount(user)" variant="warning" class="ml-1">
                                ปรับพิเศษ {{ overrideCount(user) }}
                            </Badge>
                        </td>
                        <td class="tabular text-right">{{ user.permissions.length }}</td>
                        <td>
                            <Badge :variant="user.is_active ? 'success' : 'secondary'">
                                {{ user.is_active ? 'ทำงานอยู่' : 'ปิดใช้งาน' }}
                            </Badge>
                        </td>
                        <td class="text-right">
                            <Button variant="outline" size="sm" @click="open(user)">
                                <ShieldCheck />
                                ตั้งค่าสิทธิ์
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
        </SectionCard>

        <Modal
            :open="editing !== null"
            :title="editing ? `สิทธิ์ของ ${editing.name}` : ''"
            class="max-w-2xl"
            @update:open="(v) => !v && (editing = null)"
        >
            <div class="space-y-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[160px] flex-1 space-y-1">
                        <label for="role" class="text-xs font-medium text-muted-foreground">ตำแหน่ง</label>
                        <Select id="role" v-model="role">
                            <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </Select>
                    </div>

                    <label class="flex h-9 items-center gap-2 text-sm">
                        <input v-model="isActive" type="checkbox" class="size-4" />
                        เปิดใช้งานบัญชี
                    </label>

                    <Button variant="outline" size="sm" @click="resetToRoleDefaults">
                        <RotateCcw />
                        รีเซ็ตตามตำแหน่ง
                    </Button>
                </div>

                <p v-if="diffCount > 0" class="text-xs text-[var(--status-warning)]">
                    ต่างจากค่าตั้งต้นของตำแหน่ง {{ diffCount }} ข้อ
                </p>

                <div class="max-h-[45vh] space-y-4 overflow-y-auto">
                    <section v-for="(items, groupName) in groups" :key="groupName">
                        <h3 class="mb-2 text-sm font-semibold">{{ groupName }}</h3>

                        <ul class="grid gap-1.5 sm:grid-cols-2">
                            <li v-for="item in items" :key="item.value">
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5 text-sm">
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 size-4 shrink-0"
                                        :checked="selected.includes(item.value)"
                                        @change="toggle(item.value)"
                                    />
                                    <span class="min-w-0">
                                        {{ item.label }}
                                        <span
                                            v-if="!roleDefaults.includes(item.value) && selected.includes(item.value)"
                                            class="block text-xs text-[var(--status-warning)]"
                                        >
                                            เพิ่มให้เป็นพิเศษ
                                        </span>
                                        <span
                                            v-else-if="roleDefaults.includes(item.value) && !selected.includes(item.value)"
                                            class="block text-xs text-[var(--status-critical)]"
                                        >
                                            ตัดออกจากตำแหน่ง
                                        </span>
                                    </span>
                                </label>
                            </li>
                        </ul>
                    </section>
                </div>

                <div class="flex justify-end gap-2 border-t pt-3">
                    <Button variant="outline" @click="editing = null">ยกเลิก</Button>
                    <Button variant="brand" :disabled="saving" @click="save">บันทึกสิทธิ์</Button>
                </div>
            </div>
        </Modal>
    </BackOfficeLayout>
</template>
