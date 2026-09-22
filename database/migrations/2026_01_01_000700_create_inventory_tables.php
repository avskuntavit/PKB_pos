<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | วัตถุดิบ / สินค้าคงคลัง
        |
        | unit = หน่วยฐาน ใช้เก็บสต๊อกและเขียนสูตร (App\Enums\StockUnit)
        |        ควรเป็นหน่วยเล็กสุด เช่น กรัม / มล. / ชิ้น
        |
        | purchase_unit + purchase_factor = หน่วยตอนซื้อ
        |        เช่น ซื้อเป็น "กก." 1 กก. = 1000 กรัม
        |        ตอนรับของกรอก 2 กก. ระบบเพิ่มให้ 2000 กรัม และหารราคาให้เอง
        |
        | cost_per_unit เก็บ 4 ทศนิยม เพราะราคาต่อกรัมมักเป็นเลขเล็ก
        | ถ้าเก็บแค่ 2 ตำแหน่ง 0.045 จะปัดเป็น 0.05 คลาดไป 11%
        */
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->string('code', 40)->nullable();
            $table->string('name');
            $table->string('unit', 20)->default('g');
            $table->string('purchase_unit', 30)->nullable();
            $table->decimal('purchase_factor', 12, 4)->default(1);  // 1 หน่วยซื้อ = กี่หน่วยฐาน
            $table->decimal('stock_qty', 14, 3)->default(0);
            $table->decimal('cost_per_unit', 12, 4)->default(0);
            $table->decimal('reorder_level', 14, 3)->default(0);  // จุดสั่งซื้อ
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code']);
        });

        /*
        | สูตรอาหาร — ใช้ตัดสต๊อกอัตโนมัติเมื่อขาย
        |
        | ใส่เฉพาะส่วนที่ "ทุกแบบใช้เหมือนกัน" เช่น เส้น น้ำซุป ผัก
        | ส่วนที่ต่างกันตามที่ลูกค้าเลือก (หมู/ไก่/เนื้อวัว, รับ/ไม่รับลูกชิ้น)
        | ให้ไปใส่ที่ modifier_recipe_items แทน
        */
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            /*
            | สูตรแยกรายสาขา
            |
            | เมนูเป็นของกลาง แต่วัตถุดิบยังผูกสาขา (ingredients.branch_id)
            | สูตรจึงต้องรู้ว่าเป็นของสาขาไหน ไม่งั้น "ผัดไทย" ตัวเดียว
            | จะลากวัตถุดิบของทุกสาขามารวมกันเป็นสูตรเดียวแล้วตัดสต๊อกเกินจริง
            |
            | ผลคือแต่ละสาขาเขียนสูตรของตัวเอง ซึ่งตรงกับความจริงอยู่แล้ว —
            | คนละครัว คนละเจ้าวัตถุดิบ ปริมาณต่อจานก็ไม่เท่ากัน
            */
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 14, 4)->default(0);  // ใช้กี่หน่วยต่อ 1 จาน

            // ของที่ไม่ควรเพิ่มตามขนาดจาน เช่น ถุงพลาสติก 1 ใบ
            // ไม่ว่าลูกค้าจะสั่งธรรมดาหรือจัมโบ้ก็ใช้ใบเดียว
            $table->boolean('scales_with_portion')->default(true);

            $table->timestamps();

            $table->unique(['branch_id', 'product_id', 'ingredient_id']);
            $table->index(['branch_id', 'product_id']);
        });

        /*
        | วัตถุดิบที่ "ตัวเลือก" เพิ่มหรือลดจากสูตรฐาน
        |
        | เนื้อสัตว์: หมู -> +หมูหมัก / ไก่ -> +ไก่ / เนื้อวัว -> +เนื้อวัว
        | ลูกชิ้น:    รับ -> +ลูกชิ้น / ไม่รับ -> ไม่มีบรรทัด
        |
        | qty ติดลบได้ ถ้าตัวเลือกนั้นแปลว่า "ไม่ใส่"
        */
        Schema::create('modifier_recipe_items', function (Blueprint $table) {
            $table->id();
            // แยกรายสาขาด้วยเหตุผลเดียวกับ recipe_items — ตัวเลือกกลาง แต่วัตถุดิบเป็นของสาขา
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('modifier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 14, 4)->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'modifier_id', 'ingredient_id']);
            $table->index(['branch_id', 'modifier_id']);
        });

        // ความเคลื่อนไหวสต๊อก
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);           // purchase|usage|waste|adjust|transfer_in|transfer_out
            $table->decimal('qty', 14, 3);        // + เข้า / - ออก
            $table->decimal('cost', 14, 2)->default(0);
            $table->decimal('balance_after', 14, 3)->default(0);
            $table->nullableMorphs('reference');  // อ้างถึง order / purchase order
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('business_date')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'ingredient_id', 'occurred_at']);
            $table->index(['branch_id', 'type', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('modifier_recipe_items');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('ingredients');
    }
};
