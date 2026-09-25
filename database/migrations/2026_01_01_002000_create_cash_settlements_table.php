<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | การนำส่งเงินสดประจำวัน
        |
        | ร้านรับเงินสดจากลูกค้า แต่พนักงานโอนเข้าบัญชีบริษัทแทนการนำฝากเงินสด
        | ตารางนี้คือหลักฐานว่าเงินก้อนนั้นถูกส่งเข้าบริษัทแล้วหรือยัง
        |
        | หนึ่งแถวต่อ "สาขา + วันขาย" ไม่ใช่ต่อรอบการขาย เพราะตกลงกันว่านำส่งสิ้นวันรวมทุกรอบ
        | (ปิดรอบ = นับเงินในลิ้นชัก / นำส่ง = เงินก้อนนั้นถึงบริษัทหรือยัง คนละเรื่องกัน)
        */
        Schema::create('cash_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->date('business_date');

            /*
            | เก็บสามยอด เพราะเงินหายได้สองจังหวะและต้องแยกให้ออกว่าหายตรงไหน
            |   expected    บิลบอกว่าควรได้เท่าไหร่
            |   counted     นับในลิ้นชักตอนปิดรอบได้เท่าไหร่ (หักเงินทอนตั้งต้นออกแล้ว)
            |   transferred พนักงานโอนเข้าบัญชีจริงเท่าไหร่
            | expected != counted คือเงินหายที่หน้าเคาน์เตอร์
            | counted  != transferred คือเงินหายระหว่างทางไปธนาคาร
            */
            $table->decimal('expected_amount', 14, 2)->default(0);
            $table->decimal('counted_amount', 14, 2)->default(0);
            $table->decimal('transferred_amount', 14, 2)->default(0);

            /*
            | เงินสดที่พนักงานรับมาจริงตอนเน็ตหลุด แต่ลงบิลไม่ได้ (offline_sync_entries.held)
            |
            | ── ทำไมต้องแยกคอลัมน์ ไม่บวกรวมใน expected_amount ────────────────
            | expected_amount มีสัญญาข้อเดียวคือ "คำนวณจากบิล" ซึ่งเป็นตัวเลขที่ตรวจย้อนหลังได้
            | ถ้าเอาเงินที่ไม่มีบิลรองรับไปบวกทับ ตัวเลขนั้นจะเลิกตรวจได้ทันที
            |
            | ── ทำไมต้องนับรวมในยอดที่นำส่ง ───────────────────────────────
            | เงินก้อนนี้อยู่ในลิ้นชักจริง พนักงานต้องส่งมอบจริง
            | ถ้าไม่นับรวม ปลายวันระบบจะขึ้นว่า "เงินเกิน" แล้วคนที่ทำถูกจะกลายเป็นคนที่ต้องอธิบาย
            |
            | ยอดที่ต้องนำส่ง = expected_amount + held_cash_amount (ดู CashSettlement::due())
            */
            $table->decimal('held_cash_amount', 14, 2)->default(0);

            $table->decimal('diff_amount', 14, 2)->default(0);   // transferred - (expected + held_cash)

            $table->string('status', 20)->default('pending');    // pending|submitted|verified|disputed

            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at')->nullable();
            $table->string('reference', 100)->nullable();        // เลขอ้างอิงรายการโอน
            $table->string('slip_path')->nullable();             // รูปสลิป

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // หนึ่งวันมีได้ใบเดียวต่อสาขา — กันการนำส่งซ้ำซ้อนตั้งแต่ระดับฐานข้อมูล
            $table->unique(['branch_id', 'business_date']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_settlements');
    }
};
