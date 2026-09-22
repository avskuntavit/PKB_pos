<?php

namespace Database\Seeders;

use App\Enums\DietTag;
use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use Illuminate\Database\Seeder;
use App\Console\Commands\SnapshotSeedImages;
use Illuminate\Support\Facades\Storage;

class MenuSeeder extends Seeder
{
    /** @var array<string, string>|null */
    protected ?array $imageMap = null;

    public function run(): void
    {
        /*
        | แต่ละแถว: [ชื่อ, ราคาขาย, ต้นทุน, ไฟล์รูปตั้งต้น (ไม่ใส่ก็ได้)]
        |
        | ไฟล์รูปวางไว้ที่ storage/app/public/products/seed/
        | ใส่ชื่อไฟล์ตรงนี้แล้วรูปจะติดมากับเมนูทุกครั้งที่ seed ใหม่
        */
        $menu = [
            'ก๋วยเตี๋ยว' => ['color' => '#ef4444', 'items' => [
                ['หมี่ขาว หมูหมัก', 50, 18],
                ['เส้นเล็ก หมูหมัก', 50, 18],
                ['บะหมี่ หมูหมัก', 55, 20],
                ['เส้นใหญ่ หมูหมัก', 50, 18],
                ['หมี่ขาว ลูกชิ้น เนื้อสด', 50, 20],
                ['เส้นเล็ก ลูกชิ้น เนื้อสด', 50, 20],
                ['เกาเหลาหมู', 65, 25],
                ['เกาเหลาเนื้อ', 75, 32],
            ]],
            'ข้าว / กับข้าว' => ['color' => '#f59e0b', 'items' => [
                ['ข้าวราดกะเพราเนื้อสับ', 80, 35],
                ['ข้าวราดกะเพราหมูสับ', 70, 28],
                ['กะเพราเนื้อชิ้น (กับข้าว)', 80, 36],
                ['ข้าวเปล่า', 15, 4],
                ['ไข่ดาว', 15, 7],
            ]],
            'ของทานเล่น' => ['color' => '#84cc16', 'items' => [
                ['กากเจียว', 20, 5, 'kak-jiao.jpg'],
                ['เกี๊ยวทอด', 40, 14],
                ['ลูกชิ้นทอด', 45, 18],
            ]],
            'เครื่องดื่ม' => ['color' => '#0ea5e9', 'items' => [
                ['เป๊ปซี่ 545 มล.', 20, 12],
                ['น้ำเปล่า', 10, 4],
                ['ชาเย็น', 35, 12],
                ['โอเลี้ยง', 30, 10],
            ]],
            'เครื่องดื่มแอลกอฮอล์' => ['color' => '#7c3aed', 'alcohol' => true, 'items' => [
                ['เบียร์ขวดใหญ่', 120, 78],
                ['เบียร์กระป๋อง', 70, 45],
                ['โซดา', 25, 12],
            ]],
        ];

        /*
        | แคตตาล็อกเป็นของกลาง — สร้างรอบเดียว ทุกสาขาใช้ร่วมกัน
        |
        | เดิมวนสร้างซ้ำทุกสาขา ผลคือแก้ราคาเมนูเดียวต้องไล่แก้ทุกสาขา
        | ตอนนี้ branch_id = null คือของกลาง ส่วนราคาหรือการเปิด-ปิดที่สาขาหนึ่ง
        | ต่างจากกลาง ไปอยู่ในตาราง branch_product ซึ่ง seeder ไม่ต้องสร้างล่วงหน้า
        | (ไม่มีแถว = สาขานั้นใช้ค่ากลางทั้งหมด ซึ่งเป็นสิ่งที่เราอยากได้ตอนเริ่ม)
        */
        foreach ([null] as $branchId) {
            /*
            | เซ็ตตัวเลือก — name คือชื่อภายในไว้หาในหลังบ้าน
            | display_name คือชื่อที่ลูกค้าเห็นตอนสั่ง
            | หลายเซ็ตใช้ชื่อหน้าบ้านซ้ำกันได้ ตัวเลือกข้างในคนละชุด
            */
            $spicy = ModifierGroup::create([
                'branch_id' => $branchId,
                'name' => 'ก๋วยเตี๋ยว - ความเผ็ด',
                'display_name' => 'ระดับความเผ็ด',
                'min_select' => 0,
                'max_select' => 1,
                'is_required' => false,
            ]);

            foreach (['ไม่เผ็ด' => 0, 'เผ็ดน้อย' => 0, 'เผ็ดปกติ' => 0, 'เผ็ดมาก' => 0] as $name => $price) {
                Modifier::create(['modifier_group_id' => $spicy->id, 'name' => $name, 'price_delta' => $price]);
            }

            // ของที่เพิ่มเข้าไปจริง ๆ — ผูกวัตถุดิบไว้ใน InventorySeeder
            $extra = ModifierGroup::create([
                'branch_id' => $branchId,
                'name' => 'ก๋วยเตี๋ยว - เพิ่มเติม',
                'display_name' => 'เพิ่มเติม',
                'min_select' => 0,
                'max_select' => 5,
                'is_required' => false,
            ]);

            foreach (['เพิ่มเส้น' => 10, 'เพิ่มเนื้อ' => 20, 'เพิ่มลูกชิ้น' => 15] as $name => $price) {
                Modifier::create(['modifier_group_id' => $extra->id, 'name' => $name, 'price_delta' => $price]);
            }

            /*
            | ขนาดจาน — ใช้ตัวคูณ ไม่ใช่การเพิ่มวัตถุดิบเป็นรายการ
            | จัมโบ้ = คูณสูตรฐานทั้งสูตร 2 เท่า (เส้น น้ำซุป ผัก เนื้อ ขึ้นตามกันหมด)
            | แต่ถุงหูหิ้วตั้ง scales_with_portion = false ไว้ จึงยังใช้ใบเดียว
            */
            $portion = ModifierGroup::create([
                'branch_id' => $branchId,
                'name' => 'ก๋วยเตี๋ยว - ปริมาณ',
                'display_name' => 'ปริมาณ',
                'min_select' => 1,
                'max_select' => 1,
                'is_required' => true,
                'sort_order' => 0,
            ]);

            foreach ([
                ['ธรรมดา', 0, 1.00, true],
                ['พิเศษ', 15, 1.50, false],
                ['จัมโบ้', 30, 2.00, false],
            ] as $i => [$name, $price, $multiplier, $default]) {
                Modifier::create([
                    'modifier_group_id' => $portion->id,
                    'name' => $name,
                    'price_delta' => $price,
                    'portion_multiplier' => $multiplier,
                    'is_default' => $default,
                    'sort_order' => $i,
                ]);
            }

            /*
            | รูปแบบการรับ — ย้ายจากระดับบิลมาเป็นตัวเลือกรายจาน
            | เพราะลูกค้าสั่งทีเดียวแล้วทานที่ร้านบางจาน ห่อกลับบางจานได้
            | ตัวที่ห่อกลับติดธง marks_takeaway ไว้ ระบบใช้เดาประเภทบิลตอนสรุปรายงาน
            */
            $packaging = ModifierGroup::create([
                'branch_id' => $branchId,
                'name' => 'รูปแบบการรับ',
                'display_name' => 'รับประทาน',
                'min_select' => 1,
                'max_select' => 1,
                'is_required' => true,
                'sort_order' => 1,
            ]);

            foreach ([
                ['ทานที่ร้าน', 0, true, false],
                ['ห่อกลับบ้าน', 5, false, true],
            ] as $i => [$label, $price, $default, $takeaway]) {
                Modifier::create([
                    'modifier_group_id' => $packaging->id,
                    'name' => $label,
                    'price_delta' => $price,
                    'is_default' => $default,
                    'marks_takeaway' => $takeaway,
                    'sort_order' => $i,
                ]);
            }

            $sort = 0;
            $promoFilled = 0;

            foreach ($menu as $categoryName => $config) {
                $category = Category::create([
                    'branch_id' => $branchId,
                    'name' => $categoryName,
                    'color' => $config['color'],
                    'sort_order' => $sort++,
                ]);

                $isAlcohol = $config['alcohol'] ?? false;

                foreach ($config['items'] as $i => $item) {
                    // แถวเก่ามี 3 ช่อง แถวที่มีรูปมี 4 — แยกอ่านช่องที่ 4 เพื่อไม่ให้แถวเก่าฟ้อง undefined key
                    [$name, $price, $cost] = $item;
                    $imageFile = $item[3] ?? null;

                    // ราคาพนักงาน: ลดจากราคาปกติราว 30% ปัดลงหลักสิบ
                    // แอลกอฮอล์ไม่ตั้งราคาพนักงาน = ไม่เข้าเงื่อนไขสวัสดิการ
                    $staffPrice = $isAlcohol ? null : max($cost, floor($price * 0.7 / 5) * 5);

                    $product = Product::create([
                        'branch_id' => $branchId,
                        'category_id' => $category->id,
                        'sku' => $category->id.'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                        'name' => $name,
                        'price' => $price,
                        'cost' => $cost,
                        'sort_order' => $i,
                        'staff_price' => $staffPrice,
                        'is_alcohol' => $isAlcohol,
                        'diet_tags' => $this->dietTagsFor($name, $categoryName),
                        'print_group' => str_contains($categoryName, 'เครื่องดื่ม') ? 2 : 1,
                        'image_path' => $this->seedImage($imageFile, $name),
                    ]);

                    /*
                    | ตัวอย่างกลุ่มโปรโมท — เมนูแรกของร้านเป็นรูปใหญ่
                    | อีก 8 เมนูถัดมาลงตะแกรง 2 คอลัมน์ 4 แถว
                    | ร้านจริงไปเปลี่ยนเองได้ที่ รายละเอียดสินค้า > กลุ่มโปรโมท
                    */
                    if ($promoFilled < 8) {
                        $product->update([
                            'is_featured' => $promoFilled === 0,
                            'is_promoted' => true,
                            'promo_sort' => $promoFilled,
                            'promo_label' => match ($promoFilled) {
                                0 => 'ยอดสั่งเยอะที่สุด',
                                1 => 'ยอดสั่งเยอะที่สุด',
                                2 => 'ยอดนิยม',
                                default => null,
                            },
                        ]);

                        $promoFilled++;
                    }

                    // ทุกเมนูต้องเลือกว่าทานที่ร้านหรือห่อกลับ ยกเว้นเครื่องดื่มบรรจุขวด
                    if (! str_contains($categoryName, 'เครื่องดื่ม')) {
                        $product->modifierGroups()->attach([
                            $packaging->id => ['sort_order' => 9, 'is_active' => true],
                        ]);
                    }

                    if ($categoryName === 'ก๋วยเตี๋ยว') {
                        $product->modifierGroups()->attach([
                            $portion->id => ['sort_order' => 0, 'is_active' => true],
                            $spicy->id => ['sort_order' => 1, 'is_active' => true],
                            $extra->id => ['sort_order' => 2, 'is_active' => true],
                        ]);
                    }
                }
            }
        }
    }

    /**
     * รูปตั้งต้นของเมนู — วางไฟล์ไว้ที่ storage/app/public/products/seed/
     *
     * เหตุผลเดียวกับรูปสาขา: migrate:fresh ล้างแค่ฐานข้อมูล ไม่ได้ลบไฟล์รูป
     * ที่หายทุกครั้งคือ path ในตาราง พอมีไฟล์ตั้งต้นวางไว้ รีเซ็ตกี่รอบรูปก็กลับมาเอง
     *
     * หาชื่อไฟล์จาก 2 ที่ ตามลำดับ:
     *   1. ช่องที่ 4 ของแถวเมนู — ใส่มือไว้ในโค้ด อ่านง่าย ติดไปกับ repo
     *   2. ไฟล์จับคู่ที่ `php artisan images:snapshot` เขียนไว้ — มาจากรูปที่อัปโหลดจริง
     *
     * ไม่เจอทั้งสองที่ ก็คืน null แล้วเมนูจะใช้กล่องสีอักษรแรกแทน
     */
    protected function seedImage(?string $file, string $productName): ?string
    {
        $file = $file ?: ($this->imageMap()[$productName] ?? null);

        if (blank($file)) {
            return null;
        }

        $path = 'products/seed/'.$file;

        return Storage::disk('public')->exists($path) ? '/storage/'.$path : null;
    }

    /**
     * ตารางจับคู่ ชื่อเมนู -> ชื่อไฟล์รูป ที่ images:snapshot สร้างไว้
     *
     * อ่านครั้งเดียวแล้วจำไว้ เพราะ seeder วนทุกเมนูของทุกสาขา
     * ถ้าอ่านไฟล์ใหม่ทุกรอบจะเปิดไฟล์เป็นร้อยครั้งโดยไม่ได้อะไรเพิ่ม
     *
     * @return array<string, string>
     */
    protected function imageMap(): array
    {
        if ($this->imageMap !== null) {
            return $this->imageMap;
        }

        $path = base_path(SnapshotSeedImages::MAP_PATH);

        if (! is_file($path)) {
            return $this->imageMap = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return $this->imageMap = is_array($decoded) ? $decoded : [];
    }

    /**
     * เดาป้ายข้อมูลอาหารจากชื่อเมนู — ใช้กับข้อมูลตัวอย่างเท่านั้น
     *
     * ร้านจริงต้องไปติ๊กเองในหลังบ้าน เพราะการเดาจากชื่อผิดได้ง่าย
     * และป้ายกลุ่ม "ต้องระวัง" ผิดแปลว่าคนแพ้อาหารกินของที่กินไม่ได้
     *
     * @return array<int, string>
     */
    private function dietTagsFor(string $name, string $categoryName): array
    {
        $tags = [];

        foreach ([
            'ผัดกะเพรา' => [DietTag::Spicy],
            'ต้มยำ' => [DietTag::Spicy, DietTag::ContainsSeafood],
            'ส้มตำ' => [DietTag::VerySpicy, DietTag::ContainsNut],
            'แกงเขียวหวาน' => [DietTag::Spicy],
            'ผัดไทย' => [DietTag::ContainsNut, DietTag::ContainsSeafood],
            'ลาบ' => [DietTag::VerySpicy],
            'ยำ' => [DietTag::Spicy],
            'ผัดซีอิ๊ว' => [DietTag::ContainsGluten],
            'ชาเย็น' => [DietTag::ContainsDairy],
            'นม' => [DietTag::ContainsDairy],
            'เจ' => [DietTag::Vegan],
            'มังสวิรัติ' => [DietTag::Vegetarian],
        ] as $keyword => $matched) {
            if (str_contains($name, $keyword)) {
                foreach ($matched as $tag) {
                    $tags[$tag->value] = true;
                }
            }
        }

        return array_keys($tags);
    }
}
