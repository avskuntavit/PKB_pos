<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * ข้อมูลตัวอย่างสำหรับลองเล่น — ยอดขายย้อนหลัง 30 วัน และสถานการณ์ "กำลังขายอยู่"
 *
 * ── ต้องสั่งเองเท่านั้น ────────────────────────────────────
 *     php artisan db:seed --class=DemoSeeder
 *
 * ไม่ถูกเรียกจาก DatabaseSeeder และไม่ถูกเรียกตอน deploy อีกแล้ว
 * เพราะบิลที่ seeder นี้สร้างคือ **บิลปลอม** ที่เข้าไปนั่งอยู่ในรายงานภาษีขาย
 * การกระทบยอดธนาคาร และข้อมูลที่ส่งให้ระบบบัญชี แยกไม่ออกจากบิลจริง
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'ห้าม seed ข้อมูลตัวอย่างบนเครื่อง production — บิลปลอมจะปนเข้ารายงานภาษีและงานบัญชี'
            );
        }

        $this->call([
            DemoSalesSeeder::class,
            LiveDemoSeeder::class,
        ]);
    }
}
