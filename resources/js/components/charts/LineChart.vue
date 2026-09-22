<script setup lang="ts">
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
    type ChartOptions,
} from 'chart.js'
import { useChartTheme } from '@/composables/useChartTheme'
import { money, moneyCompact } from '@/lib/format'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip, Legend)

const props = withDefaults(
    defineProps<{
        labels: string[]
        values: number[]
        /** ชื่อชุดข้อมูล ใช้ในทูลทิป */
        name?: string
        height?: number
        /** จัดรูปแบบค่าเป็นเงินบาท */
        asMoney?: boolean
    }>(),
    { name: 'ยอดขาย', height: 240, asMoney: true },
)

const { theme } = useChartTheme()

const chartData = computed(() => ({
    labels: props.labels,
    datasets: [
        {
            label: props.name,
            data: props.values,
            borderColor: theme.value.series[0],
            backgroundColor: `color-mix(in oklab, ${theme.value.series[0]} 14%, transparent)`,
            borderWidth: 2,
            pointRadius: props.values.length > 40 ? 0 : 3,
            pointHoverRadius: 5,
            pointBackgroundColor: theme.value.series[0],
            pointBorderColor: theme.value.surface,
            pointBorderWidth: 2,
            fill: true,
            tension: 0.25,
        },
    ],
}))

const options = computed<ChartOptions<'line'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        // ชุดข้อมูลเดียว หัวข้อการ์ดบอกอยู่แล้วว่าคืออะไร จึงไม่ต้องมี legend
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) =>
                    ` ${ctx.dataset.label}: ${props.asMoney ? money(ctx.parsed.y) + ' บาท' : ctx.parsed.y}`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: { color: theme.value.text, font: { size: 11 }, maxRotation: 0, autoSkipPadding: 16 },
            border: { color: theme.value.grid },
        },
        y: {
            beginAtZero: true,
            grid: { color: theme.value.grid },
            border: { display: false },
            ticks: {
                color: theme.value.text,
                font: { size: 11 },
                callback: (v) => (props.asMoney ? moneyCompact(Number(v)) : v),
            },
        },
    },
}))
</script>

<template>
    <div :style="{ height: `${height}px` }">
        <Line :data="chartData" :options="options" />
    </div>
</template>
