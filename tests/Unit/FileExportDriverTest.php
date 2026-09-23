<?php

namespace Tests\Unit;

use App\Exports\FileExportDriver;
use PHPUnit\Framework\TestCase;

/**
 * เส้นทางไฟล์ที่ส่งข้อมูลการขายออกไป
 *
 * ชื่อไฟล์ประกอบจากรหัสสาขาและวันขายซึ่งมาจากฐานข้อมูล ไม่ใช่ค่าคงที่ในโค้ด
 * ถ้าไม่กรอง ชื่อไฟล์จะพาไฟล์ไปโผล่นอกโฟลเดอร์ที่ตั้งไว้ได้
 */
class FileExportDriverTest extends TestCase
{
    protected FileExportDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FileExportDriver;
    }

    public function test_builds_the_normal_path(): void
    {
        $this->assertSame(
            'exports/sam/sales-HQ-2026-09-21',
            $this->driver->basePath('exports/sam', 'sales-{branch}-{date}', 'HQ', '2026-09-21'),
        );
    }

    public function test_trims_slashes_and_normalises_backslashes(): void
    {
        $this->assertSame(
            'exports/sam/x-HQ',
            $this->driver->basePath('/exports/sam/', 'x-{branch}', 'HQ', '2026-09-21'),
        );

        $this->assertSame(
            'exports/sam/HQ',
            $this->driver->basePath('exports\\sam', '{branch}', 'HQ', '2026-09-21'),
        );
    }

    public function test_a_branch_code_cannot_escape_the_folder(): void
    {
        $this->assertSame(
            'exports/sam/etc-2026-09-21',
            $this->driver->basePath('exports/sam', '{branch}-{date}', '../../etc', '2026-09-21'),
        );
    }

    public function test_two_thai_branch_codes_do_not_overwrite_each_other(): void
    {
        /*
        | รหัสสาขาที่เป็นภาษาไทยล้วนจะเหลือค่าว่างหลังกรองอักขระ
        | ถ้าปล่อยให้ว่าง ทุกสาขาจะเขียนทับไฟล์ชื่อเดียวกันโดยไม่มีใครรู้
        | ซึ่งแปลว่าข้อมูลของสาขาหนึ่งหายไปเงียบ ๆ ทุกวัน
        */
        $a = $this->driver->basePath('exports/sam', '{branch}', 'สาขาหลัก', '2026-09-21');
        $b = $this->driver->basePath('exports/sam', '{branch}', 'สาขาสอง', '2026-09-21');

        $this->assertNotSame($a, $b);
        $this->assertStringStartsWith('exports/sam/b', $a);
    }

    public function test_falls_back_when_everything_is_stripped(): void
    {
        // รหัสสาขาที่กรองแล้วเหลือค่าว่าง ยังต้องได้ชื่อเฉพาะตัวจากแฮช
        // ไม่ใช่ตกไปใช้ชื่อกลางที่ทุกสาขาใช้ร่วมกันแล้วเขียนทับกันเอง
        $this->assertSame(
            'exports/sam/b'.substr(md5('...'), 0, 8),
            $this->driver->basePath('exports/sam', '{branch}', '...', ''),
        );

        $this->assertSame(
            'exports/sam/nodate',
            $this->driver->basePath('exports/sam', '{date}', 'HQ', ''),
        );

        // ชื่อกลางใช้เฉพาะตอนที่รูปแบบชื่อไฟล์ทั้งอันไม่เหลืออะไรเลย
        $this->assertSame(
            'exports/sam/sales',
            $this->driver->basePath('exports/sam', '...', 'HQ', '2026-09-21'),
        );
    }
}
