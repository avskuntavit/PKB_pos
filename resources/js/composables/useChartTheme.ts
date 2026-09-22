import { onMounted, ref } from 'vue'

/**
 * อ่านสีจาก CSS variable ของธีมปัจจุบัน แล้วส่งให้ Chart.js
 * ทำแบบนี้เพื่อให้กราฟเปลี่ยนตามโหมดสว่าง/มืดโดยไม่ต้อง hardcode สีในสองที่
 */
export function useChartTheme() {
    const theme = ref({
        series: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'],
        grid: 'rgba(0,0,0,0.08)',
        text: '#52514e',
        surface: '#ffffff',
    })

    function read() {
        if (typeof window === 'undefined') return

        const style = getComputedStyle(document.documentElement)
        const get = (name: string, fallback: string) => style.getPropertyValue(name).trim() || fallback

        theme.value = {
            series: Array.from({ length: 8 }, (_, i) =>
                get(`--series-${i + 1}`, theme.value.series[i]),
            ),
            grid: get('--grid-line', theme.value.grid),
            text: get('--muted-foreground', theme.value.text),
            surface: get('--card', theme.value.surface),
        }
    }

    onMounted(() => {
        read()

        // ตามธีมระบบเมื่อผู้ใช้สลับโหมดกลางคัน
        window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener('change', read)
    })

    return { theme, read }
}
