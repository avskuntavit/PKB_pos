<script setup lang="ts">
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    Tooltip,
    type ChartOptions,
} from 'chart.js'
import { useChartTheme } from '@/composables/useChartTheme'
import { money, moneyCompact } from '@/lib/format'

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend)

const props = withDefaults(
    defineProps<{
        labels: string[]
        values: number[]
        name?: string
        height?: number
        asMoney?: boolean
        /** เลือกสีจากชุด series (1-8) */
        colorIndex?: number
        horizontal?: boolean
    }>(),
    { name: 'ยอดขาย', height: 240, asMoney: true, colorIndex: 0, horizontal: false },
)

const { theme } = useChartTheme()

const chartData = computed(() => ({
    labels: props.labels,
    datasets: [
        {
            label: props.name,
            data: props.values,
            backgroundColor: theme.value.series[props.colorIndex],
            hoverBackgroundColor: theme.value.series[props.colorIndex],
            // เว้นช่องว่าง 2px ระหว่างแท่ง ให้แท่งที่ติดกันไม่กลืนกัน
            borderColor: theme.value.surface,
            borderWidth: { top: 0, right: 1, bottom: 0, left: 1 },
            borderRadius: 4,
            borderSkipped: props.horizontal ? ('left' as const) : ('bottom' as const),
            maxBarThickness: 42,
        },
    ],
}))

const options = computed<ChartOptions<'bar'>>(() => ({
    indexAxis: props.horizontal ? ('y' as const) : ('x' as const),
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => {
                    const value = props.horizontal ? ctx.parsed.x : ctx.parsed.y
                    return ` ${ctx.dataset.label}: ${props.asMoney ? money(value) + ' บาท' : value}`
                },
            },
        },
    },
    scales: {
        x: {
            grid: { display: props.horizontal, color: theme.value.grid },
            border: { display: false },
            ticks: {
                color: theme.value.text,
                font: { size: 11 },
                maxRotation: 0,
                callback: props.horizontal
                    ? (v) => (props.asMoney ? moneyCompact(Number(v)) : v)
                    : undefined,
            },
        },
        y: {
            beginAtZero: true,
            grid: { display: !props.horizontal, color: theme.value.grid },
            border: { display: false },
            ticks: {
                color: theme.value.text,
                font: { size: 11 },
                callback: props.horizontal
                    ? undefined
                    : (v) => (props.asMoney ? moneyCompact(Number(v)) : v),
            },
        },
    },
}))
</script>

<template>
    <div :style="{ height: `${height}px` }">
        <Bar :data="chartData" :options="options" />
    </div>
</template>
