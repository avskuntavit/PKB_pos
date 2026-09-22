<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // อ็อบเจ็กต์และสิ่งอำนวยความสะดวกในผังร้าน เช่น เคาน์เตอร์บาร์ แคชเชียร์ ครัว ทางเข้า ห้องน้ำ
        Schema::create('floor_plan_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);                         // cashier, bar, kitchen, entrance, restroom, pillar, wall, custom
            $table->string('name', 60);                         // เช่น แคชเชียร์ 1, บาร์เครื่องดื่ม, ทางเข้าหลัก
            $table->unsignedInteger('pos_x')->default(0);
            $table->unsignedInteger('pos_y')->default(0);
            $table->unsignedSmallInteger('width')->default(120);
            $table->unsignedSmallInteger('height')->default(60);
            $table->string('color', 30)->nullable();
            $table->string('icon', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_plan_objects');
    }
};
