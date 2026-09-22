<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * รหัส OTP สำหรับล็อกอินลูกค้า
         *
         * เก็บเป็น hash ไม่เก็บตัวเลขตรง ๆ คนที่เปิดฐานข้อมูลได้จะได้ไม่เห็นรหัสของลูกค้า
         * และเก็บเป็นตารางแยกแทนที่จะยัดลง customers เพราะต้องนับความถี่การขอรหัส
         * ของ "เบอร์ที่ยังไม่เคยเป็นลูกค้า" ด้วย
         */
        Schema::create('customer_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['phone', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_otp_codes');
    }
};
