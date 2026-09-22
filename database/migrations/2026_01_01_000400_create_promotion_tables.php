<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | โปรโมชั่น = เงื่อนไข (trigger) × รางวัล (reward)
        |
        | 5 แบบที่หน้าจอให้เลือก จริง ๆ คือการจับคู่สองแกนนี้:
        |   1. ลดราคาเมนูที่ร่วมรายการ      none   × item_percent|item_amount|item_fixed_price
        |   2. ซื้อครบ X ชิ้น แถม/ลด        qty    × free_item|item_*
        |   3. ซื้อครบ X บาท แถม/ลด         amount × free_item|item_*
        |   4. ซื้อครบ X ชิ้น ลดท้ายบิล      qty    × bill_percent|bill_amount
        |   5. ซื้อครบ X บาท ลดท้ายบิล       amount × bill_percent|bill_amount
        |
        | เก็บเป็นสองแกนแทน enum 5 ค่าแบน ๆ เพราะวันที่อยากได้ส่วนผสมใหม่
        | (เช่น ครบ 500 บาท แถมเมนู) จะได้ไม่ต้องรื้อตาราง
        */
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 255)->nullable();   // ข้อความที่ลูกค้าเห็น

            // เงื่อนไข — none = แค่มีเมนูที่ร่วมรายการอยู่ในบิลก็พอ
            $table->string('trigger_type', 10)->default('none');   // none|qty|amount
            $table->decimal('trigger_value', 12, 2)->default(0);   // X ชิ้น หรือ X บาท

            // รางวัล
            $table->string('reward_type', 20);   // item_percent|item_amount|item_fixed_price|free_item|bill_percent|bill_amount
            $table->decimal('reward_value', 12, 2)->default(0);
            $table->unsignedTinyInteger('free_qty')->default(1);    // แถมกี่ชิ้นต่อรอบ
            $table->decimal('max_discount', 12, 2)->nullable();

            // โปรเดียวใช้ซ้ำในบิลเดียวได้กี่รอบ — ซื้อ 3 แถม 1 สั่ง 9 ชิ้น = 3 รอบ ถ้าเปิดให้
            $table->unsignedTinyInteger('max_rounds')->default(1);

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'is_active']);
        });

        /*
        | เมนู/หมวดที่เกี่ยวข้องกับโปร
        |
        | role = trigger คือ "รายการที่เข้าโปร" (ตัวนับเงื่อนไขและตัวรับส่วนลดรายการ)
        | role = reward  คือ "เมนูของแถมที่ให้ลูกค้าเลือก"
        |
        | ไม่ผูก trigger ไว้เลย = ทั้งร้านเข้าโปร
        | ผูกได้ทั้งรายเมนูและรายหมวด — หมวดคือ "ทุกเมนูในหมวดนี้ รวมเมนูที่เพิ่มทีหลัง"
        */
        Schema::create('promotion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10);   // trigger|reward
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();

            $table->index(['promotion_id', 'role']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type', 20)->default('amount');  // amount|percent
            $table->decimal('value', 12, 2)->default(0);
            $table->decimal('min_spend', 12, 2)->default(0);
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->unsignedInteger('usage_limit')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
        });

        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable();
            $table->foreignId('customer_id')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('promotion_items');
        Schema::dropIfExists('promotions');
    }
};
