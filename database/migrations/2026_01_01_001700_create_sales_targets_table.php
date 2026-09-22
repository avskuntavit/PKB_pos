<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | เป้ายอดขาย — ตั้งเป็น "รายเดือนต่อสาขา" ตัวเลขเดียว
        |
        | ผู้บริหารคิดเป็นเดือนอยู่แล้ว การบังคับให้กรอก 30 ช่องต่อเดือน
        | จบลงที่ไม่มีใครกรอก ระบบจึงรับตัวเลขเดือนเดียวแล้วเกลี่ยเป็นรายวันให้เอง
        | โดยถ่วงน้ำหนักตามวันในสัปดาห์จากยอดขายจริงย้อนหลัง (ดู SalesTargetService)
        |
        | target_amount เทียบกับ orders.grand_total ของบิลที่ปิดแล้ว (ยอดสุทธิรวม VAT)
        | เพราะเป็นตัวเลขเดียวกับที่โชว์บนหน้ารายงานสรุปและที่เจ้าของเห็นในลิ้นชัก
        */
        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');                  // 1-12
            $table->decimal('target_amount', 14, 2)->default(0);   // เป้ายอดขายสุทธิทั้งเดือน

            // เป้าต้นทุนวัตถุดิบเป็น % ของยอดขาย — ร้านอาหารไทยทั่วไปตั้งกันที่ 30-35%
            // null = ยังไม่ตั้ง หน้า dashboard จะไม่ขึ้นไฟเตือนต้นทุน
            $table->decimal('food_cost_percent', 5, 2)->nullable();

            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->timestamps();

            $table->unique(['branch_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};
