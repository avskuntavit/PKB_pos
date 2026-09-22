<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * บันทึกการส่งข้อมูลการขายให้ระบบบัญชี — หนึ่งแถวต่อสาขาต่อวันขาย
 *
 * ── ทำไมต้องมีตาราง ส่งแล้วก็จบไม่ได้เหรอ ──────────────────
 * ไม่ได้ เพราะสามคำถามที่ต้องตอบได้ทุกวัน:
 *   วันไหนยังไม่ได้ส่ง · ส่งแล้วสำเร็จไหม · ส่งไปแล้วข้อมูลเปลี่ยนทีหลังหรือเปล่า
 * ถ้าไม่เก็บ ก็ต้องไปไล่ถามปลายทางซึ่งเราไม่ได้คุมและอาจไม่มีใครตอบ
 *
 * ── source_fingerprint คืออะไร ─────────────────────────────
 * ลายนิ้วมือของข้อมูลวันนั้นตอนที่ส่ง (จำนวนบิล + ยอดรวม + เวลาแก้ไขล่าสุด)
 * ถ้าวันนี้คำนวณใหม่แล้วไม่ตรง แปลว่ามีคนแก้บิลย้อนหลังหลังส่งไปแล้ว
 * ซึ่งเป็นกรณีที่ต้องส่งใหม่ และเป็นคำถามข้อ 9 ที่ยังรอ SAM ตอบว่าจะรับยังไง
 *
 * ── payload_hash ต่างจาก source_fingerprint ยังไง ──────────
 * อันแรกคือแฮชของ "สิ่งที่เราส่งออกไป" ใช้ยืนยันว่าไฟล์ที่ปลายทางได้คือชุดเดียวกับที่เราบันทึก
 * อันหลังคือแฮชของ "ข้อมูลต้นทาง" ใช้ดูว่าข้อมูลขยับหลังส่งหรือยัง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->date('business_date');

            $table->string('status', 20)->default('pending');   // pending|sent|failed
            $table->string('driver', 30);                        // file|api|database
            $table->string('format', 10)->nullable();            // json|csv (เฉพาะ driver file)
            $table->string('detail', 10);                        // summary|bills|both

            $table->unsignedInteger('bill_count')->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);

            $table->string('payload_hash', 64)->nullable();
            $table->string('source_fingerprint', 64)->nullable();

            // เลขอ้างอิงที่ปลายทางให้มา หรือชื่อไฟล์ที่เขียน (คั่นด้วย , ถ้าหลายไฟล์)
            $table->string('reference', 500)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ส่งซ้ำได้ แต่ต้องทับแถวเดิม ไม่ใช่สร้างประวัติซ้อนกันจนไม่รู้ว่าอันไหนของจริง
            $table->unique(['branch_id', 'business_date']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_exports');
    }
};
