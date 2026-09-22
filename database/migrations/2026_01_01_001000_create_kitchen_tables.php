<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * ใบสั่งครัว = รายการที่ถูก "ส่งครัว" พร้อมกัน 1 ครั้ง แยกตามจุดผลิต
         * ส่งครั้งเดียวแต่มีทั้งอาหารและเครื่องดื่ม จะได้ 2 ใบ (ครัว 1 ใบ บาร์ 1 ใบ)
         */
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_table_id')->nullable()->constrained()->nullOnDelete();

            $table->string('ticket_no', 30);
            $table->unsignedTinyInteger('print_group')->default(1);  // 1=ครัว 2=บาร์ 3=ของหวาน
            $table->unsignedInteger('round')->default(1);            // สั่งรอบที่เท่าไหร่ของบิลนี้
            // คอร์สที่ส่งรอบนี้ — พิมพ์บนหัวใบให้ครัวรู้ว่ากำลังทำกองไหน
            $table->unsignedTinyInteger('course')->nullable();
            $table->string('status', 20)->default('queued');         // queued|preparing|ready|served|cancelled
            $table->string('source', 20)->default('pos');            // pos|self_order
            $table->string('order_type', 20)->default('dine_in');
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->date('business_date');
            $table->timestamps();

            $table->unique(['branch_id', 'ticket_no']);
            $table->index(['branch_id', 'status', 'queued_at']);
            $table->index(['order_id', 'round']);
        });

        Schema::create('kitchen_ticket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ

            // snapshot เผื่อรายการต้นทางถูกยกเลิกหรือแก้ไขภายหลัง
            $table->string('product_name');
            $table->decimal('qty', 10, 3)->default(1);
            $table->string('modifiers_text')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('queued');   // queued|ready|cancelled
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_items');
        Schema::dropIfExists('kitchen_tickets');
    }
};
