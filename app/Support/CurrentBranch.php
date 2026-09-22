<?php

namespace App\Support;

use App\Models\Branch;

/**
 * เก็บสาขาที่กำลังทำงานอยู่ของ request ปัจจุบัน
 * ตั้งค่าโดย middleware ResolveCurrentBranch
 */
class CurrentBranch
{
    protected static ?Branch $branch = null;

    public static function set(?Branch $branch): void
    {
        static::$branch = $branch;
    }

    public static function get(): ?Branch
    {
        return static::$branch;
    }

    public static function id(): ?int
    {
        return static::$branch?->id;
    }

    public static function getOrFail(): Branch
    {
        return static::$branch ?? throw new \RuntimeException('ยังไม่ได้เลือกสาขา');
    }
}
