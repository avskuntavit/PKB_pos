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
        | การโอนของระหว่างสถานี
        |--------------------------------------------------------------------------
        | ทำได้ตั้งแต่ของในคลังกลายเป็นของกลาง (stock_items.branch_id = NULL)
        | เมื่อก่อนของชิ้นเดียวกันคนละสาขาเป็นคนละ id การ "โอน" จึงไม่มีความหมาย
        |
        | ── ทำไมต้องสองขั้น (ส่ง -> รับ) ────────────────────────────────────
        | ของที่ออกจากสาขาหนึ่งไม่ได้ถึงอีกสาขาทันที ระหว่างทางมันไม่ได้อยู่ที่ไหนเลย
        | ถ้าตัดและเพิ่มพร้อมกันในขั้นเดียว ระบบจะบอกว่าปลายทางมีของแล้วทั้งที่ยังไม่ถึง
        | แล้วปลายทางจะขายของที่ยังมาไม่ถึง — สต๊อกติดลบตอนของมาถึงจริง
        |
        | ขั้นที่ 1 ส่ง : ตัดของออกจากต้นทางทันที (ของออกจากร้านไปแล้วจริง ๆ)
        | ขั้นที่ 2 รับ : ปลายทางกรอกจำนวนที่รับได้จริง แล้วของค่อยเข้าสต๊อกปลายทาง
        |
        | ── ของขาดระหว่างทางไปไหน ──────────────────────────────────────────
        | ถ้ารับได้น้อยกว่าที่ส่ง ส่วนต่างคือของที่หายจริง ๆ — **ไม่** สร้างรายการชดเชยใด ๆ
        | ต้นทางตัดไปแล้ว ปลายทางไม่ได้รับ ผลรวมในระบบจึงลดลงเท่ากับที่หายไปจริง
        | ส่วนต่างโชว์บนใบโอนให้เห็นชัด จะได้ตามได้ว่าหายที่ไหน
        */
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('ref_no', 40)->unique();

            /*
            | ไม่ใส่ cascade/nullOnDelete ที่ FK พวกนี้เลย
            |
            | SQL Server ห้ามมีเส้นทางลบต่อ (cascade / set null) หลายเส้นไปตารางเดียวกัน
            | ตารางนี้ชี้ไป branches สองเส้น และชี้ไป users สามเส้น
            | ถ้าใส่ nullOnDelete จะสร้างตารางไม่ผ่านตั้งแต่ migration แรกบน SQL Server
            */
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');

            $table->string('status', 20)->default('in_transit');  // in_transit|received|cancelled
            $table->string('note')->nullable();

            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->timestamp('sent_at')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamp('received_at')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->date('business_date')->nullable();   // วันขายของต้นทางตอนกดส่ง
            $table->timestamps();

            $table->index(['from_branch_id', 'status']);
            $table->index(['to_branch_id', 'status']);
        });

        /*
        | qty_received เป็น NULL จนกว่าปลายทางจะกดรับ
        | ห้ามตั้งค่าเริ่มต้นเป็น 0 เพราะ 0 แปลว่า "รับแล้วแต่ไม่ได้ของเลย" ซึ่งคนละเรื่องกัน
        |
        | unit_cost = ต้นทุนต่อหน่วยฐานของ **ต้นทาง ณ วินาทีที่กดส่ง**
        | ต้องเก็บ snapshot ไว้ ไม่ใช่ไปอ่านใหม่ตอนรับ เพราะระหว่างนั้นต้นทางอาจรับของใหม่
        | เข้ามาแล้วต้นทุนเฉลี่ยขยับ — ของที่ส่งไปแล้วต้องพกต้นทุนของตัวเองไปด้วย
        */
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained();

            $table->decimal('qty_sent', 14, 3);
            $table->decimal('qty_received', 14, 3)->nullable();
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'stock_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
