<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * กระทบยอดเงินเข้าบัญชีธนาคาร — หนึ่งแถวต่อ สาขา + วันขาย + ช่องทาง
 *
 * ── ทำไมแยกเป็นช่องทาง ไม่ใช่ติ๊กทั้งวันทีเดียว ─────────────
 * เงินแต่ละช่องทางเข้าบัญชีคนละเวลา เงินโอนพร้อมเพย์เข้าทันที
 * บัตรเครดิตเข้าวันถัดไปหลังหักค่าธรรมเนียม แอปเดลิเวอรี่จ่ายเป็นรอบสัปดาห์
 * ถ้าติ๊กรวมทั้งวัน วันที่รอเงินแอปอยู่จะติ๊กไม่ได้เลยทั้งที่ช่องทางอื่นตรงหมดแล้ว
 *
 * ── ทำไมเก็บ expected_amount ซ้ำกับที่คำนวณได้ ─────────────
 * คือ snapshot ของตัวเลขที่คนกดเห็นตอนกระทบ ถ้าบิลย้อนหลังถูกแก้ทีหลัง
 * เราต้องรู้ว่าตอนนั้นเขายืนยันยอดเท่าไหร่ ไม่ใช่ยอดที่คำนวณใหม่วันนี้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->date('business_date');
            $table->string('channel', 30);                        // ค่าเดียวกับ payments.method

            $table->decimal('expected_amount', 14, 2)->default(0);  // ที่ควรเข้าบัญชี ตอนกด
            $table->decimal('actual_amount', 14, 2)->nullable();    // ที่เห็นในสเตทเมนต์จริง
            $table->decimal('diff_amount', 14, 2)->default(0);

            $table->string('status', 20)->default('pending');       // pending|matched|mismatched
            $table->string('reference', 100)->nullable();           // เลขรายการ/รอบจ่ายของธนาคาร
            $table->string('note', 255)->nullable();

            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            // กันกดซ้ำจากสองเครื่องแล้วได้สองแถวของช่องทางเดียวกัน
            $table->unique(['branch_id', 'business_date', 'channel']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
