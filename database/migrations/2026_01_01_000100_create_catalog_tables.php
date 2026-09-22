<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | แคตตาล็อกกลาง + ค่าเฉพาะสาขา
        |--------------------------------------------------------------------------
        | branch_id = NULL  คือของกลาง ทุกสาขาใช้ร่วมกัน
        | branch_id = X     คือของเฉพาะสาขานั้น (เมนูพิเศษที่สาขาอื่นไม่ขาย)
        |
        | สิ่งที่แต่ละสาขาปรับเองได้ (ราคา เปิด-ปิดขาย ลำดับ จุดพิมพ์)
        | ไม่ได้เขียนทับของกลาง แต่ไปอยู่ในตาราง branch_product แยกต่างหาก
        | เพื่อให้ "ของกลาง" ยังเป็นความจริงชุดเดียว แก้ครั้งเดียวเห็นผลทุกสาขา
        |
        | branch_key เป็นคอลัมน์ช่วยทำ unique เท่านั้น — 0 แทน NULL
        | เพราะ MySQL/SQLite ปล่อยให้ NULL ซ้ำกันได้ในคีย์ unique
        | ถ้าไม่มีคอลัมน์นี้ เมนูกลางจะมี sku ซ้ำกันกี่ตัวก็ได้
        */

        // หมวดหมู่สินค้า เช่น ก๋วยเตี๋ยว / กับข้าว / เครื่องดื่ม
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 9)->default('#64748b');   // สีปุ่มบนหน้า POS
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'sort_order']);
        });

        // สินค้า / เมนู
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->unsignedBigInteger('branch_key')->default(0);       // 0 = เมนูกลาง — ใช้ทำ unique เท่านั้น
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 40)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);      // ราคาขายกลาง — สาขาทับได้ที่ branch_product
            $table->decimal('cost', 12, 2)->default(0);       // ต้นทุน (สำหรับรายงานกำไร)
            $table->string('unit', 20)->default('รายการ');
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('track_stock')->default(false);   // ตัดสต๊อกวัตถุดิบตามสูตรหรือไม่
            $table->boolean('is_open_price')->default(false); // ราคาเปิด (กรอกเอง)
            $table->decimal('staff_price', 12, 2)->nullable(); // ราคาสำหรับพนักงานองค์กร (null = ไม่มีสิทธิ์)
            $table->boolean('is_alcohol')->default(false);     // เครื่องดื่มแอลกอฮอล์ — ไม่เข้าเงื่อนไขสวัสดิการ
            // ป้ายบอกข้อมูลอาหาร เช่น เผ็ด เจ มีถั่ว — เก็บเป็นชุดค่าจาก App\Enums\DietTag
            // ใช้ json เพราะหนึ่งเมนูติดได้หลายป้าย และชุดป้ายจะเพิ่มทีหลังโดยไม่ต้องแก้ schema
            $table->json('diet_tags')->nullable();
            $table->timestamp('unavailable_until')->nullable(); // ปิดขายชั่วคราว (ของหมดวันนี้)
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('print_group')->default(1); // 1=ครัว 2=บาร์ — สาขาทับได้

            /*
            | โปรโมทบนหน้าสั่งอาหาร
            |
            | is_featured  รูปใหญ่ใบเดียวบนสุด — มีได้สาขาละ 1 เมนู
            | is_promoted  อยู่ในตะแกรงโปรโมท 2 คอลัมน์ x 4 แถว
            | promo_label  ป้ายบนการ์ด เช่น "ยอดสั่งเยอะที่สุด"
            | promo_sort   ลำดับในตะแกรง น้อยขึ้นก่อน
            */
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_promoted')->default(false);
            $table->string('promo_label', 20)->nullable();
            $table->unsignedInteger('promo_sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'category_id', 'is_active']);
            $table->index(['branch_id', 'is_promoted', 'promo_sort']);
            $table->unique(['branch_key', 'sku']);
        });

        /*
        | ค่าที่สาขาหนึ่งปรับต่างจากเมนูกลาง
        |
        | ไม่มีแถว = สาขานั้นใช้ค่ากลางทั้งหมด (กรณีปกติ)
        | คอลัมน์เป็น NULL = ข้อนั้นใช้ค่ากลาง ส่วนข้ออื่นในแถวเดียวกันทับได้
        | แยก NULL กับ 0 ให้ออก — ราคา 0 คือแจกฟรี ไม่ใช่ "ใช้ราคากลาง"
        */
        Schema::create('branch_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->decimal('price', 12, 2)->nullable();            // NULL = ใช้ราคากลาง
            $table->boolean('is_active')->nullable();               // NULL = ตามค่ากลาง
            $table->timestamp('unavailable_until')->nullable();     // ของหมดวันนี้ เฉพาะสาขานี้
            $table->unsignedInteger('sort_order')->nullable();      // NULL = ตามลำดับกลาง
            $table->unsignedTinyInteger('print_group')->nullable(); // NULL = ตามจุดพิมพ์กลาง
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->index(['branch_id', 'is_active']);
        });

        /*
        | เซ็ตตัวเลือก — สร้างครั้งเดียว เอาไปแปะเมนูไหนก็ได้
        |
        | name         ชื่อภายใน ใช้หาในหลังบ้าน  "ก๋วยเตี๋ยว - เพิ่มเติม"
        | display_name ชื่อที่ลูกค้าเห็นตอนสั่ง     "เพิ่มเติม"
        |
        | แยกสองชื่อเพราะหลายเซ็ตแสดงชื่อเดียวกันได้ — ก๋วยเตี๋ยวกับกระเพรา
        | ต่างมีเซ็ต "เพิ่มเติม" ของตัวเอง แต่ตัวเลือกข้างในคนละชุด
        */
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            // NULL = เซ็ตกลาง ใช้ได้ทุกสาขา (ต้องเป็นกลางตามเมนู ไม่งั้นเมนูกลางจะผูกเซ็ตของสาขาใดสาขาหนึ่ง)
            $table->foreignId('branch_id')->nullable()->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->string('name');
            $table->string('display_name')->nullable();  // ว่าง = ใช้ name
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->default(1);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true); // ปิดที่นี่ = หายจากทุกเมนูพร้อมกัน
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        /*
        | ตัวเลือกแต่ละอัน
        |
        | มีผลกับสต๊อกได้ 2 ทาง — ใช้ทางใดทางหนึ่ง ห้ามใช้พร้อมกัน
        |   1. portion_multiplier  คูณสูตรฐานทั้งสูตร (กลุ่ม "ปริมาณ")
        |                          ธรรมดา 1.00 / พิเศษ 1.50 / จัมโบ้ 2.00
        |   2. modifier_recipe_items  เพิ่ม/ลดวัตถุดิบเป็นรายการ
        |                          (กลุ่ม "เนื้อสัตว์" หมู/ไก่/เนื้อวัว)
        |
        | ใช้พร้อมกันจะกลายเป็นคูณซ้อนแล้วตัดสต๊อกเกินจริง
        */
        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price_delta', 12, 2)->default(0); // บวก/ลบจากราคาหลัก
            $table->decimal('portion_multiplier', 5, 2)->default(1);  // คูณสูตรฐาน
            $table->boolean('scales_with_portion')->default(true);    // ของที่เพิ่มโตตามขนาดจานไหม
            $table->boolean('is_default')->default(false);            // เลือกไว้ให้ตั้งแต่เปิดหน้าต่าง
            // ตัวเลือกนี้แปลว่า "ห่อกลับบ้าน" — ใช้เดาประเภทบิลตอนสรุปรายงาน
            $table->boolean('marks_takeaway')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // เมนูไหนใช้เซ็ตไหน — is_active ตรงนี้ปิดเฉพาะเมนูนี้ เมนูอื่นยังใช้เซ็ตเดิมได้
        Schema::create('modifier_group_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->unique(['product_id', 'modifier_group_id'], 'mgp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_group_product');
        Schema::dropIfExists('branch_product');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
