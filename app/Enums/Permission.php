<?php

namespace App\Enums;

/**
 * สิทธิ์การใช้งานแบบละเอียด
 *
 * ตำแหน่ง (UserRole) เป็นแค่ "ชุดสิทธิ์ตั้งต้น" ที่ใช้บ่อย
 * ส่วนสิทธิ์จริงของแต่ละคนคำนวณจาก ค่าตั้งต้นของตำแหน่ง + grant/revoke รายคน
 * ทำแบบนี้เพราะร้านจริงมักมีข้อยกเว้น เช่น แคชเชียร์คนหนึ่งที่ให้ปิดรอบได้
 */
enum Permission: string
{
    // หน้าร้าน
    case PosUse = 'pos.use';
    case OrderVoid = 'order.void';
    case OrderDiscount = 'order.discount';
    case OrderApproveSelfOrder = 'order.approve_self_order';
    case PaymentTake = 'payment.take';
    case PaymentRefund = 'payment.refund';
    case ShiftOpen = 'shift.open';
    case ShiftClose = 'shift.close';
    case CashSettle = 'cash.settle';
    case KitchenUse = 'kitchen.use';

    // หลังบ้าน
    case BackOfficeAccess = 'backoffice.access';
    case ReportSales = 'report.sales';
    case ReportProfit = 'report.profit';
    case ReportStaffBenefit = 'report.staff_benefit';
    case ProductManage = 'product.manage';
    case RecipeManage = 'recipe.manage';
    case InventoryManage = 'inventory.manage';
    // โอนของข้ามสถานี — ผู้จัดการทำได้ แต่โอนออกได้เฉพาะสถานีที่ตัวเองดูแล
    // (ด่านนั้นอยู่ที่ accessibleBranchIds() ใน controller ไม่ใช่ที่สิทธิ์ตัวนี้)
    case StockTransfer = 'stock.transfer';
    case CustomerManage = 'customer.manage';
    case EmployeeVerify = 'customer.employee_verify';
    case PromotionManage = 'promotion.manage';
    case TableManage = 'table.manage';
    case StaffManage = 'staff.manage';
    case BranchSettings = 'branch.settings';
    // เปิดสถานีใหม่และแก้ข้อมูลแบรนด์ของทุกสถานี — ไม่อยู่ในชุดตั้งต้นของผู้จัดการ
    // เพราะผู้จัดการดูแลสถานีของตัวเอง ไม่ใช่โครงสร้างของทั้งกิจการ
    case StationManage = 'station.manage';
    // สุขภาพระบบ: การสำรองข้อมูล ข้อผิดพลาด และค่าตั้งค่าที่อันตราย
    // เป็นเรื่องของทั้งระบบ ไม่ใช่ของสาขา จึงไม่อยู่ในชุดตั้งต้นของผู้จัดการเช่นกัน
    case SystemHealth = 'system.health';
    case CashSettleVerify = 'cash.settle_verify';
    case BankReconcile = 'bank.reconcile';
    // ปิดงวดบัญชี — ผู้จัดการปิดได้ แต่ "เปิดงวดที่ปิดแล้วกลับมา" เจ้าของระบบเท่านั้น
    // (ด่านนั้นอยู่ใน PeriodLockService::reopen() ไม่ได้อยู่ที่สิทธิ์ตัวนี้)
    case PeriodClose = 'period.close';
    case SalesExport = 'sales.export';

    public function label(): string
    {
        return match ($this) {
            self::PosUse => 'ใช้หน้าจอขาย',
            self::OrderVoid => 'ทำลายบิล / ยกเลิกรายการ',
            self::OrderDiscount => 'ให้ส่วนลดท้ายบิล',
            self::OrderApproveSelfOrder => 'ยืนยันออเดอร์ที่ลูกค้าสั่งเอง',
            self::PaymentTake => 'รับชำระเงิน',
            self::PaymentRefund => 'คืนเงิน',
            self::ShiftOpen => 'เปิดรอบการขาย',
            self::ShiftClose => 'ปิดรอบการขาย',
            self::CashSettle => 'นำส่งเงินสดสิ้นวัน',
            self::KitchenUse => 'ใช้หน้าจอครัว',
            self::BackOfficeAccess => 'เข้าหลังบ้าน',
            self::ReportSales => 'ดูรายงานยอดขาย',
            self::ReportProfit => 'ดูต้นทุนและกำไร',
            self::ReportStaffBenefit => 'ดูรายงานสวัสดิการพนักงาน',
            self::ProductManage => 'จัดการเมนู',
            self::RecipeManage => 'จัดการสูตรและส่วนผสม',
            self::InventoryManage => 'จัดการคลัง',
            self::StockTransfer => 'โอนของข้ามสถานี',
            self::CustomerManage => 'จัดการลูกค้า',
            self::EmployeeVerify => 'อนุมัติสิทธิ์พนักงานองค์กร',
            self::PromotionManage => 'จัดการโปรโมชั่นและ Voucher',
            self::TableManage => 'จัดการโต๊ะ',
            self::StaffManage => 'จัดการพนักงานและสิทธิ์',
            self::BranchSettings => 'ตั้งค่าสาขา',
            self::StationManage => 'เปิดและจัดการสถานี',
            self::SystemHealth => 'ดูสุขภาพระบบและสำรองข้อมูล',
            self::CashSettleVerify => 'ตรวจการนำส่งเงินกับธนาคาร',
            self::BankReconcile => 'กระทบยอดเงินกับธนาคาร',
            self::PeriodClose => 'ปิดงวดบัญชี',
            self::SalesExport => 'ส่งข้อมูลการขายให้ระบบบัญชี',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::PosUse, self::OrderVoid, self::OrderDiscount, self::OrderApproveSelfOrder,
            self::PaymentTake, self::PaymentRefund, self::ShiftOpen, self::ShiftClose,
            self::CashSettle, self::KitchenUse => 'หน้าร้าน',
            self::ReportSales, self::ReportProfit, self::ReportStaffBenefit => 'รายงาน',
            default => 'หลังบ้าน',
        };
    }

    /** สิทธิ์ตั้งต้นของแต่ละตำแหน่ง */
    public static function defaultsFor(UserRole $role): array
    {
        $serving = [self::PosUse, self::KitchenUse, self::OrderApproveSelfOrder];

        $cashier = array_merge($serving, [
            self::PaymentTake,
            self::OrderDiscount,
            self::ShiftOpen,
        ]);

        $manager = array_merge($cashier, [
            self::OrderVoid,
            self::PaymentRefund,
            self::ShiftClose,
            // ผู้จัดการนำส่งเองได้ และเป็นคนตรวจว่าเงินเข้าบัญชีจริง
            self::CashSettle,
            self::CashSettleVerify,
            // ผู้จัดการเป็นคนกระทบยอดกับสเตทเมนต์ตอนปิดเดือน
            self::BankReconcile,
            self::PeriodClose,
            self::SalesExport,
            self::BackOfficeAccess,
            self::ReportSales,
            self::ReportProfit,
            self::ProductManage,
            self::RecipeManage,
            self::InventoryManage,
            self::StockTransfer,
            self::CustomerManage,
            self::EmployeeVerify,
            self::PromotionManage,
            self::TableManage,
        ]);

        return match ($role) {
            UserRole::Owner => self::cases(),
            UserRole::Manager => $manager,
            UserRole::Cashier => $cashier,
            UserRole::Staff => $serving,
        };
    }

    /** ใช้ส่งไปหน้าตั้งค่าสิทธิ์ */
    public static function grouped(): array
    {
        $out = [];

        foreach (self::cases() as $permission) {
            $out[$permission->group()][] = [
                'value' => $permission->value,
                'label' => $permission->label(),
            ];
        }

        return $out;
    }
}
