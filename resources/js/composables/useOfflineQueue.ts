import { computed, ref } from 'vue'

/**
 * คิวของคำสั่งที่กดตอนเน็ตหลุด รอส่งขึ้นระบบเมื่อกลับมาออนไลน์
 *
 * ── ขอบเขตที่ตกลงกันไว้ ──────────────────────────────────────────────────
 * เก็บได้แค่ "เพิ่มรายการ" กับ "ส่งครัว" และเฉพาะในบิลที่เปิดไว้ก่อนหลุดแล้ว
 * เปิดบิลใหม่ตอนหลุดไม่ได้ (ต้องได้เลขบิลจากเซิร์ฟเวอร์)
 * แก้จำนวนหรือลบรายการที่ขึ้นระบบไปแล้วก็ไม่ได้ (ต้องใช้ id ที่อาจถูกคนอื่นแก้ไปแล้ว)
 *
 * ── ทำไมเป็น singleton ──────────────────────────────────────────────────
 * หน้าจอ POS มีสองที่ที่ต้องเห็นคิวใบเดียวกัน — ตัวบิลที่ต้องโชว์รายการรอส่ง
 * และแถบเตือนด้านบนที่ต้องบอกว่าค้างกี่รายการ ถ้าต่างคนต่างถือ state
 * ตัวเลขสองที่จะไม่ตรงกันทันทีที่กดเพิ่มของ
 *
 * ── ทำไม localStorage ไม่ใช่ IndexedDB ──────────────────────────────────
 * ตกลงกันว่าไม่ทำ service worker ดังนั้นรีเฟรชตอนหลุด = แอปหายอยู่ดี
 * แต่ localStorage ทำให้ "ของที่คีย์ไว้ยังอยู่" โดยแทบไม่ต้องเขียนโค้ดเพิ่ม
 * พอเซิร์ฟเวอร์กลับมาแล้วเปิดหน้าใหม่ คิวยังครบและซิงก์ขึ้นได้ตามปกติ
 * IndexedDB ให้ประโยชน์เพิ่มเฉพาะตอนข้อมูลเยอะกว่า 5MB ซึ่งคิวไม่กี่สิบรายการไม่มีทางถึง
 */

export type OfflineEntryKind = 'add_item' | 'send_kitchen' | 'pay_cash'

export interface AddItemPayload {
    product_id: number
    qty: number
    modifier_ids: number[]
    note: string | null
    open_price: number | null
    /** ชื่อ/ราคา ณ ตอนกด — ใช้วาดบนจอระหว่างรอเท่านั้น ไม่ได้ส่งไปคิดเงิน */
    name: string
    unit_price: number
    modifier_names: string[]
}

/**
 * รับเงินสดตอนเน็ตหลุด
 *
 * expected_total คือยอดบิลที่เซิร์ฟเวอร์คิดไว้และพนักงานเห็นบนจอตอนเก็บเงิน
 * ส่งขึ้นไปเพื่อให้เซิร์ฟเวอร์เทียบว่ายอดเปลี่ยนไประหว่างที่เครื่องหลุดหรือเปล่า
 * **ไม่ได้ส่งไปเพื่อให้ใช้เป็นยอดบิล** — ยอดจริงเซิร์ฟเวอร์คิดใหม่ของมันเอง
 */
export interface PayCashPayload {
    expected_total: number
    received: number
    /** เงินทอนที่โชว์ให้ลูกค้าดู — ของแสดงผลล้วน เซิร์ฟเวอร์คิดใหม่ตอนปิดบิล */
    change: number
    /** เลขอ้างอิงบนสลิปชั่วคราว ให้พนักงานจดใส่กระดาษได้ */
    slip_no: string
    order_no: string | null
}

export interface QueueEntry {
    uuid: string
    kind: OfflineEntryKind
    order_id: number
    /** เวลาตามนาฬิกาเครื่องนี้ — เก็บไว้ดูย้อนหลัง เซิร์ฟเวอร์ไม่เอาไปคิดอะไร */
    at: string
    payload: AddItemPayload | PayCashPayload | null
    /** เหตุผลที่ส่งไม่ผ่านรอบล่าสุด — null = ยังไม่เคยลอง หรือลองแล้วยังไม่ได้คำตอบ */
    error: string | null
}

interface SyncResult {
    uuid: string
    status: 'applied' | 'duplicate' | 'failed' | 'held'
    message: string | null
    warning?: string | null
}

/** ใบเพิ่มรายการที่รู้แน่ว่ามี payload ครบ — เทมเพลตจะได้ไม่ต้องใส่ ?. ทุกจุด */
export type AddItemEntry = QueueEntry & { payload: AddItemPayload }

export type PayCashEntry = QueueEntry & { payload: PayCashPayload }

function isAddItem(entry: QueueEntry): entry is AddItemEntry {
    return entry.kind === 'add_item' && entry.payload !== null
}

function isPayCash(entry: QueueEntry): entry is PayCashEntry {
    return entry.kind === 'pay_cash' && entry.payload !== null
}

const STORAGE_KEY = 'foodpos.offline.queue'

/** ค้างได้มากสุดเท่าไหร่ ตรงกับ MAX_ENTRIES ฝั่งเซิร์ฟเวอร์ */
const MAX_ENTRIES = 100

/*
| state อยู่นอกฟังก์ชัน = ทุกที่ที่เรียก useOfflineQueue() ได้คิวใบเดียวกัน
*/
const entries = ref<QueueEntry[]>(readStored())
const syncing = ref(false)
const lastSyncMessage = ref<string | null>(null)

function readStored(): QueueEntry[] {
    try {
        const raw = localStorage.getItem(STORAGE_KEY)
        const parsed = raw ? JSON.parse(raw) : []

        return Array.isArray(parsed) ? (parsed as QueueEntry[]) : []
    } catch {
        // โหมดไม่ระบุตัวตน หรือ storage เต็ม — ยังทำงานต่อได้ แค่ไม่รอดการรีเฟรช
        return []
    }
}

function persist() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(entries.value))
    } catch {
        /* เขียนไม่ได้ก็ไม่เป็นไร คิวในหน่วยความจำยังใช้ได้ตลอดที่หน้ายังเปิดอยู่ */
    }
}

function newUuid(): string {
    const uuid = globalThis.crypto?.randomUUID?.()

    if (uuid) return uuid

    // แท็บเล็ตเก่า หรือหน้าที่ไม่ได้เสิร์ฟผ่าน https จะไม่มี crypto.randomUUID
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

    return match ? decodeURIComponent(match[1]) : ''
}

export function useOfflineQueue() {
    const count = computed(() => entries.value.length)
    const hasPending = computed(() => entries.value.length > 0)

    /** ใบที่ล้มไปแล้ว — ส่งซ้ำไม่ได้ พนักงานต้องคีย์ใหม่หรือเอาออก */
    const failed = computed(() => entries.value.filter((e) => e.error !== null))

    /** ใบที่ยังรอส่งอยู่จริง ๆ */
    const waiting = computed(() => entries.value.filter((e) => e.error === null))

    /** รายการรอส่งของบิลใบนี้ เรียงตามที่กด */
    function addItemsFor(orderId: number): AddItemEntry[] {
        return entries.value.filter((e): e is AddItemEntry => isAddItem(e) && e.order_id === orderId)
    }

    /** ใบรับเงินที่ค้างอยู่ของบิลนี้ — มีได้ใบเดียว */
    function paymentFor(orderId: number): PayCashEntry | null {
        return entries.value.find((e): e is PayCashEntry => isPayCash(e) && e.order_id === orderId) ?? null
    }

    function hasPendingPayment(orderId: number): boolean {
        return paymentFor(orderId) !== null
    }

    /** เงินที่รับมาแล้วแต่ยังไม่ขึ้นระบบ รวมทุกบิล — แถบเตือนใช้ตัวเลขนี้ */
    const heldCash = computed(() =>
        entries.value
            .filter(isPayCash)
            .reduce((sum, e) => sum + e.payload.received, 0),
    )

    /** บิลใบนี้มีคำสั่งส่งครัวค้างอยู่ไหม */
    function hasPendingSend(orderId: number): boolean {
        return entries.value.some((e) => e.kind === 'send_kitchen' && e.order_id === orderId)
    }

    /** ยอดรวมของที่ยังไม่ขึ้นระบบ — ต้องบวกเข้ากับยอดบิลที่เซิร์ฟเวอร์ส่งมา */
    function pendingTotalFor(orderId: number): number {
        return addItemsFor(orderId).reduce((sum, e) => sum + e.payload.unit_price * e.payload.qty, 0)
    }

    function enqueueAddItem(orderId: number, payload: AddItemPayload): QueueEntry | null {
        if (entries.value.length >= MAX_ENTRIES) {
            lastSyncMessage.value = `คิวเต็ม (${MAX_ENTRIES} รายการ) ต้องให้ระบบกลับมาก่อนจึงจะคีย์ต่อได้`

            return null
        }

        const entry: QueueEntry = {
            uuid: newUuid(),
            kind: 'add_item',
            order_id: orderId,
            at: new Date().toISOString(),
            payload,
            error: null,
        }

        entries.value.push(entry)
        persist()

        return entry
    }

    /**
     * กดส่งครัวตอนหลุด — ตั้งธงไว้ใบเดียวต่อบิล
     *
     * กดซ้ำไม่เพิ่มธง เพราะคำสั่งนี้แปลว่า "ส่งทุกอย่างที่ค้าง" อยู่แล้ว
     * ถ้าเก็บสองใบ ตอน replay จะได้ใบสั่งครัวสองใบ ครัวทำสองรอบ
     */
    function enqueueSend(orderId: number): QueueEntry | null {
        if (hasPendingSend(orderId)) return null

        const entry: QueueEntry = {
            uuid: newUuid(),
            kind: 'send_kitchen',
            order_id: orderId,
            at: new Date().toISOString(),
            payload: null,
            error: null,
        }

        entries.value.push(entry)
        persist()

        return entry
    }

    /**
     * รับเงินสดตอนเน็ตหลุด
     *
     * ── กติกาที่ต่างจากใบอื่นทั้งหมด ──────────────────────────────────────
     * ใบนี้แทนเงินจริงในลิ้นชัก ลูกค้าเดินออกจากร้านไปแล้ว จึงมีข้อจำกัดของตัวเอง
     * · หนึ่งบิลมีได้ใบเดียว — กดซ้ำไม่เกิดใบใหม่ ไม่งั้นบิลถูกปิดสองรอบ
     * · แก้จำนวนหรือลบออกจากคิวเองไม่ได้ (ปุ่มลบไม่มีให้กด) เพราะการลบใบนี้ทิ้ง
     *   คือการทำให้เงินก้อนหนึ่งหายจากระบบโดยไม่มีร่องรอย
     * · ถ้าเซิร์ฟเวอร์ลงบิลไม่ได้ ใบนี้จะไม่กลับมาเป็นงานของพนักงาน แต่กลายเป็น
     *   งานของผู้จัดการที่หน้า "เงินค้างจากตอนระบบล่ม"
     */
    function enqueuePayment(
        orderId: number,
        payload: PayCashPayload,
    ): PayCashEntry | null {
        if (hasPendingPayment(orderId)) return null

        if (entries.value.length >= MAX_ENTRIES) {
            lastSyncMessage.value = `คิวเต็ม (${MAX_ENTRIES} รายการ) ต้องให้ระบบกลับมาก่อน`

            return null
        }

        const entry: PayCashEntry = {
            uuid: newUuid(),
            kind: 'pay_cash',
            order_id: orderId,
            at: new Date().toISOString(),
            payload,
            error: null,
        }

        entries.value.push(entry)
        persist()

        return entry
    }

    /** เลขอ้างอิงบนสลิปชั่วคราว — สั้นพอที่พนักงานจดใส่กระดาษได้ */
    function newSlipNo(): string {
        const now = new Date()
        const pad = (n: number) => String(n).padStart(2, '0')
        const stamp = `${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`

        return `X${stamp}`
    }

    /**
     * แก้จำนวนของรายการที่ยังไม่ขึ้นระบบ — แก้ในเครื่องได้เลย
     *
     * ไม่ต้องผ่านเซิร์ฟเวอร์เพราะของชิ้นนี้ยังไม่เคยไปถึงที่ไหน
     * 0 หรือน้อยกว่า = เอาออกจากคิว เหมือนปุ่มลบในตะกร้าปกติ
     */
    function setPendingQty(uuid: string, qty: number) {
        const entry = entries.value.find((e) => e.uuid === uuid)

        // ใบรับเงินแก้จำนวนไม่ได้ — เงินที่ลูกค้ายื่นมาแล้วไม่ใช่ตัวเลขที่ใครแก้ได้
        if (!entry || !isAddItem(entry)) return

        if (qty <= 0) {
            removePending(uuid)

            return
        }

        entry.payload.qty = qty
        persist()
    }

    /**
     * เอาใบออกจากคิว
     *
     * ใบรับเงินเอาออกไม่ได้ — เงินอยู่ในลิ้นชักแล้ว การลบใบทิ้งคือการทำให้
     * เงินก้อนหนึ่งหายจากระบบโดยไม่มีร่องรอย ต้องปล่อยให้มันขึ้นไปถึงเซิร์ฟเวอร์
     * แล้วให้ผู้จัดการเป็นคนตัดสิน ไม่ใช่พนักงานที่หน้าจอ
     */
    function removePending(uuid: string): boolean {
        const entry = entries.value.find((e) => e.uuid === uuid)

        if (!entry || isPayCash(entry)) return false

        entries.value = entries.value.filter((e) => e.uuid !== uuid)
        persist()

        return true
    }

    /** ล้างคิว — ยกเว้นใบรับเงิน ด้วยเหตุผลเดียวกับ removePending() */
    function clear() {
        entries.value = entries.value.filter(isPayCash)
        persist()
    }

    /**
     * ส่งคิวทั้งก้อนขึ้นระบบ
     *
     * ส่งตามลำดับที่กดเสมอ (ไม่เรียงใหม่) เพราะ "เพิ่มรายการ" ต้องถึงก่อน "ส่งครัว"
     * ไม่งั้นของจานสุดท้ายจะค้างอยู่ในบิลโดยครัวไม่เห็น
     *
     * รายการที่สำเร็จหรือซ้ำถูกเอาออกจากคิว รายการที่ล้มถูกเก็บไว้พร้อมเหตุผล
     * ให้พนักงานตัดสินใจเองว่าจะลบทิ้งหรือคีย์ใหม่ — ระบบไม่ลบของที่ลูกค้ากินไปแล้วเอง
     */
    async function sync(): Promise<boolean> {
        if (syncing.value || entries.value.length === 0) return true

        syncing.value = true


        /*
        | ส่งเฉพาะใบที่ยังไม่เคยล้ม
        |
        | ใบที่ล้มไปแล้วส่งซ้ำไม่มีวันสำเร็จ เพราะเซิร์ฟเวอร์จองคีย์ uuid นั้นไว้แล้ว
        | ตอนพยายามครั้งแรก (ต้องจองก่อนทำงาน ไม่งั้นงานที่สำเร็จแล้วแต่บันทึกผลไม่ทัน
        | จะถูกทำซ้ำ) ส่งไปก็ได้คำตอบว่า "ซ้ำ" แล้วใบนั้นจะหายจากคิวไปเงียบ ๆ
        | ทั้งที่ของยังไม่เคยขึ้นบิล — พนักงานต้องคีย์ใหม่ ไม่ใช่กดส่งซ้ำ
        */
        const sending = entries.value.filter((e) => e.error === null).slice(0, MAX_ENTRIES)

        if (sending.length === 0) {
            syncing.value = false

            return true
        }

        try {
            const response = await fetch('/pos/offline/sync', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    entries: sending.map((e) => ({
                        uuid: e.uuid,
                        kind: e.kind,
                        order_id: e.order_id,
                        at: e.at,
                        payload: e.payload,
                    })),
                }),
            })

            if (!response.ok) throw new Error(`HTTP ${response.status}`)

            const body = (await response.json()) as { results?: SyncResult[] }
            const results = body.results ?? []

            applyResults(results)

            const failedNow = results.filter((r) => r.status === 'failed').length
            const appliedNow = results.filter((r) => r.status === 'applied').length
            const heldNow = results.filter((r) => r.status === 'held')

            const parts = [`ส่งขึ้นระบบแล้ว ${appliedNow} รายการ`]

            if (failedNow > 0) parts.push(`ไม่สำเร็จ ${failedNow} รายการ ดูรายละเอียดในบิล`)

            /*
            | เงินที่ลงบิลไม่ได้ต้องพูดให้ชัดที่สุดในบรรดาข้อความทั้งหมด
            | พนักงานที่เห็นแค่ "ไม่สำเร็จ" จะคิดว่าต้องไปเก็บเงินลูกค้าใหม่
            | ทั้งที่เงินอยู่ในลิ้นชักแล้ว และเรื่องนี้ไม่ใช่งานของเขาแล้ว
            */
            if (heldNow.length > 0) {
                parts.push(
                    `เงิน ${heldNow.length} รายการลงบิลไม่ได้ — ส่งให้ผู้จัดการตัดสินแล้ว `
                    + `เงินยังอยู่ในลิ้นชัก ไม่ต้องเก็บจากลูกค้าใหม่`,
                )
            }

            lastSyncMessage.value = parts.join(' · ')

            return failedNow === 0 && heldNow.length === 0
        } catch {
            /*
            | ส่งไม่ถึงเลย = ไม่รู้ว่าเซิร์ฟเวอร์ทำไปหรือยัง **ห้ามลบอะไรออกจากคิว**
            | รอบหน้าส่งซ้ำได้ปลอดภัย เพราะ uuid เดิมจะถูกปฏิเสธที่ฐานข้อมูล
            */
            lastSyncMessage.value = 'ยังส่งขึ้นระบบไม่ได้ จะลองใหม่ให้อัตโนมัติ'

            return false
        } finally {
            syncing.value = false
        }
    }

    function applyResults(results: SyncResult[]) {
        /*
        | held ออกจากคิวด้วย ไม่ใช่ค้างไว้เหมือน failed
        |
        | เพราะเซิร์ฟเวอร์บันทึกใบนั้นไว้แล้วพร้อมยอดเงิน และส่งต่อให้ผู้จัดการแล้ว
        | ถ้าเก็บไว้ในเครื่องต่อ เครื่องจะพยายามส่งซ้ำไปเรื่อย ๆ โดยไม่มีวันสำเร็จ
        | และพนักงานจะเห็นตัวเลข "ค้างอยู่" ที่ไม่ใช่งานของเขาอีกต่อไปแล้ว
        */
        const done = new Set(
            results.filter((r) => r.status !== 'failed').map((r) => r.uuid),
        )
        const errors = new Map(
            results.filter((r) => r.status === 'failed').map((r) => [r.uuid, r.message]),
        )

        entries.value = entries.value
            .filter((e) => !done.has(e.uuid))
            .map((e) => (errors.has(e.uuid) ? { ...e, error: errors.get(e.uuid) ?? 'ไม่สำเร็จ' } : e))

        persist()
    }

    function dismissMessage() {
        lastSyncMessage.value = null
    }

    return {
        entries,
        count,
        hasPending,
        failed,
        waiting,
        heldCash,
        syncing,
        lastSyncMessage,
        addItemsFor,
        hasPendingSend,
        pendingTotalFor,
        paymentFor,
        hasPendingPayment,
        enqueueAddItem,
        enqueueSend,
        enqueuePayment,
        newSlipNo,
        setPendingQty,
        removePending,
        clear,
        sync,
        dismissMessage,
    }
}
