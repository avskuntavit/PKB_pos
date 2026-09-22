<?php

namespace App\Enums;

/**
 * สถานะการส่งข้อมูลการขายออกไปให้ระบบบัญชี
 *
 * ── ทำไมไม่มีสถานะ "ข้อมูลเปลี่ยนหลังส่ง" ──────────────────
 * เพราะมันไม่ใช่สิ่งที่เกิดขึ้นกับ "การส่ง" แต่เกิดกับ "ข้อมูลต้นทาง"
 * ถ้าเก็บเป็นสถานะจะต้องมีอะไรสักอย่างคอยไล่อัปเดตทุกแถวตลอดเวลา
 * จึงคำนวณตอนอ่านแทน โดยเทียบลายนิ้วมือของข้อมูลวันนั้นกับที่บันทึกไว้ตอนส่ง
 */
enum ExportStatus: string
{
    case Pending = 'pending';

    case Sent = 'sent';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'ยังไม่ได้ส่ง',
            self::Sent => 'ส่งแล้ว',
            self::Failed => 'ส่งไม่สำเร็จ',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label()],
            self::cases(),
        );
    }
}
