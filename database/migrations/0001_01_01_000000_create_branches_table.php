<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 20)->unique();          // รหัสสาขา
            $table->string('name');                         // ชื่อสาขา
            $table->string('tax_id', 20)->nullable();       // เลขผู้เสียภาษี
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 50)->default('Asia/Bangkok');
            $table->string('currency', 3)->default('THB');
            $table->decimal('vat_rate', 5, 2)->default(7.00);           // ภาษีมูลค่าเพิ่ม %
            $table->boolean('vat_included')->default(true);             // ราคารวม VAT แล้วหรือไม่
            $table->decimal('service_charge_rate', 5, 2)->default(0);   // ค่าบริการ %
            $table->unsignedTinyInteger('rounding_mode')->default(0);   // 0=ไม่ปัด 1=ปัดขึ้น 2=ปัดลง 3=ปัดใกล้สุด
            $table->time('business_day_start')->default('05:00:00');    // ตัดรอบวันขาย

            // หน้าร้านออนไลน์
            $table->boolean('is_accepting_online_orders')->default(true);
            $table->time('open_time')->default('09:00:00');
            $table->time('close_time')->default('21:00:00');
            $table->unsignedSmallInteger('prep_minutes')->default(20);   // ใช้เดาเวลารับของเร็วสุด
            $table->boolean('award_points_online')->default(true);       // ออเดอร์ออนไลน์ได้แต้มไหม

            // QR บนโต๊ะสั่งได้เฉพาะตอนพนักงานเปิดโต๊ะแล้ว
            // กันคนถ่ายรูป QR กลับบ้านแล้วสั่งเล่น — ปิดได้ถ้าร้านให้ลูกค้าสแกนสั่งเองเลย
            $table->boolean('qr_requires_open_table')->default(true);
            $table->string('cover_path')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('intro')->nullable();

            // พร้อมเพย์สำหรับสร้าง QR รับเงิน
            $table->string('promptpay_id', 20)->nullable();              // เบอร์มือถือ / เลขประจำตัวผู้เสียภาษี
            $table->string('promptpay_name', 60)->nullable();

            // สวัสดิการพนักงานองค์กร
            $table->boolean('staff_benefit_enabled')->default(true);
            $table->decimal('staff_benefit_monthly_cap', 12, 2)->default(0); // 0 = ไม่จำกัด
            $table->boolean('staff_benefit_exclude_alcohol')->default(true);

            // จำกัดเวลาขายแอลกอฮอล์ — ปิดไว้เป็นค่าเริ่มต้น
            // ถ้าเปิดใช้ ร้านต้องตั้งช่วงเวลาเองให้ตรงกับระเบียบที่บังคับใช้อยู่
            $table->boolean('restrict_alcohol_hours')->default(false);
            $table->json('alcohol_hours')->nullable();          // [{"from":"11:00","to":"14:00"}, ...]

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
