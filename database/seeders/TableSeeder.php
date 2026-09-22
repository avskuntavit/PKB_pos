<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\FloorPlanObject;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * ผังร้านเริ่มต้น — 12 โต๊ะ จัดเป็น 3 แถว แถวละ 4 โต๊ะ
 *
 * ── ผังที่ได้ (มองจากด้านบน) ──────────────────────────────
 *
 *   A1   A2   A3   A4      แถว 1 — โต๊ะแนวตั้ง (กว้างน้อยกว่าสูง) นั่ง 4
 *   B1   B2   B3   B4      แถว 2 — โต๊ะเกือบจัตุรัส นั่ง 4
 *   C1   C2   C3   C4      แถว 3 — เหมือนแถว 2
 *  [    ครัว    ][คิดเงิน][  บาร์  ]
 *
 * ── เรื่องพิกัด ─────────────────────────────────────────
 * pos_x / pos_y / width / height เป็น "พิกเซล" บน canvas ของหน้าออกแบบผัง
 * ไม่ใช่ช่องตาราง หน้าออกแบบ snap ทุกอย่างเป็นช่องละ 20 px
 * ตัวเลขทุกตัวในไฟล์นี้จึงหารด้วย 20 ลงตัว ลากแล้วจะไม่เบี้ยวจากเส้นกริด
 *
 * ── เรื่อง shape ────────────────────────────────────────
 * ในหน้าออกแบบ shape = 'square' หมายถึง "บังคับให้กว้าง = สูง"
 * โต๊ะแถว 2-3 จึงเป็น 'rectangle' ทั้งที่รูปทรงเกือบจัตุรัส
 * ถ้าใส่ 'square' ไว้ พอไปกดปุ่มรูปทรงในหน้าออกแบบมันจะดึงให้เป็นจัตุรัสเป๊ะทันที
 */
class TableSeeder extends Seeder
{
    /** ระยะขอบซ้าย/บนของผัง */
    private const MARGIN_X = 60;

    /**
     * ความกว้างของหนึ่งคอลัมน์ — โต๊ะถูกจัดให้อยู่กึ่งกลางคอลัมน์
     *
     * เวลาเปลี่ยนความกว้างโต๊ะ ให้เลือกค่าที่ทำให้ (COL_PITCH - width) หารด้วย 40 ลงตัว
     * ไม่งั้นพิกัดกึ่งกลางจะตกร่องกริด 20 px แล้วผังจะเบี้ยวตอนลากครั้งแรก
     */
    private const COL_PITCH = 200;

    private const COLS = 4;

    /**
     * แถวโต๊ะ: ตำแหน่งแกน Y, ขนาดโต๊ะ, และตัวอักษรนำหน้าชื่อ
     *
     * ตั้งชื่อด้วยตัวอักษรตามแถว (A = แถวหน้า) เพื่อให้พนักงานฟังปุ๊บรู้ว่าโต๊ะอยู่แถวไหน
     * ถ้าอยากได้เลข 1-12 เรียงยาว เปลี่ยน prefix เป็น '' แล้วนับต่อเนื่องได้
     */
    private const ROWS = [
        ['prefix' => 'A', 'y' => 60,  'width' => 80,  'height' => 160],
        ['prefix' => 'B', 'y' => 260, 'width' => 120, 'height' => 100],
        ['prefix' => 'C', 'y' => 420, 'width' => 120, 'height' => 100],
    ];

    /** แถบใต้แถว 3 — เรียงจากมุมซ้ายสุดไปขวา หน่วยเป็น "กี่คอลัมน์" */
    private const FACILITIES = [
        ['type' => 'kitchen', 'name' => 'หน้าครัว',      'cols' => 2],
        ['type' => 'cashier', 'name' => 'โต๊ะคิดเงิน',    'cols' => 1],
        ['type' => 'bar',     'name' => 'เคาน์เตอร์บาร์', 'cols' => 1],
    ];

    private const FACILITY_Y = 600;

    private const FACILITY_HEIGHT = 80;

    public function run(): void
    {
        foreach (Branch::all() as $branch) {
            $zone = Zone::create([
                'branch_id' => $branch->id,
                'name' => 'ในร้าน',
                'sort_order' => 0,
            ]);

            $this->tables($branch->id, $zone->id);
            $this->facilities($branch->id, $zone->id);
        }
    }

    private function tables(int $branchId, int $zoneId): void
    {
        foreach (self::ROWS as $row) {
            for ($col = 0; $col < self::COLS; $col++) {
                DiningTable::create([
                    'branch_id' => $branchId,
                    'zone_id' => $zoneId,
                    'name' => $row['prefix'].($col + 1),
                    'seats' => 4,
                    'pos_x' => $this->centeredX($col, $row['width']),
                    'pos_y' => $row['y'],
                    'width' => $row['width'],
                    'height' => $row['height'],
                    'shape' => 'rectangle',
                ]);
            }
        }
    }

    private function facilities(int $branchId, int $zoneId): void
    {
        $col = 0;

        foreach (self::FACILITIES as $facility) {
            FloorPlanObject::create([
                'branch_id' => $branchId,
                'zone_id' => $zoneId,
                'type' => $facility['type'],
                'name' => $facility['name'],
                // ชิดขอบซ้ายของคอลัมน์ ไม่ต้องจัดกึ่งกลาง เพราะแถบนี้ต่อกันเป็นพืด
                'pos_x' => self::MARGIN_X + ($col * self::COL_PITCH),
                'pos_y' => self::FACILITY_Y,
                'width' => $facility['cols'] * self::COL_PITCH,
                'height' => self::FACILITY_HEIGHT,
                // ไม่กำหนดสี ปล่อยให้หน้าออกแบบใช้สีประจำประเภทของมันเอง
                'color' => null,
            ]);

            $col += $facility['cols'];
        }
    }

    /** จัดโต๊ะให้อยู่กึ่งกลางคอลัมน์ของมัน */
    private function centeredX(int $col, int $width): int
    {
        return self::MARGIN_X
            + ($col * self::COL_PITCH)
            + intdiv(self::COL_PITCH - $width, 2);
    }
}
