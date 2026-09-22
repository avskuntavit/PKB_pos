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
        | เครื่องพิมพ์ของสาขา
        |--------------------------------------------------------------------------
        | เครื่องพิมพ์ความร้อนเกือบทุกยี่ห้อพูด ESC/POS และเปิดพอร์ต 9100 รอ socket
        | เซิร์ฟเวอร์อยู่ใน LAN เดียวกันจึงยิงตรงไปหา IP ได้เลย ไม่ต้องมีตัวกลาง
        |
        | driver แยกไว้ตั้งแต่แรก เผื่อวันที่ย้ายระบบขึ้นคลาวด์ — ตอนนั้นเซิร์ฟเวอร์
        | จะเข้าถึงเครื่องพิมพ์ในร้านไม่ได้ ต้องเปลี่ยนเป็นแบบที่เครื่องพิมพ์
        | วิ่งมาถามเองแทน (CloudPRNT) ซึ่งเป็นคนละทิศทางกันโดยสิ้นเชิง
        */
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');                                  // "เครื่องครัว", "เครื่องเคาน์เตอร์"
            $table->string('driver', 20)->default('escpos_network'); // escpos_network | cloudprnt (ยังไม่ได้ทำ)

            $table->string('host', 100)->nullable();                 // IP ในวง LAN
            $table->unsignedSmallInteger('port')->default(9100);

            // 58 มม. พิมพ์ได้ 32 ตัวอักษรต่อบรรทัด / 80 มม. ได้ 48
            $table->unsignedTinyInteger('columns')->default(48);

            /*
            | จุดผลิตที่เครื่องนี้รับ — เก็บเป็น json เพราะเครื่องเดียวรับหลายจุดได้
            | เช่น ร้านเล็กใช้เครื่องเดียวรับทั้งครัวและบาร์
            | ว่าง = ไม่รับใบสั่งครัว (เครื่องใบเสร็จอย่างเดียว)
            */
            $table->json('print_groups')->nullable();

            $table->boolean('prints_receipt')->default(false);       // ออกใบเสร็จที่เครื่องนี้
            $table->boolean('opens_cash_drawer')->default(false);    // ลิ้นชักต่อพ่วงอยู่ที่เครื่องนี้
            $table->unsignedTinyInteger('copies')->default(1);       // พิมพ์กี่ใบต่องาน
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        /*
        |--------------------------------------------------------------------------
        | คิวงานพิมพ์
        |--------------------------------------------------------------------------
        | เก็บ "ไบต์ที่จะส่ง" ไว้เลย ไม่ใช่เก็บแค่ id ของบิลแล้วไปสร้างใหม่ตอนพิมพ์
        |
        | เหตุผล: ใบสั่งครัวต้องเป็นภาพ ณ ตอนกดส่งครัว ถ้าเครื่องพิมพ์ดับไป 10 นาที
        | แล้วค่อยกลับมา ระหว่างนั้นพนักงานอาจแก้บิลไปแล้ว — กระดาษที่ออกมาต้องตรง
        | กับที่สั่งจริงตอนนั้น ไม่ใช่สถานะล่าสุด
        |
        | ราคาที่จ่ายคือกินที่เก็บมากกว่า แต่ใบหนึ่งไม่กี่ร้อยไบต์ ถือว่าคุ้ม
        */
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind', 20);                 // kitchen_ticket | receipt | drawer | test
            $table->nullableMorphs('source');           // ใบสั่งครัว / บิล ที่เป็นต้นทาง
            $table->string('title')->nullable();        // ไว้โชว์ในหน้าคิวงานพิมพ์ให้คนอ่านรู้เรื่อง

            $table->binary('payload');                  // ไบต์ ESC/POS ที่พร้อมส่ง

            $table->string('status', 20)->default('pending');   // pending|printing|done|failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();      // ถอยเวลาก่อนลองใหม่
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            // ตัวดึงงานใช้สามคอลัมน์นี้เรียงกัน
            $table->index(['branch_id', 'status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('printers');
    }
};
