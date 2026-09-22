<script setup lang="ts">
import { computed } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import BrandMark from '@/components/ui/BrandMark.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import type { PageProps } from '@/types'

const page = usePage<PageProps>()
const brandName = computed(() => page.props.brand?.name ?? 'PKB POS')

const form = useForm({ email: '', password: '', remember: false })

function submit() {
    form.post('/login', { onFinish: () => form.reset('password') })
}
</script>

<template>
    <Head title="เข้าสู่ระบบ" />

    <div class="grid min-h-screen place-items-center bg-muted/40 p-4">
        <div class="w-full max-w-sm space-y-6 rounded-xl border bg-card p-6 shadow-sm">
            <div class="space-y-2 text-center">
                <BrandMark :height="44" class="justify-center" />
                <h1 class="text-xl font-semibold">{{ brandName }}</h1>
                <p class="text-xs text-muted-foreground">ระบบขายหน้าร้านและหลังบ้านสำหรับร้านอาหาร</p>
            </div>

            <form class="space-y-3" @submit.prevent="submit">
                <div class="space-y-1">
                    <Label for="email">อีเมล</Label>
                    <Input id="email" v-model="form.email" type="email" required autofocus />
                </div>

                <div class="space-y-1">
                    <Label for="password">รหัสผ่าน</Label>
                    <Input id="password" v-model="form.password" type="password" required />
                </div>

                <p v-if="form.errors.email" class="text-xs text-[var(--status-critical)]">
                    {{ form.errors.email }}
                </p>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.remember" type="checkbox" class="size-4" />
                    จดจำการเข้าสู่ระบบ
                </label>

                <Button type="submit" variant="brand" size="lg" class="w-full" :disabled="form.processing">
                    เข้าสู่ระบบ
                </Button>
            </form>
        </div>
    </div>
</template>
