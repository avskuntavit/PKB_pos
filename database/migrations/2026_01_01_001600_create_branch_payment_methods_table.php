<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * ช่องทางชำระเงินที่แต่ละสาขาเปิดรับ
         *
         * แยกเป็นตารางแทนที่จะเก็บเป็น json ในสาขา เพราะต้อง join กับรายงานยอดขาย
         * และต้องเรียงลำดับปุ่มบนหน้าจอรับเงินได้เอง
         *
         * สาขาที่ไม่มีแถวเลย = เปิดทุกช่องทาง (กันระบบล็อกตัวเองตอนเพิ่งติดตั้ง)
         */
        Schema::create('branch_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('method', 30);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('show_on_storefront')->default(true);  // ให้ลูกค้าเลือกตอนสั่งล่วงหน้าไหม
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('label_override', 60)->nullable();
            $table->string('note')->nullable();                    // ข้อความเตือนพนักงาน
            $table->timestamps();

            $table->unique(['branch_id', 'method']);
            $table->index(['branch_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_payment_methods');
    }
};
