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
        | รูปบรรยากาศร้านของแต่ละสถานี
        |--------------------------------------------------------------------------
        | แยกเป็นตารางแทนที่จะยัด json ลง branches เพราะแต่ละรูปมีลำดับและคำบรรยาย
        | ของตัวเอง และต้องลบทีละใบได้โดยไม่ต้องเขียนทั้งก้อนใหม่
        |
        | รูปปก (cover_path) กับโลโก้ (logo_path) ยังอยู่บน branches เหมือนเดิม
        | เพราะมีได้อย่างละใบเดียวและถูกอ่านแทบทุก request
        */
        Schema::create('branch_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('path');                          // /storage/branches/xxx.jpg
            $table->string('caption', 120)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_images');
    }
};
