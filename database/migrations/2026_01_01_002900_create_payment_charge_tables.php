<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รับเงินผ่าน QR ของผู้ให้บริการชำระเงิน
 *
 * ── ปัญหาที่แก้ ─────────────────────────────────────────────────────────
 * ตอนนี้พร้อมเพย์เป็น QR คงที่ของร้าน ลูกค้าโอนแล้วระบบไม่รู้เลยว่าเงินเข้าหรือยัง
 * พนักงานต้องขอดูสลิปทีละใบ ซึ่งเป็นจุดที่ปลอมสลิปได้ง่ายที่สุดในร้าน
 *
 * ── ทำไมแยกเป็น payment_charges ไม่ใส่ลงตาราง payments เลย ───────────────
 * `payments` คือ "เงินที่เข้าบิลแล้ว" — หนึ่งแถวเท่ากับยอดที่ตัดกับบิลจริง
 * ส่วนตารางนี้คือ "QR ที่ออกไปแล้ว" ซึ่งส่วนใหญ่จะไม่มีเงินเข้าเลย
 * (ลูกค้าเปลี่ยนใจ ปิดหน้าจอ หมดอายุ) ถ้าปนกัน ยอดขายจะพองตามจำนวน QR ที่ออก
 *
 * และเราต้องเก็บคำตอบดิบของเกตเวย์ไว้เถียงกับเขาทีหลังได้ ซึ่งไม่ใช่หน้าที่ของ payments
 *
 * ── ทำไม polling ไม่ใช่ webhook ─────────────────────────────────────────
 * เซิร์ฟเวอร์อยู่บนเครื่องในร้าน เกตเวย์ยิงเข้ามาไม่ได้ถ้าไม่เปิด port forward
 * และระบบนี้ยังไม่มี queue worker เดินอยู่ (มีแค่ php-fpm · nginx · schedule:work)
 * งานที่โยนเข้าคิวจะนอนอยู่ในตารางตลอดไป
 *
 * `schedule:work` ที่เดินอยู่แล้วจึงเป็นเครื่องมือที่มีจริง — ไล่ถามทุกนาที
 * ช้ากว่า webhook ไม่กี่สิบวินาที แต่ไม่ต้องเปิดทางเข้าเครื่องในร้านจากอินเทอร์เน็ต
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | บัญชีผู้ให้บริการของแต่ละสาขา
        |
        | แยกตารางเพราะสาขาละบัญชี และหนึ่งสาขาอาจมีหลายเจ้าในช่วงย้ายระบบ
        | (ของเดิมยังรับเงินค้างอยู่ ของใหม่เริ่มรับของวันนี้)
        */
        Schema::create('payment_provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ

            /** static|paysolutions|xendit|beam|stripe — ดู App\Enums\PaymentProvider */
            $table->string('provider', 30);

            /** test|live — แยกให้ชัด เพราะคีย์ทดสอบที่หลุดไปใช้จริงคือบิลที่ไม่มีเงินเข้า */
            $table->string('mode', 10)->default('test');

            /*
            | กุญแจของเกตเวย์ — เข้ารหัสด้วย APP_KEY (cast เป็น encrypted:array)
            |
            | ไม่เก็บใน .env เพราะสาขาละบัญชี และเจ้าของร้านต้องแก้เองได้จากหลังบ้าน
            | โดยไม่ต้องให้โปรแกรมเมอร์มาแก้ไฟล์แล้ว deploy ใหม่
            |
            | ผลข้างเคียงที่ต้องรู้: `.env` ไม่ได้อยู่ในชุดสำรอง ถ้า APP_KEY หาย
            | คอลัมน์นี้อ่านไม่ออกทั้งตาราง — ต้องเก็บ APP_KEY แยกไว้ด้วยมือ
            */
            $table->text('credentials')->nullable();

            $table->boolean('is_active')->default(false);

            /** เปิดให้เกตเวย์นี้รับเงินตั้งแต่เมื่อไหร่ — ไว้ตัดรอบตอนย้ายเจ้า */
            $table->timestamp('activated_at')->nullable();

            $table->string('note', 255)->nullable();
            $table->timestamps();

            // หนึ่งสาขาหนึ่งเจ้าหนึ่งแถว
            $table->unique(['branch_id', 'provider']);
        });

        Schema::create('payment_charges', function (Blueprint $table) {
            $table->id();

            /** อ้างอิงที่ส่งให้เกตเวย์เป็น merchant reference ของเรา */
            $table->uuid('uuid')->unique();

            $table->foreignId('branch_id')->constrained();
            $table->foreignId('order_id')->constrained();

            $table->string('provider', 30);
            $table->string('method', 30)->default('promptpay');

            /*
            | id ของรายการฝั่งเกตเวย์ — **ด่านกันบันทึกซ้ำตัวจริง**
            |
            | unique(provider, provider_charge_id) ทำให้การไล่ถามซ้ำ ๆ
            | หรือสองเครื่องถามพร้อมกัน ไม่สามารถสร้างรายการเดียวกันสองแถวได้
            |
            | nullable เพราะ driver `static` (QR คงที่ของร้าน) ไม่มีเกตเวย์ให้คุย
            | จึงไม่มี id มาให้ — SQL Server อนุญาตให้ NULL ซ้ำกันใน unique index
            | ที่สร้างแบบ filtered ได้ แต่ Laravel สร้างแบบธรรมดา จึงกันซ้ำด้วย
            | คอลัมน์ uuid ที่ unique อยู่แล้วสำหรับกรณีไม่มี id ของเกตเวย์
            */
            $table->string('provider_charge_id', 100)->nullable();

            /** ยอดที่ขอให้จ่าย — คิดจากบิลตอนออก QR ไม่ให้ใครพิมพ์เอง */
            $table->decimal('amount', 14, 2);

            /** ยอดที่เกตเวย์บอกว่าเข้ามาจริง — อาจไม่เท่า amount */
            $table->decimal('paid_amount', 14, 2)->default(0);

            $table->string('currency', 3)->default('THB');

            /** ข้อความ EMVCo ที่เอาไปวาดเป็น QR — ของ static คือของร้าน ของเกตเวย์คือของเขา */
            $table->text('qr_payload')->nullable();

            /*
            | pending|paid|expired|failed|cancelled|mismatch|unmatched
            |
            | สองสถานะท้ายคือเงินที่เข้ามาแล้วแต่ลงบิลอัตโนมัติไม่ได้ — ต้องมีคนตัดสิน
            |   mismatch   ยอดที่เข้าไม่เท่ากับที่ขอ
            |   unmatched  บิลถูกปิดไปแล้วด้วยวิธีอื่น เงินก้อนนี้ไม่มีบิลรองรับ
            | ดู App\Enums\ChargeStatus
            */
            $table->string('status', 20)->default('pending');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            /** ไล่ถามเกตเวย์ครั้งล่าสุดเมื่อไหร่ — ตัวจัดคิวเรียงจากที่ค้างนานสุด */
            $table->timestamp('last_polled_at')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);

            /*
            | แถวใน payments ที่ถูกสร้างจากรายการนี้
            |
            | มีค่า = ลงบิลไปแล้ว ห้ามลงอีก · เป็นหลักฐานว่าลงครั้งเดียวจริง
            | ไม่ผูก FK เพราะถ้าบิลถูกลบ ประวัติว่าเคยมีเงินเข้าต้องไม่หายตามไปด้วย
            */
            $table->unsignedBigInteger('settled_payment_id')->nullable();

            /** คำตอบดิบครั้งล่าสุดของเกตเวย์ — ไว้เถียงกับเขาทีหลัง */
            $table->json('raw')->nullable();

            $table->string('failure_message', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'provider_charge_id']);

            // ตัวไล่ถามถามคำถามเดียว: "มีอะไรค้างและถามมานานสุด"
            $table->index(['status', 'last_polled_at']);

            // หน้ารายงานอ่านตามสาขาและช่วงเวลา
            $table->index(['branch_id', 'created_at']);

            // หน้าบิลถามว่า "บิลนี้มี QR ค้างอยู่ไหม"
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_charges');
        Schema::dropIfExists('payment_provider_accounts');
    }
};
