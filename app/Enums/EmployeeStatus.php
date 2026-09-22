<?php

namespace App\Enums;

/** สถานะการขอสิทธิ์พนักงานองค์กรของลูกค้า */
enum EmployeeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'รอ HR อนุมัติ',
            self::Approved => 'ได้รับสิทธิ์พนักงานแล้ว',
            self::Rejected => 'ไม่ผ่านการอนุมัติ',
        };
    }
}
