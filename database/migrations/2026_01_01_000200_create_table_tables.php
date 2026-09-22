<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // โซน เช่น ชั้น 1 / ระเบียง / ห้องแอร์
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // โต๊ะ — เก็บพิกัดไว้วาดผังโต๊ะบนหน้า POS
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 20);                        // A1, A2, VIP1
            $table->string('qr_token', 64)->unique();          // token ถาวรใน QR ที่ติดหน้าโต๊ะ
            $table->unsignedTinyInteger('seats')->default(4);
            // พิกัดและขนาดบนผังร้าน หน่วยเป็นพิกเซล (หน้าออกแบบ snap เป็นช่องละ 20)
            // ต้องเป็น smallInteger ไม่ใช่ tinyInteger เพราะหน้าออกแบบให้ลากขยายได้ถึง 600
            // ซึ่งเกิน 255 ที่ tinyInteger รับได้
            $table->unsignedInteger('pos_x')->default(0);
            $table->unsignedInteger('pos_y')->default(0);
            $table->unsignedSmallInteger('width')->default(90);
            $table->unsignedSmallInteger('height')->default(90);
            $table->string('shape', 10)->default('square');    // square|rectangle|circle
            $table->string('status', 20)->default('available'); // available|occupied|reserved|cleaning
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('zones');
    }
};
