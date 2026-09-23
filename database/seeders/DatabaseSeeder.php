<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * ข้อมูลตั้งต้นที่ระบบต้องมีถึงจะเปิดร้านได้ — สาขา เมนู โต๊ะ วัตถุดิบ
 *
 * ── ข้อมูลตัวอย่างไม่อยู่ที่นี่แล้ว ─────────────────────────
 * เดิม seeder ตัวนี้เรียก DemoSalesSeeder ซึ่งสร้างบิลปลอมย้อนหลัง 30 วันด้วย
 * พอ entrypoint ของ Docker รัน `migrate --seed` ทุกครั้งที่คอนเทนเนอร์เริ่ม
 * บิลปลอมก็จะไหลเข้ารายงานภาษี การกระทบยอด และข้อมูลที่ส่งให้ SAM ทุกครั้งที่ deploy
 * ตอนนี้ต้องสั่งเองเท่านั้น: php artisan db:seed --class=DemoSeeder
 *
 * ── ทำไมต้องกันการ seed ซ้ำ ───────────────────────────────
 * seeder ทุกตัวในโปรเจกต์นี้ใช้ create() ล้วน ไม่ใช่ updateOrCreate()
 * เรียกซ้ำจึงเป็นการ "สร้างใหม่" ไม่ใช่ "อัปเดตทับ" — ถ้าไม่ชนกับ unique index
 * ก็จะได้เมนูซ้ำโต๊ะซ้ำแบบเงียบ ๆ ถ้าชนก็ล้มกลางคัน เหลือข้อมูลครึ่ง ๆ กลาง ๆ
 * ทั้งสองทางแย่กว่าการไม่ทำอะไรเลย จึงเช็คก่อนว่ามีข้อมูลอยู่แล้วหรือยัง
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (Branch::query()->exists()) {
            $this->command?->warn(
                'มีข้อมูลสาขาอยู่แล้ว ข้ามการ seed เพื่อไม่ให้ข้อมูลซ้ำ'
                ."\n".'ถ้าต้องการล้างแล้วเริ่มใหม่จริง ๆ ให้ใช้: php artisan migrate:fresh --seed'
            );

            return;
        }

        $this->call([
            BranchSeeder::class,
            MenuSeeder::class,
            TableSeeder::class,
            InventorySeeder::class,
        ]);
    }
}
