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

            /*
            | ข้อมูลแบรนด์ของสถานี
            |
            | **theme_color ไม่ได้อยู่ในไฟล์นี้** — ถูกเพิ่มที่
            | 2026_01_01_001800_add_theme_color_to_branches_table.php
            | อย่าย้ายมาไว้ตรงนี้โดยไม่ลบไฟล์นั้นทิ้งก่อน ไม่งั้น migrate:fresh
            | จะล้มด้วย "duplicate column name: theme_color" แล้วเทสต์ที่ใช้ฐานข้อมูลตกทั้งชุด
            |
            | promo_title หายไปจริง ๆ ทั้งที่ PromoController เขียนและ MenuController อ่าน
            | ฐานข้อมูลเก่ามีคอลัมน์นี้อยู่เพราะเคยถูกสร้างไว้ก่อน แล้วบรรทัดหายไปทีหลัง
            |
            | store_type เก็บเป็นค่าจาก App\Enums\StoreType
            | latitude/longitude ใช้ 7 ตำแหน่งทศนิยม ละเอียดระดับ ~1 ซม. พอสำหรับหมุดหน้าร้าน
            */
            $table->string('promo_title', 60)->nullable();   // หัวข้อแถบโปรโมทบนหน้าสั่งอาหาร
            $table->string('store_type', 40)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // พร้อมเพย์สำหรับสร้าง QR รับเงิน
            $table->string('promptpay_id', 20)->nullable();              // เบอร์มือถือ / เลขประจำตัวผู้เสียภาษี
            $table->string('promptpay_name', 60)->nullable();

            /*
            | ฐานที่คูปองใหม่ของสาขานี้จะถูกตั้งให้เป็นค่าเริ่มต้น
            |
            | เป็น "ค่าตั้งต้นตอนสร้าง" ไม่ใช่ค่าที่ถูกอ่านสด ๆ ตอนคิดเงิน —
            | คูปองทุกใบเก็บฐานของตัวเองไว้ในแถวตัวเอง (vouchers.base_mode)
            | ถ้าเปลี่ยนค่านี้แล้วคูปองเก่าเปลี่ยนความหมายตาม เท่ากับแก้เงื่อนไข
            | ที่พิมพ์อยู่บนคูปองที่อยู่ในมือลูกค้าแล้ว ซึ่งทำไม่ได้
            |
            | ดู App\Enums\VoucherBase
            */
            $table->string('default_voucher_base', 20)->default('menu_total');

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
