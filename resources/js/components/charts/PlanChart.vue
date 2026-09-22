<script setup lang="ts">
/**
 * ยอดขายจริงเทียบเป้า
 *
 * ยอดจริงเป็นแท่ง เป้าเป็นเส้นประ — สายตาอ่านได้ทันทีว่าแท่งไหนโผล่พ้นเส้น
 * วันที่ยังไม่ถึงส่ง actual เป็น null เส้นแท่งจะขาดหายไปเอง ไม่ลากดิ่งลงศูนย์
 * ซึ่งสำคัญมาก เพราะกราฟที่ดิ่งลงศูนย์กลางเดือนอ่านเหมือนยอดตก
 */
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
    type ChartData,
    type ChartOptions,
} from 'chart.js'
import { useChartTheme } from '@/composables/useChartTheme'
import { money, moneyCompact } from '@/lib/format'

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    Filler,
    Tooltip,
    Legend,
)

const props = withDefaults(
    defineProps<{
        labels: string[]
        actual: Array<number | null>
        target: number[]
        height?: number
        actualName?: string
        targetName?: string
    }>(),
    { height: 260, actualName: 'ยอดขายจริง', targetName: 'เป้า' },
)

const { theme } = useChartTheme()

/**
 * กราฟผสมแท่ง+เส้น: Chart.js รองรับเต็มที่ตอนรัน แต่ ChartData<'bar'> ในไทป์
 * ไม่เปิดช่องให้ dataset ชนิด line ปนเข้ามา จึงต้อง cast ตรงนี้จุดเดียว
 */
const chartData = computed(() => ({
    labels: props.labels,
    datasets: [
        {
            type: 'bar' as const,
            label: props.actualName,
            data: props.actual,
            backgroundColor: theme.value.series[0],
            hoverBackgroundColor: theme.value.series[0],
            borderRadius: 3,
            maxBarThickness: 26,
            order: 2,
        },
        {
            type: 'line' as const,
            label: props.targetName,
            data: props.target,
            borderColor: theme.value.series[3],
            borderWidth: 2,
            borderDash: [5, 4],
            pointRadius: 0,
            pointHoverRadius: 4,
            fill: false,
            tension: 0.2,
            order: 1,
        },
    ],
}) as unknown as ChartData<'bar'>)

const options = computed<ChartOptions<'bar'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: {
            display: true,
            position: 'top',
            align: 'end',
            labels: {
                color: theme.value.text,
                boxWidth: 12,
                boxHeight: 2,
                font: { size: 11 },
                usePointStyle: false,
            },
        },
        tooltip: {
            callbacks: {
                label: (ctx) =>
                    ctx.parsed.y === null
                        ? ''
                        : ` ${ctx.dataset.label}: ${money(ctx.parsed.y)} บาท`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            border: { color: theme.value.grid },
            ticks: { color: theme.value.text, font: { size: 10 }, maxRotation: 0, autoSkipPadding: 8 },
        },
        y: {
            beginAtZero: true,
            grid: { color: theme.value.grid },
            border: { display: false },
            ticks: {
                color: theme.value.text,
                font: { size: 11 },
                callback: (v) => moneyCompact(Number(v)),
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
