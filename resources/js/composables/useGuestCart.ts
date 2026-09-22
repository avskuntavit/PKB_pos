import { computed, ref, watch } from 'vue'
import type { Product } from '@/types'

export interface CartLine {
    /** key รวม product + ตัวเลือก + หมายเหตุ เพื่อยุบรายการเหมือนกันเป็นบรรทัดเดียว */
    key: string
    product_id: number
    name: string
    unit_price: number
    qty: number
    modifier_ids: number[]
    modifier_names: string[]
    note: string | null
}

/**
 * ตะกร้าของลูกค้า — เก็บใน localStorage แยกตามโต๊ะ
 * เผื่อกดรีเฟรชหรือสลับแอปแล้วกลับมา ของในตะกร้ายังอยู่
 */
export function useGuestCart(tableKey: string) {
    const storageKey = `foodpos.cart.${tableKey}`
    const lines = ref<CartLine[]>(read())

    function read(): CartLine[] {
        try {
            const raw = localStorage.getItem(storageKey)
            return raw ? (JSON.parse(raw) as CartLine[]) : []
        } catch {
            // โหมดไม่ระบุตัวตน หรือ storage ถูกปิด — ใช้ตะกร้าในหน่วยความจำแทน
            return []
        }
    }

    watch(
        lines,
        (value) => {
            try {
                localStorage.setItem(storageKey, JSON.stringify(value))
            } catch {
                /* เขียนไม่ได้ก็ไม่เป็นไร ตะกร้ายังทำงานได้ในรอบนี้ */
            }
        },
        { deep: true },
    )

    function add(
        product: Product,
        qty: number,
        modifierIds: number[],
        modifierNames: string[],
        note: string | null,
        unitPrice: number,
    ) {
        const key = `${product.id}|${[...modifierIds].sort().join(',')}|${note ?? ''}`
        const existing = lines.value.find((l) => l.key === key)

        if (existing) {
            existing.qty += qty
            return
        }

        lines.value.push({
            key,
            product_id: product.id,
            name: product.name,
            unit_price: unitPrice,
            qty,
            modifier_ids: modifierIds,
            modifier_names: modifierNames,
            note,
        })
    }

    function setQty(key: string, qty: number) {
        const index = lines.value.findIndex((l) => l.key === key)

        if (index === -1) return

        if (qty <= 0) {
            lines.value.splice(index, 1)
            return
        }

        lines.value[index].qty = qty
    }

    function clear() {
        lines.value = []
    }

    const count = computed(() => lines.value.reduce((sum, l) => sum + l.qty, 0))
    const total = computed(() => lines.value.reduce((sum, l) => sum + l.unit_price * l.qty, 0))

    const payload = computed(() =>
        lines.value.map((l) => ({
            product_id: l.product_id,
            qty: l.qty,
            modifier_ids: l.modifier_ids,
            note: l.note,
        })),
    )

    return { lines, add, setQty, clear, count, total, payload }
}
