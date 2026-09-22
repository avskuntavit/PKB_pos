<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // บันทึกการกระทำของพนักงาน — ใช้ทำรายงาน "พนักงาน" (เปิดบิล/ส่งรายการ/จ่ายเงิน/ยกเลิก)
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50);   // order.open, order.item_sent, order.pay, order.void, ...
            $table->nullableMorphs('subject');
            $table->json('meta')->nullable();
            $table->date('business_date')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'business_date', 'action']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
