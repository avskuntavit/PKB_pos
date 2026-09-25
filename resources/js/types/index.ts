export type Role = 'owner' | 'manager' | 'cashier' | 'staff'

export interface AuthUser {
    id: number
    name: string
    email: string
    role: Role
    role_label: string
    branch_id: number | null
}

export interface Branch {
    id: number
    name: string
    code: string
    logo_path?: string | null
    cover_path?: string | null
    theme_color?: string | null
    vat_rate?: string
    vat_included?: boolean
    service_charge_rate?: string
    currency?: string
}

export interface MemberSummary {
    id: number
    name: string
    phone: string | null
    points: number
    tier: string
    employee_status: string | null
    is_verified_employee: boolean
}

export interface Brand {
    name: string
    /** โลโก้เต็มพร้อมพื้นขาว */
    logo: string | null
    /** โลโก้พื้นโปร่ง ใช้ในหัวจอ */
    mark: string | null
}

export interface PageProps {
    brand: Brand
    auth: { user: AuthUser | null }
    customer: MemberSummary | null
    branches: Branch[]
    currentBranch: Branch | null
    flash: { success: string | null; error: string | null }
    /** null = คนนี้ไม่มีสิทธิ์ดูสุขภาพระบบ จึงไม่ต้องขึ้นแถบเตือนให้ */
    systemAlerts: SystemAlerts | null
    [key: string]: unknown
}

/** ตัวเลขสรุปบนแถบเตือนหลังบ้าน — รายละเอียดอยู่ที่หน้า /backoffice/health */
export interface SystemAlerts {
    /** จำนวนชนิดข้อมูลที่ขาดการสำรองเกินกำหนด */
    backup_stale: number
    /** จำนวนบั๊กที่ยังไม่มีใครปิด */
    open_errors: number
    /** ตัวตั้งเวลาเงียบไปนานผิดปกติ = งานอัตโนมัติทั้งหมดหยุด */
    scheduler_down: boolean
}

export interface Option {
    value: string
    label: string
}

/* ---------- Catalog ---------- */

export interface Category {
    id: number
    name: string
    color: string
    is_active?: boolean
    sort_order?: number
    /** จำนวนเมนูในหมวดนี้ — ใช้กันลบหมวดที่ยังมีเมนูอยู่ */
    products_count?: number
}

export interface Modifier {
    id: number
    name: string
    price_delta: number
    /**
     * ตัวคูณขนาดจาน — 1 = ปกติ, 1.5 = พิเศษ, 2 = จัมโบ้
     * หน้าขายใช้โชว์ป้าย ×1.5 บนปุ่ม / ไม่บังคับเพราะหน้าสั่งของลูกค้าไม่ได้ส่งมา
     */
    portion_multiplier?: number
    /** ติ๊กไว้ให้ตั้งแต่เปิดหน้าต่างสั่ง */
    is_default?: boolean
}

export interface ModifierGroup {
    id: number
    name: string
    min_select: number
    max_select: number
    is_required: boolean
    modifiers: Modifier[]
}

/** ป้ายข้อมูลอาหาร มาจาก App\Enums\DietTag — kind ใช้เลือกสีของป้าย */
export interface DietTag {
    value: string
    label: string
    kind: 'heat' | 'diet' | 'allergen'
}

export interface Product {
    id: number
    name: string
    description?: string | null
    category_id: number | null
    price: number
    staff_price?: number | null
    is_alcohol?: boolean
    image_path: string | null
    is_open_price?: boolean
    /** ป้ายโปรโมทบนการ์ด เช่น "ยอดสั่งเยอะที่สุด" */
    promo_label?: string | null
    diet_tags?: DietTag[]
    /** คิดจากยอดขายจริงของสาขา ร้านติดเองไม่ได้ */
    is_best_seller?: boolean
    modifier_groups: ModifierGroup[]
}

/* ---------- นำส่งเงินสด ---------- */

/** ใบนำส่งของวันเดียว ที่หน้าขายใช้ */
export interface CashSettlement {
    id: number
    business_date: string
    expected_amount: number | string
    /** เงินสดที่รับตอนเน็ตหลุดแต่ยังไม่มีบิลรองรับ */
    held_cash_amount: number | string
    /** expected_amount + held_cash_amount — ยอดที่ต้องนำส่งจริง */
    due_amount: number
    counted_amount: number | string
    transferred_amount: number | string
    diff_amount: number | string
    status: 'pending' | 'submitted' | 'verified' | 'disputed'
    status_label?: string
    reference: string | null
    slip_path: string | null
    note: string | null
}

/** แถวในหน้าตรวจของผู้จัดการ — แปลงค่าให้พร้อมแสดงมาจากฝั่งเซิร์ฟเวอร์แล้ว */
export interface CashSettlementRow {
    id: number
    business_date: string
    expected_amount: number
    held_cash_amount: number
    due_amount: number
    counted_amount: number
    transferred_amount: number
    diff_amount: number
    status: string
    status_label: string
    settled_by: string | null
    transferred_at: string | null
    reference: string | null
    slip_path: string | null
    verified_by: string | null
    verified_at: string | null
    note: string | null
    days_overdue: number
}

/* ---------- Orders ---------- */

export interface OrderItemModifier {
    id: number
    name: string
    group_name: string | null
    price: string
}

export interface OrderItem {
    id: number
    product_id: number | null
    product_name: string
    source?: string
    approval_status?: 'pending' | 'approved' | 'rejected' | null
    category_name: string | null
    unit_price: string
    qty: string
    modifier_total: string
    discount: string
    line_total: string
    status: 'pending' | 'sent' | 'served' | 'void'
    note: string | null
    /** คอร์ส (1 เรียกน้ำย่อย, 2 จานหลัก, 3 ของหวาน) — null = ไม่จัดคอร์ส */
    course?: number | null
    modifiers: OrderItemModifier[]
}

export interface Order {
    id: number
    order_no: string
    receipt_no: string | null
    business_date: string
    type: string
    status: string
    guest_count: number
    subtotal: string
    item_discount: string
    bill_discount: string
    promotion_discount: string
    voucher_discount: string
    service_charge: string
    delivery_fee: string
    tax_amount: string
    rounding: string
    grand_total: string
    paid_amount: string
    change_amount: string
    opened_at: string | null
    closed_at: string | null
    source?: string
    payment_intent?: string | null
    payment_intent_label?: string | null
    is_government_scheme?: boolean
    contact_name?: string | null
    contact_phone?: string | null
    fulfilment_status?: string | null
    items?: OrderItem[]
    dining_table?: { id: number; name: string } | null
    customer?: { id: number; name: string; phone: string | null } | null
}

/**
 * QR รับเงินหนึ่งใบ — รูปที่เซิร์ฟเวอร์ส่งมาให้หน้า POS
 *
 * ประกอบที่ PaymentChargeController::chargePayload() ที่เดียว ทั้งตอนโหลดหน้า
 * และตอนถามซ้ำ จึงได้ฟิลด์ชุดเดียวกันเสมอ
 */
export interface PaymentCharge {
    id: number
    uuid: string
    order_id: number
    status: 'pending' | 'paid' | 'expired' | 'failed' | 'cancelled' | 'mismatch' | 'unmatched'
    status_label: string
    provider: string
    provider_label: string
    /** false = QR ของร้านเอง ระบบตรวจยอดให้ไม่ได้ พนักงานต้องดูสลิป */
    verifies_automatically: boolean
    amount: number
    /** ยอดที่เข้ามาจริง — null เมื่อยังไม่มีเงินเข้า */
    paid_amount: number | null
    qr_payload: string | null
    expires_at: string | null
    /**
     * ข้อความที่แสดงได้ — **ของเรา ไม่ใช่ของเกตเวย์**
     *
     * คอลัมน์ failure_message ในฐานข้อมูลเก็บข้อความดิบของเกตเวย์ไว้ด้วย
     * ซึ่งอาจมีรหัสภายในติดมา จึงไม่ถูกส่งออกมาที่เบราว์เซอร์เลย
     * (ดู PaymentChargeController::noteFor())
     */
    note: string | null
    /** ผูกเข้ากับแถว payments แล้วหรือยัง */
    settled: boolean
}

/** บิลของโต๊ะ ในมุมลูกค้าที่นั่งอยู่ — มาจาก TableBillService */
export interface TableBillItem {
    id: number
    name: string
    qty: number
    modifiers_text: string | null
    note: string | null
    /** ชื่อเล่นคนสั่ง — null เมื่อพนักงานคีย์ให้ หรือลูกค้าไม่ได้ตั้งชื่อ */
    guest_name?: string | null
    line_total: number
    status: 'waiting_approval' | 'waiting_kitchen' | 'in_kitchen' | 'preparing' | 'ready' | 'served'
    status_label: string
}

export interface TableBillRound {
    round: number
    placed_at: string | null
    items: TableBillItem[]
}

export interface TableBill {
    order_id: number
    order_no: string
    table: string | null
    /** จำนวนจาน ไม่ใช่จำนวนบรรทัด */
    item_count: number
    rounds: TableBillRound[]
    totals: {
        subtotal: number
        discount: number
        service_charge: number
        tax_amount: number
        grand_total: number
    }
    waiting_approval: number
    bill_called: boolean
}

/**
 * หนึ่งบรรทัดในตะกร้า ในรูปแบบที่แผ่นตะกร้า (CartSheet) ต้องใช้
 *
 * ตั้งใจให้แคบกว่าทั้ง CartLine (ตะกร้าส่วนตัวใน localStorage)
 * และ SharedCartLine (ตะกร้าร่วมของโต๊ะที่อยู่ฝั่งเซิร์ฟเวอร์)
 * เพื่อให้ทั้งสองแบบส่งเข้าแผ่นเดียวกันได้ โดยแผ่นนั้นไม่ต้องรู้ว่าของมาจากไหน
 *
 * key เป็น string ทั้งคู่ — ตะกร้าส่วนตัวประกอบเองจาก product+ตัวเลือก+หมายเหตุ
 * ส่วนตะกร้าร่วมใช้ id ของแถวในตาราง หน้าแม่เป็นคนแปลงให้
 */
export interface SheetLine {
    key: string
    name: string
    qty: number
    unit_price: number
    modifier_names: string[]
    note: string | null
    /** ชื่อคนที่หยิบใส่ — มีเฉพาะตะกร้าร่วมของโต๊ะ */
    guest_name?: string | null
    /** ของเครื่องนี้เองหรือเปล่า — ใช้ตกแต่งอย่างเดียว ไม่ใช่สิทธิ์ในการแก้ */
    mine?: boolean
}

export interface KitchenTicketItem {
    id: number
    name: string
    qty: number
    modifiers_text: string | null
    note: string | null
}

export interface KitchenTicket {
    id: number
    ticket_no: string
    table: string | null
    print_group: number
    print_group_label: string
    round: number
    course?: number | null
    course_label?: string | null
    status: 'queued' | 'preparing' | 'ready' | 'served' | 'cancelled'
    status_label: string
    source: string
    order_type: string
    queued_at: string | null
    waiting_minutes: number
    items: KitchenTicketItem[]
}

export interface ServiceCallItem {
    id: number
    type: string
    /** โต๊ะที่กดเรียก — ใช้จับคู่คำขอกับโต๊ะบนผัง */
    dining_table_id?: number | null
    label: string
    status: string
    table: string | null
    order_id: number | null
    note?: string | null
    waiting_minutes: number
}

export interface DiningTable {
    id: number
    name: string
    seats: number
    status: string
    pos_x: number
    pos_y: number
    width: number
    height: number
    shape: string
    is_active?: boolean
    zone_id?: number | null
    zone?: { id: number; name: string } | null
    open_order?: {
        id: number
        order_no: string
        grand_total: string
        guest_count: number
        opened_at: string
        pending_approval_items?: Array<{ id: number }>
    } | null
    active_session?: { id: number; started_at: string; order_count: number } | null
}

export type FloorPlanObjectType = 'cashier' | 'bar' | 'kitchen' | 'entrance' | 'restroom' | 'pillar' | 'wall' | 'custom'

export interface FloorPlanObject {
    id: number
    type: FloorPlanObjectType
    name: string
    pos_x: number
    pos_y: number
    width: number
    height: number
    color?: string | null
    icon?: string | null
    zone_id?: number | null
    zone?: { id: number; name: string } | null
    is_active?: boolean
}

/* ---------- Report ---------- */

export interface SalesSummary {
    bill_count: number
    gross_sales: number
    item_discount: number
    bill_discount: number
    service_charge: number
    delivery_fee: number
    tax_amount: number
    rounding: number
    net_sales: number
    cost_total: number
    gross_profit: number
    guest_count: number
    avg_per_bill: number
    avg_per_guest: number
}

export interface SummaryReport {
    sales: SalesSummary
    payments: Array<{ method: string; label: string; amount: number; count: number; percent: number }>
    bills_by_type: Array<{ type: string; label: string; bill_count: number; amount: number }>
    cancelled: {
        refund: { count: number; amount: number }
        void: { count: number; amount: number }
    }
    daily_sales: Array<{ date: string; bill_count: number; amount: number }>
    sales_by_hour: Array<{ hour: number; label: string; bill_count: number; amount: number }>
    sales_by_weekday: Array<{ weekday: number; label: string; amount: number; bill_count: number }>
    top_products: Array<{ product_id: number | null; name: string; category: string | null; qty: number; amount: number }>
    top_categories: Array<{ name: string; qty: number; amount: number; percent: number }>
    product_coverage: { sold: number; total: number; percent: number }
    inventory: {
        purchase: { count: number; amount: number }
        waste: { count: number; amount: number }
        low_stock_count: number
    }
    promotions: { bill_count: number; promo_bill_count: number; discount_amount: number; percent: number }
    customers: { total: number; avg_per_day: number; avg_spend: number }
    tables: {
        turnover_per_table_per_day: number
        avg_minutes: number
        max_minutes: number
        avg_guests: number
        guest_count: number
        avg_items_per_bill: number
    }
    staff: { items: Array<{ action: string; label: string; count: number }>; total: number }
}

export interface Paginated<T> {
    data: T[]
    current_page: number
    last_page: number
    per_page: number
    total: number
    links: Array<{ url: string | null; label: string; active: boolean }>
}
