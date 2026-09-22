<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * ไทม์ไลน์ของออเดอร์ล่วงหน้า — ใช้แสดงหน้าติดตามฝั่งลูกค้า
         * แยกออกมาเป็นตารางเพราะอยากเก็บ "เวลาที่เปลี่ยนสถานะ" ทุกครั้ง
         * ไม่ใช่แค่สถานะล่าสุด (เอาไว้วัดว่าร้านตอบรับช้าแค่ไหน)
         */
        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_events');
    }
};
