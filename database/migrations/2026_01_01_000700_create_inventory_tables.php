<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | ของในคลัง — แม่แบบกลาง
        |
        | ── ทำไมชื่อ stock_items ไม่ใช่ ingredients ──────────────────────────
        | คลังเก็บทุกอย่างที่ร้านใช้ ไม่ใช่แค่ของกิน ถุงพลาสติก หลอด กล่องใส่อาหาร
        | น้ำแข็ง ก็อยู่ในคลังและตัดสต๊อกได้ คำว่า ingredient จึงแคบเกินความจริง
        |
        | ── branch_id = NULL คือของกลาง ──────────────────────────────────────
        | เมนูเป็นของกลางอยู่แล้ว ถ้าของในคลังยังผูกสาขา เปิดสาขาใหม่ทีต้องคีย์
        | รายการของทั้งคลังใหม่ทั้งชุด และแก้ชื่อ/หน่วยทีต้องไล่แก้ทุกสาขา
        |
        | ยอดคงเหลือ ต้นทุน และจุดสั่งซื้อ **ไม่ได้อยู่ในตารางนี้** เพราะเป็นของรายสาขา
        | ดูตาราง branch_stock_items ข้างล่าง
        |
        | ── branch_key คืออะไร ───────────────────────────────────────────────
        | เงาของ branch_id ที่แปลง NULL เป็น 0 มีไว้ให้ unique(branch_key, code)
        | ทำงานกับของกลางได้ เพราะ SQL ถือว่า NULL ไม่เท่ากับ NULL
        | วิธีเดียวกับ products.branch_key
        |
        | unit = หน่วยฐาน ใช้เขียนสูตรและเก็บสต๊อก (App\Enums\StockUnit)
        |        ควรเป็นหน่วยเล็กสุด เช่น กรัม / มล. / ชิ้น
        | purchase_unit + purchase_factor = หน่วยตอนซื้อ เช่น ซื้อเป็น กก. (1 = 1000 กรัม)
        |        ตอนรับของกรอก 2 กก. ระบบเพิ่มให้ 2000 กรัม และหารราคาให้เอง
        */
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained();  // NULL = ของกลาง
            $table->unsignedBigInteger('branch_key')->default(0);       // 0 = ของกลาง — ใช้ทำ unique เท่านั้น
            $table->string('code', 40)->nullable();
            $table->string('name');
            $table->string('unit', 20)->default('g');
            $table->string('purchase_unit', 30)->nullable();
            $table->decimal('purchase_factor', 12, 4)->default(1);  // 1 หน่วยซื้อ = กี่หน่วยฐาน
            $table->boolean('is_active')->default(true);            // ปิดทั้งระบบ (สาขาปิดเองได้ที่ตารางล่าง)
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_key', 'code']);
            $table->index(['branch_id', 'is_active']);
        });

        /*
        | ยอดคงเหลือ ต้นทุน และจุดสั่งซื้อ — แยกรายสาขา
        |
        | ── ทำไมต้องแยกออกมา ────────────────────────────────────────────────
        | ของชิ้นเดียวกันแต่ละสาขามีไม่เท่ากัน ซื้อมาคนละราคา และตั้งจุดสั่งซื้อไม่เท่ากัน
        | ถ้าเก็บไว้บนแม่แบบกลาง สาขาที่สองจะทับยอดของสาขาแรกทันทีที่รับของ
        |
        | ── cost_per_unit เก็บ 4 ทศนิยม ─────────────────────────────────────
        | ราคาต่อกรัมมักเป็นเลขเล็ก ถ้าเก็บแค่ 2 ตำแหน่ง 0.045 จะปัดเป็น 0.05 คลาดไป 11%
        |
        | ── is_active ที่นี่ต่างจากของแม่แบบ ─────────────────────────────────
        | ของแม่แบบ = เลิกใช้ทั้งระบบ · ของที่นี่ = สาขานี้ไม่ได้ใช้ของชิ้นนี้
        | สาขาที่ไม่ขายเครื่องดื่มไม่ต้องเห็นน้ำเชื่อมในรายการของตัวเอง
        */
        Schema::create('branch_stock_items', function (Blueprint $table) {
            $table->id();
            // ไม่ cascade ที่ branch_id — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();

            $table->decimal('stock_qty', 14, 3)->default(0);
            $table->decimal('cost_per_unit', 12, 4)->default(0);
            $table->decimal('reorder_level', 14, 3)->default(0);  // จุดสั่งซื้อ
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'stock_item_id']);
            $table->index(['branch_id', 'stock_qty']);
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
            | สูตรยังแยกรายสาขา แม้ของในคลังจะเป็นกลางแล้ว
            |
            | ตั้งใจให้เป็นแบบนี้ เพราะปริมาณต่อจานเป็นเรื่องของครัวแต่ละที่จริง ๆ
            | สาขาที่ลูกค้าเป็นนักเรียนใส่เส้น 150 กรัม อีกสาขาใส่ 120 กรัม
            | ของเดียวกัน สูตรคนละตัวเลข — บังคับให้เท่ากันจะผิดกับหน้างาน
            |
            | สิ่งที่เปลี่ยนไปคือ stock_item_id ชี้ไปแม่แบบกลางได้แล้ว
            | ไม่ต้องสร้าง "เส้นเล็ก" ซ้ำทุกสาขาเหมือนก่อน
            */
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 14, 4)->default(0);  // ใช้กี่หน่วยต่อ 1 จาน

            // ของที่ไม่ควรเพิ่มตามขนาดจาน เช่น ถุงพลาสติก 1 ใบ
            // ไม่ว่าลูกค้าจะสั่งธรรมดาหรือจัมโบ้ก็ใช้ใบเดียว
            $table->boolean('scales_with_portion')->default(true);

            $table->timestamps();

            $table->unique(['branch_id', 'product_id', 'stock_item_id']);
            $table->index(['branch_id', 'product_id']);
        });

        /*
        | ของที่ "ตัวเลือก" เพิ่มหรือลดจากสูตรฐาน
        |
        | เนื้อสัตว์: หมู -> +หมูหมัก / ไก่ -> +ไก่ / เนื้อวัว -> +เนื้อวัว
        | ลูกชิ้น:    รับ -> +ลูกชิ้น / ไม่รับ -> ไม่มีบรรทัด
        |
        | qty ติดลบได้ ถ้าตัวเลือกนั้นแปลว่า "ไม่ใส่"
        */
        Schema::create('modifier_recipe_items', function (Blueprint $table) {
            $table->id();
            // แยกรายสาขาด้วยเหตุผลเดียวกับ recipe_items — ปริมาณเป็นเรื่องของครัวแต่ละที่
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('modifier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 14, 4)->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'modifier_id', 'stock_item_id']);
            $table->index(['branch_id', 'modifier_id']);
        });

        /*
        | ความเคลื่อนไหวสต๊อก
        |
        | branch_id ที่นี่สำคัญกว่าเดิมมาก — เมื่อก่อนอ่านจากตัววัตถุดิบได้เพราะ
        | วัตถุดิบผูกสาขาอยู่แล้ว ตอนนี้ของเป็นกลาง สาขาจึงต้องระบุมาตรง ๆ เสมอ
        */
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
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

            $table->index(['branch_id', 'stock_item_id', 'occurred_at']);
            $table->index(['branch_id', 'type', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('modifier_recipe_items');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('branch_stock_items');
        Schema::dropIfExists('stock_items');
    }
};
