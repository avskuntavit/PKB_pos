<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';       // เจ้าของ — เห็นทุกสาขา
    case Manager = 'manager';   // ผู้จัดการสาขา
    case Cashier = 'cashier';   // แคชเชียร์
    case Staff = 'staff';       // พนักงานเสิร์ฟ

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'เจ้าของร้าน',
            self::Manager => 'ผู้จัดการ',
            self::Cashier => 'แคชเชียร์',
            self::Staff => 'พนักงาน',
        };
    }

    /** เข้าหลังบ้าน (Back Office) ได้หรือไม่ */
    public function canAccessBackOffice(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }

    public function canVoidBill(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }
}
