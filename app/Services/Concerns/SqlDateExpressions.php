<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * ชิ้นส่วน SQL ที่แต่ละฐานข้อมูลเขียนไม่เหมือนกัน
 *
 * รวมไว้ที่เดียวเพราะระบบนี้รันได้ทั้ง SQLite (ตอนทดสอบ) และ SQL Server (ของจริง)
 * ถ้าปล่อยให้แต่ละ service คัดลอกไปเอง วันหนึ่งจะแก้ที่เดียวแล้วอีกที่เพี้ยนเงียบ ๆ
 *
 * ── เรื่อง alias กับ table prefix ──────────────────────────────────
 * Laravel เติม prefix ให้ alias ด้วย ไม่ใช่แค่ชื่อตาราง
 *
 *   DB::table('orders AS o')   ->   "POS2_orders" as "POS2_o"
 *
 * ตัว query builder รู้เรื่องนี้เอง where('o.branch_id') จึงถูกต้อง
 * แต่ SQL ดิบใน selectRaw ไม่มีใครเติมให้ ต้องอ้าง POS2_o เอง จึงใช้ a('o')
 */
trait SqlDateExpressions
{
    /**
     * alias ที่ผ่าน prefix แล้ว — ใช้เฉพาะใน SQL ดิบ
     *
     * ไม่มี prefix ก็คืน alias เดิม ใช้ได้ทั้งตอนตั้งและไม่ตั้ง DB_PREFIX
     */
    protected function a(string $alias): string
    {
        return DB::getTablePrefix().$alias;
    }

    protected function hourExpression(string $column): string
    {
        return match ($this->driver()) {
            'sqlite' => "CAST(STRFTIME('%H', {$column}) AS INTEGER)",
            'sqlsrv' => "DATEPART(HOUR, {$column})",
            default => "HOUR({$column})",
        };
    }

    /**
     * คืนเลข 0 = อาทิตย์ ถึง 6 = เสาร์ เหมือนกันทุกฐานข้อมูล
     *
     * SQL Server: DATEPART(WEEKDAY) นับจากวันที่ @@DATEFIRST กำหนดไว้
     * ซึ่งเปลี่ยนตาม language setting ของเซิร์ฟเวอร์ จึงต้องบวกกลับเอง
     * ไม่งั้นกราฟจะเลื่อนวันทั้งแถวบนเครื่องที่ตั้งค่าต่างกัน
     *
     * ตรงกับ Carbon::dayOfWeek พอดี จึงเทียบกันได้โดยไม่ต้องแปลง
     */
    protected function weekdayExpression(string $column): string
    {
        return match ($this->driver()) {
            'sqlite' => "CAST(STRFTIME('%w', {$column}) AS INTEGER)",
            'sqlsrv' => "((DATEPART(WEEKDAY, {$column}) + @@DATEFIRST - 1) % 7)",
            default => "(DAYOFWEEK({$column}) - 1)",
        };
    }

    /**
     * ต้องคืนค่าทศนิยม เพราะผลลัพธ์ถูกเอาไปหา AVG ต่อ
     * SQL Server: AVG ของ int คืน int ค่าเฉลี่ยจะถูกตัดเศษทิ้ง จึง CAST เป็น float ก่อน
     */
    protected function minutesDiffExpression(string $start, string $end): string
    {
        return match ($this->driver()) {
            'sqlite' => "((JULIANDAY({$end}) - JULIANDAY({$start})) * 1440)",
            'sqlsrv' => "CAST(DATEDIFF(MINUTE, {$start}, {$end}) AS float)",
            default => "TIMESTAMPDIFF(MINUTE, {$start}, {$end})",
        };
    }

    protected function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
