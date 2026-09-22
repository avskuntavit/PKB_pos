<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * บันทึกการใช้สิทธิ์สวัสดิการพนักงาน 1 บรรทัดต่อ 1 บิล
         *
         * มีไว้ 2 อย่าง:
         *   1. คุมเพดานวงเงินต่อเดือน (รวมยอดของเดือนนั้นได้เร็ว ไม่ต้องไล่ทั้ง orders)
         *   2. ให้บัญชีดึงไปตั้งเป็นค่าใช้จ่ายสวัสดิการของบริษัท
         *
         * period เก็บเป็น YYYY-MM เพื่อให้ group ตามรอบเดือนได้ตรงไปตรงมา
         */
        Schema::create('staff_benefit_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('employee_code', 40)->nullable();
            $table->string('period', 7);                            // 2026-09
            $table->decimal('discount_amount', 12, 2);              // มูลค่าที่บริษัทออกให้
            $table->decimal('order_total', 12, 2)->default(0);      // ยอดที่ลูกค้าจ่ายจริง
            $table->date('business_date');
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['customer_id', 'period']);
            $table->index(['branch_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_benefit_usages');
    }
};
