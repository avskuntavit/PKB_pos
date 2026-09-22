<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // รอบการขาย — เปิด/ปิดรอบ พร้อมนับเงินในลิ้นชัก
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->string('shift_no', 30);
            $table->date('business_date');
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 12, 2)->default(0);   // เงินทอนตั้งต้น
            $table->decimal('cash_in', 12, 2)->default(0);        // นำเงินเข้า
            $table->decimal('cash_out', 12, 2)->default(0);       // นำเงินออก
            $table->decimal('expected_cash', 12, 2)->default(0);  // เงินสดที่ควรมี
            $table->decimal('counted_cash', 12, 2)->default(0);   // เงินสดที่นับได้
            $table->decimal('cash_diff', 12, 2)->default(0);      // ขาด/เกิน
            $table->string('status', 20)->default('open');        // open|closed
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'shift_no']);
            $table->index(['branch_id', 'business_date']);
        });

        // บันทึกนำเงินเข้า-ออกลิ้นชักระหว่างรอบ
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('type', 10);       // in|out
            $table->decimal('amount', 12, 2);
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('shifts');
    }
};
