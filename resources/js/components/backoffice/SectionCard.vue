<script setup lang="ts">
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardContent from '@/components/ui/CardContent.vue'

defineProps<{ title: string; contentClass?: string }>()
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
            <slot name="actions" />
        </CardHeader>
        <CardContent :class="contentClass">
            <slot />
        </CardContent>
    </Card>
</template>
