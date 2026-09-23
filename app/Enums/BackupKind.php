<?php

namespace App\Enums;

/**
 * ของสามอย่างที่ต้องสำรอง — คนละวิธี คนละที่เก็บ
 *
 * Database  SQL Server เป็นคนเขียนไฟล์เอง ลงดิสก์ของตัวมันเอง แอปแค่สั่ง
 * Uploads   รูปเมนูและสลิปโอนเงิน ไม่ได้อยู่ในฐานข้อมูล กู้ .bak กลับมาแล้วรูปยังหาย
 * Exports   ไฟล์ภายในที่ไม่ได้เปิดให้เข้าถึงผ่านเว็บ — ที่สำคัญคือไฟล์ที่ส่งให้ระบบบัญชี
 *           ข้อมูลต้นทางอยู่ในฐานข้อมูลอยู่แล้ว สร้างใหม่ได้ แต่ไฟล์ที่ "ส่งไปแล้วจริง"
 *           คือหลักฐานว่าส่งอะไรออกไป จึงเก็บไว้ด้วย
 */
enum BackupKind: string
{
    case Database = 'database';
    case Uploads = 'uploads';
    case Exports = 'exports';

    public function label(): string
    {
        return match ($this) {
            self::Database => 'ฐานข้อมูล',
            self::Uploads => 'รูปเมนูและสลิปโอนเงิน',
            self::Exports => 'ไฟล์ส่งระบบบัญชี',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Database => 'บิล ยอดขาย สต๊อก ลูกค้า แต้ม — ทุกอย่างที่อยู่ในตาราง',
            self::Uploads => 'ไฟล์ใน storage/app/public ที่ไม่ได้อยู่ในฐานข้อมูล',
            self::Exports => 'สำเนาไฟล์ที่ส่งให้ระบบบัญชีไปแล้ว ใช้เป็นหลักฐานย้อนหลัง',
        };
    }

    /**
     * โฟลเดอร์ต้นทาง (เฉพาะชนิดที่แอปเป็นคน zip เอง)
     *
     * ── ระวังตรงนี้ ────────────────────────────────────────────────────
     * ไฟล์ส่งบัญชีเขียนผ่าน Storage::disk('local') ซึ่งใน Laravel 11 ขึ้นไป
     * ชี้ไป storage/app/**private** ไม่ใช่ storage/app เหมือนรุ่นก่อน
     * เดา path เอาเองจะได้โฟลเดอร์ว่างแล้วระบบรายงานว่า "ข้าม ไม่มีอะไรให้สำรอง"
     * ทุกคืน โดยไม่มีใครสงสัยอะไรเลย
     *
     * จึงสำรองทั้ง storage/app/private ไม่ใช่เฉพาะโฟลเดอร์ exports
     * เพราะอะไรที่เขียนลง disk 'local' ก็อยู่ใต้นั้นหมดและไม่ได้อยู่ในฐานข้อมูล
     */
    public function sourcePath(): ?string
    {
        $path = match ($this) {
            self::Database => null,
            self::Uploads => config('monitoring.backup.sources.uploads'),
            self::Exports => config('monitoring.backup.sources.exports'),
        };

        return $path ? (string) $path : null;
    }

    /** @return array<int, self> ชนิดที่แอป zip เอง */
    public static function fileKinds(): array
    {
        return [self::Uploads, self::Exports];
    }

    public static function options(): array
    {
        return array_map(fn (self $k) => [
            'value' => $k->value,
            'label' => $k->label(),
            'description' => $k->description(),
        ], self::cases());
    }
}
