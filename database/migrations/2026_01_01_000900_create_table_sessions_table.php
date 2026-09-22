<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * รอบการนั่งโต๊ะของลูกค้า 1 กลุ่ม
         *
         * QR บนโต๊ะเป็น token ถาวร (dining_tables.qr_token) — สแกนแล้วระบบมาหาหรือสร้าง session นี้
         * ลูกค้าถือ session_token ไว้ใน cookie สั่งเพิ่มได้เรื่อย ๆ จนกว่าบิลจะถูกปิด
         * ปิดบิลเมื่อไหร่ session ปิดตาม โต๊ะต่อไปสแกน QR เดิมได้ session ใหม่ทันที
         */
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('session_token', 64)->unique();
            $table->string('status', 20)->default('active');   // active|closed|expired
            $table->unsignedTinyInteger('guest_count')->default(1);
            $table->unsignedInteger('order_count')->default(0); // จำนวนครั้งที่กดสั่ง
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason', 50)->nullable();    // paid|staff_closed|expired
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['dining_table_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
    }
};
