/** ตัวช่วยจัดรูปแบบตัวเลข/วันที่ แบบไทย ใช้ร่วมกันทุกหน้า */

const bahtFormatter = new Intl.NumberFormat('th-TH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})

const compactFormatter = new Intl.NumberFormat('th-TH', {
    notation: 'compact',
    maximumFractionDigits: 1,
})

export function money(value: number | string | null | undefined): string {
    return bahtFormatter.format(Number(value ?? 0))
}

/** ใช้บนแกนกราฟ ที่พื้นที่จำกัด เช่น 12.5พัน */
export function moneyCompact(value: number | string | null | undefined): string {
    return compactFormatter.format(Number(value ?? 0))
}

export function number(value: number | string | null | undefined, digits = 0): string {
    return new Intl.NumberFormat('th-TH', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(Number(value ?? 0))
}

export function percent(value: number | null | undefined, digits = 2): string {
    return `${number(value ?? 0, digits)}%`
}

export function date(value: string | Date | null | undefined): string {
    if (!value) return '-'
    return new Date(value).toLocaleDateString('th-TH', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    })
}

export function dateTime(value: string | Date | null | undefined): string {
    if (!value) return '-'
    return new Date(value).toLocaleString('th-TH', {
        day: '2-digit',
        month: 'short',
        year: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    })
}

export function time(value: string | Date | null | undefined): string {
    if (!value) return '-'
    return new Date(value).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })
}

/** 75 นาที -> "1 ชม. 15 นาที" */
export function duration(minutes: number): string {
    if (minutes < 60) return `${number(minutes, 0)} นาที`
    const h = Math.floor(minutes / 60)
    const m = Math.round(minutes % 60)
    return m === 0 ? `${h} ชม.` : `${h} ชม. ${m} นาที`
}

export function todayIso(): string {
    return new Date().toLocaleDateString('sv-SE') // yyyy-mm-dd ตามเวลาเครื่อง
}
