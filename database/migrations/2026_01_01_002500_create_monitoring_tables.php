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
        | ประวัติการสำรองข้อมูล
        |--------------------------------------------------------------------------
        | ตารางนี้ไม่ได้เก็บตัวข้อมูลสำรอง เก็บแค่ "ผลของการสำรองแต่ละครั้ง"
        |
        | ── ทำไมต้องมีตาราง ทั้งที่ .bak ก็อยู่บนดิสก์ให้เห็นอยู่แล้ว ─────────
        | แอปอยู่คนละเครื่องกับ SQL Server — สั่ง BACKUP DATABASE ไปได้ แต่มองไม่เห็น
        | โฟลเดอร์ปลายทาง ถ้าไม่จดไว้เอง จะไม่มีทางรู้ว่าคืนล่าสุดสำรองผ่านหรือไม่
        | และการสำรองที่ไม่มีใครตรวจ คือการสำรองที่ยังไม่รู้ว่าพังจนถึงวันที่ต้องใช้
        |
        | ── slot คืออะไร ──────────────────────────────────────────────────
        | ไฟล์สำรองหมุนตามชื่อ ไม่ได้สร้างใหม่ทุกวันแล้วค่อยลบของเก่า
        |   รายวัน  7 ช่อง  mon..sun    เขียนทับเองอัตโนมัติทุกสัปดาห์
        |   รายเดือน 12 ช่อง m01..m12   เขียนทับเองอัตโนมัติทุกปี
        | รวมมากที่สุด 19 ไฟล์ ไม่โตเกินนี้ และ **ไม่ต้องลบไฟล์เลยสักครั้ง**
        | ซึ่งสำคัญมาก เพราะแอปลบไฟล์บนเครื่อง SQL Server ไม่ได้อยู่แล้ว
        */
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);            // database | uploads | exports
            $table->string('slot', 20)->nullable(); // mon..sun / m01..m12
            $table->string('status', 20);          // success | failed | skipped

            // ที่อยู่ของไฟล์ — ของ database คือ path บน "เครื่อง SQL Server" ไม่ใช่เครื่องแอป
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            /*
            | verified_at = ผ่าน RESTORE VERIFYONLY แล้ว
            |
            | สำรองเสร็จ ไม่ได้แปลว่าไฟล์ใช้กู้ได้ ดิสก์เสียระหว่างเขียนก็ยังจบแบบ "สำเร็จ"
            | VERIFYONLY อ่านไฟล์กลับทั้งก้อนแล้วตรวจ checksum — ช้ากว่าเดิมนิดหน่อย
            | แลกกับการรู้ตั้งแต่วันนี้ว่าไฟล์เสีย ไม่ใช่รู้วันที่ต้องกู้จริง
            */
            $table->timestamp('verified_at')->nullable();

            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // ใครกดสำรองเอง — NULL = ตัวตั้งเวลาเป็นคนสั่ง
            $table->foreignId('triggered_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['kind', 'status', 'finished_at']);
        });

        /*
        |--------------------------------------------------------------------------
        | error ของระบบ — รวมตัวซ้ำไว้เป็นแถวเดียว
        |--------------------------------------------------------------------------
        | ── ทำไมไม่เก็บทีละครั้ง ─────────────────────────────────────────
        | error ตัวเดียวบนหน้าที่คนกดบ่อยจะเกิดวันละหลายร้อยครั้ง ถ้าเก็บทีละแถว
        | ตารางจะบวมจนเปิดดูไม่ไหว และ "ของใหม่" จะจมอยู่ใต้ของเดิมที่ซ้ำ ๆ
        |
        | fingerprint = ลายนิ้วมือของบั๊กหนึ่งตัว (คลาส + ไฟล์ + บรรทัด + ข้อความที่ถอดตัวเลขออก)
        | เกิดซ้ำ = count เพิ่ม กับ last_seen_at ขยับ ไม่ได้เพิ่มแถวใหม่
        | เปิดหน้าเฝ้าดูแล้วเห็นทันทีว่ามีบั๊กกี่ตัว ไม่ใช่มี error กี่ครั้ง
        |
        | ── ทำไมไม่ผูก FK กับ users / branches ──────────────────────────
        | ตารางนี้เป็นบันทึกเหตุการณ์ ไม่ใช่ข้อมูลธุรกิจ ถ้าผูก FK ไว้
        | การลบผู้ใช้สักคนจะถูกบล็อกเพราะมี error เก่าค้างอ้างอยู่ ซึ่งไม่สมเหตุผล
        | และ SQL Server ก็ห้ามมีเส้นทางลบต่อหลายเส้นไปตารางเดียวกันอยู่แล้ว
        */
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();

            $table->string('level', 20)->default('error');
            $table->string('exception_class', 255);
            $table->text('message');
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();

            // บริบทตอนที่เกิดครั้งล่าสุด — พอให้ไล่ตามได้ว่าเกิดตรงไหน
            $table->string('url', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->text('trace')->nullable();

            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            /*
            | resolved_at = คนดูแลกดว่า "จัดการแล้ว"
            | ถ้าบั๊กตัวเดิมกลับมาเกิดอีก ระบบจะล้างค่านี้ให้เอง (เปิดเคสใหม่)
            | ไม่งั้นบั๊กที่คิดว่าแก้แล้วแต่ยังไม่หาย จะหายไปจากสายตาถาวร
            */
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();

            $table->timestamps();

            $table->index(['resolved_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
        Schema::dropIfExists('backup_runs');
    }
};
