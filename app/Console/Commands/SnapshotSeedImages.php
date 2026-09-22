<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * เก็บรูปที่อัปโหลดไว้ตอนนี้ ให้กลายเป็น "รูปตั้งต้น" ของ seeder
 *
 * ปัญหาที่แก้: migrate:fresh ล้างตาราง รูปที่อัปโหลดผ่านหน้าเว็บจึงหลุดจากเมนูทุกครั้ง
 * (ตัวไฟล์ไม่ได้หาย หายแค่ path ในฐานข้อมูล) ต้องมานั่งอัปใหม่ซ้ำ ๆ
 *
 * วิธีใช้: อัปโหลดรูปผ่านหน้าเว็บตามปกติจนพอใจ แล้วรัน
 *
 *     php artisan images:snapshot
 *
 * จากนั้นรีเซ็ตฐานข้อมูลกี่รอบ รูปก็กลับมาเองทั้งหมด
 *
 * ── ทำไมไม่ให้อัปโหลดลง seed/ ตรง ๆ ไปเลย ────────────────────────────
 * เพราะ seed/ คือ "ข้อมูลตัวอย่างของโปรเจกต์" ส่วนรูปที่อัปโหลดคือ "ข้อมูลของร้าน"
 * ถ้าปนกัน วันขึ้นใช้งานจริงจะแยกไม่ออกว่าไฟล์ไหนลบได้ และชื่อไฟล์แบบสุ่ม
 * ก็มีไว้กันชื่อชน กันเดาที่อยู่ไฟล์ของร้านอื่น และกันอัปทับของเก่า
 * คำสั่งนี้จึงเป็นการ "คัดลอกไปเก็บ" ไม่ใช่การเปลี่ยนที่เก็บของจริง
 */
class SnapshotSeedImages extends Command
{
    protected $signature = 'images:snapshot {--dry-run : ดูว่าจะคัดลอกอะไรบ้างโดยยังไม่เขียนไฟล์}';

    protected $description = 'คัดลอกรูปสาขาและรูปเมนูที่ใช้อยู่ตอนนี้ ไปเก็บเป็นรูปตั้งต้นของ seeder';

    /** ไฟล์จับคู่ ชื่อเมนู -> ชื่อไฟล์รูป ให้ MenuSeeder อ่าน */
    public const MAP_PATH = 'database/seeders/data/product-images.json';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $branches = $this->snapshotBranches($dry);
        $products = $this->snapshotProducts($dry);

        if ($dry) {
            $this->newLine();
            $this->comment('โหมดทดลอง — ยังไม่ได้เขียนไฟล์จริง');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("เก็บรูปสาขา {$branches} รูป และรูปเมนู {$products} รูป เรียบร้อย");
        $this->line('รีเซ็ตฐานข้อมูลได้เลย รูปจะกลับมาเองหลัง migrate:fresh --seed');

        return self::SUCCESS;
    }

    /* ---------- สาขา ---------- */

    /**
     * รูปสาขาใช้ชื่อตามรหัสสาขา ไม่ต้องมีไฟล์จับคู่
     * เพราะรหัสสาขาเป็นตัวเดียวกับที่ BranchSeeder ใช้สร้างอยู่แล้ว
     */
    protected function snapshotBranches(bool $dry): int
    {
        $copied = 0;

        foreach (Branch::all() as $branch) {
            foreach (['cover' => $branch->cover_path, 'logo' => $branch->logo_path] as $kind => $path) {
                $source = $this->diskPath($path);

                if ($source === null) {
                    continue;
                }

                $target = "branches/seed/{$kind}-{$branch->code}.jpg";

                if ($this->copy($source, $target, $dry)) {
                    $this->line("  สาขา {$branch->code}  {$kind}  ->  {$target}");
                    $copied++;
                }
            }
        }

        if ($copied === 0) {
            $this->line('  (ยังไม่มีสาขาไหนตั้งรูปไว้)');
        }

        return $copied;
    }

    /* ---------- เมนู ---------- */

    /**
     * รูปเมนูจับคู่ด้วย "ชื่อเมนู" เพราะเป็นสิ่งเดียวที่คงที่ข้ามการ seed
     *
     * id กับ sku เปลี่ยนทุกครั้งที่ migrate:fresh (id ของหมวดขยับ)
     * จึงใช้เป็นกุญแจไม่ได้ ส่วนชื่อไฟล์คงชื่อเดิมไว้ ไม่ต้องแปลงชื่อไทยเป็นชื่อไฟล์
     */
    protected function snapshotProducts(bool $dry): int
    {
        $map = [];
        $copied = 0;

        $products = Product::whereNotNull('image_path')
            ->orderBy('name')
            ->get(['id', 'name', 'image_path']);

        foreach ($products as $product) {
            $source = $this->diskPath($product->image_path);

            if ($source === null) {
                $this->warn("  ข้าม {$product->name} — ไม่พบไฟล์ {$product->image_path}");

                continue;
            }

            $file = basename($source);
            $target = 'products/seed/'.$file;

            // เมนูชื่อเดียวกันในหลายสาขาใช้รูปเดียวกันอยู่แล้ว คัดลอกรอบเดียวพอ
            if (! isset($map[$product->name]) && $this->copy($source, $target, $dry)) {
                $copied++;
            }

            $map[$product->name] = $file;
        }

        if ($map === []) {
            $this->line('  (ยังไม่มีเมนูไหนตั้งรูปไว้)');

            return 0;
        }

        ksort($map);

        $this->line('  เมนูที่มีรูป '.count($map).' รายการ -> '.self::MAP_PATH);

        if (! $dry) {
            $path = base_path(self::MAP_PATH);

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents(
                $path,
                json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
        }

        return $copied;
    }

    /* ---------- ภายใน ---------- */

    /**
     * แปลง path ที่เก็บในฐานข้อมูล (/storage/products/xxx.jpg)
     * ให้เป็น path บนดิสก์ public (products/xxx.jpg) — ไม่มีไฟล์จริงก็คืน null
     */
    protected function diskPath(?string $stored): ?string
    {
        if (blank($stored)) {
            return null;
        }

        $path = ltrim((string) parse_url($stored, PHP_URL_PATH), '/');
        $path = preg_replace('#^storage/#', '', $path);

        return Storage::disk('public')->exists($path) ? $path : null;
    }

    protected function copy(string $source, string $target, bool $dry): bool
    {
        if ($dry) {
            return true;
        }

        return Storage::disk('public')->put($target, Storage::disk('public')->get($source)) !== false;
    }
}
