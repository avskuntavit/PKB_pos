<?php

namespace App\Enums;

enum OrderSource: string
{
    case Pos = 'pos';               // พนักงานสั่งให้ที่เครื่อง POS
    case SelfOrder = 'self_order';  // ลูกค้าสแกน QR ที่โต๊ะสั่งเอง
    case Online = 'online';         // ลูกค้าสั่งล่วงหน้าจากหน้าร้านออนไลน์

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'พนักงานสั่ง',
            self::SelfOrder => 'ลูกค้าสั่งเอง',
            self::Online => 'สั่งล่วงหน้าออนไลน์',
        };
    }

    /**
     * ต้องให้พนักงานกดยืนยันก่อนเข้าครัวไหม
     *
     * ทั้งสั่งที่โต๊ะและสั่งออนไลน์ต้องผ่านสายตาพนักงานก่อน
     * เพราะร้านเป็นคนรู้ว่าของหมดหรือคิวยาวเกินรับไหว ไม่ใช่ระบบ
     */
    public function needsApproval(): bool
    {
        return in_array($this, [self::SelfOrder, self::Online], true);
    }
}
