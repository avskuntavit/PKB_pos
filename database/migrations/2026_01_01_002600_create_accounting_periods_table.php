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
        | การปิดงวดบัญชี
        |--------------------------------------------------------------------------
        | หนึ่งแถว = "งวดเดือนหนึ่งของสถานีหนึ่ง ถูกปิดไปแล้ว"
        | ไม่มีแถว = งวดนั้นยังเปิดอยู่ (ค่าเริ่มต้นของทุกเดือนที่ยังไม่มีใครแตะ)
        |
        | ── ทำไมต้องมีของแบบนี้ ────────────────────────────────────────────
        | เฟส C (กระทบยอดธนาคาร) กับ D (ส่งข้อมูลให้บัญชี) จับได้แค่ว่า
        | "ข้อมูลเปลี่ยนไปหลังส่งแล้ว" แต่ห้ามไม่ให้เปลี่ยนไม่ได้
        |
        | พอยื่นภาษีของเดือนสิงหาไปแล้ว แล้ววันหนึ่งมีคนกดคืนเงินบิลเดือนสิงหา
        | ตัวเลขในระบบกับตัวเลขที่ยื่นไปจะไม่ตรงกันอีกเลย และไม่มีใครรู้จนถึงตอนตรวจ
        |
        | ── ทำไมเก็บแถวที่เปิดกลับไว้ด้วย แทนที่จะลบทิ้ง ──────────────────
        | "ใครเปิดงวดที่ปิดแล้วกลับมา ตอนไหน และอ้างเหตุผลว่าอะไร" คือข้อมูลที่
        | ผู้ตรวจสอบบัญชีถามเป็นคำถามแรก ลบแถวทิ้งแล้วคำถามนี้ตอบไม่ได้เลย
        | สถานะจึงมีสองค่า closed กับ reopened และนับจำนวนครั้งที่เปิดกลับไว้ด้วย
        */
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();

            // ไม่ใส่ cascade — ตารางนี้ชี้ไป users สองเส้น (ปิด/เปิดกลับ) และ branches หนึ่งเส้น
            // SQL Server ห้ามมีเส้นทางลบต่อหลายเส้นไปตารางเดียวกัน
            $table->foreignId('branch_id')->constrained('branches');

            // 'YYYY-MM' — เก็บเป็นข้อความเพราะงวดคือ "เดือน" ไม่ใช่ "วันใดวันหนึ่งของเดือน"
            // เก็บเป็น date แล้วจะมีคนเผลอเทียบด้วยวันที่ 1 หรือวันสิ้นเดือนสลับกันไปมา
            $table->string('period', 7);

            $table->string('status', 20)->default('closed');  // closed | reopened

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->string('note')->nullable();

            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users');
            $table->string('reopen_reason')->nullable();
            $table->unsignedSmallInteger('times_reopened')->default(0);

            $table->timestamps();

            // หนึ่งสถานีมีงวดเดือนหนึ่งได้แถวเดียว — ปิดแล้วเปิดแล้วปิดใหม่ก็ยังแถวเดิม
            $table->unique(['branch_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
