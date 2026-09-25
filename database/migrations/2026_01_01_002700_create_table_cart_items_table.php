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
        | ตะกร้าร่วมของโต๊ะ
        |--------------------------------------------------------------------------
        | ของที่คนทั้งโต๊ะเลือกไว้แต่ยังไม่ได้กดส่งครัว
        | กดส่งเมื่อไหร่แถวพวกนี้กลายเป็น order_items แล้วถูกล้างทิ้ง
        |
        | ── ทำไมย้ายจาก localStorage มาไว้ในฐานข้อมูล ──────────────────────
        | เดิมตะกร้าอยู่ในเบราว์เซอร์ของแต่ละคน (useGuestCart) ซึ่งแปลว่า
        | คนที่นั่งโต๊ะเดียวกันไม่มีทางเห็นของกันและกันจนกว่าจะสั่งไปแล้ว
        | ผลคือสั่งซ้ำกันเอง หรือรอกันไปมาว่าใครจะกดสั่ง
        |
        | ── ทำไมไม่ผูกกับโต๊ะ แต่ผูกกับ "รอบการนั่ง" ───────────────────────
        | ผูกกับโต๊ะแล้วของที่ลูกค้ากลุ่มก่อนเลือกค้างไว้จะโผล่ให้กลุ่มใหม่เห็น
        | table_session ปิดพร้อมบิล ตะกร้าจึงหมดอายุพร้อมกันโดยไม่ต้องล้างเอง
        |
        | ── ทำไมเก็บ unit_price ไว้ด้วย ──────────────────────────────────
        | ไว้โชว์ยอดรวมในตะกร้าเท่านั้น **ไม่ใช่ราคาที่ใช้ตัดบิล**
        | ตอนกดส่งครัว ระบบอ่านราคาจริงของสาขาใหม่อีกครั้ง (SelfOrderService)
        | ถ้าเชื่อราคาที่ client ส่งมา ใครก็แก้ราคาตัวเองได้
        */
        Schema::create('table_cart_items', function (Blueprint $table) {
            $table->id();

            // ตะกร้าตายพร้อมรอบการนั่ง — ลบ session แล้วของในตะกร้าต้องไม่ค้าง
            $table->foreignId('table_session_id')->constrained()->cascadeOnDelete();

            // ไม่ cascade — ลบเมนูทิ้งไม่ควรลบตะกร้าของลูกค้าที่กำลังนั่งอยู่เงียบ ๆ
            $table->foreignId('product_id')->constrained();

            /*
            | กุญแจยุบรายการซ้ำ: เมนู + ตัวเลือก(เรียงแล้ว) + หมายเหตุ
            | กดเพิ่มของเดิมซ้ำจึงเป็นการบวกจำนวนในบรรทัดเดิม ไม่ใช่เพิ่มบรรทัดใหม่
            | unique คู่กับ session เพื่อให้ updateOrCreate ทำงานได้โดยไม่ต้องล็อกทั้งตาราง
            */
            $table->string('line_key', 120);

            $table->decimal('qty', 8, 2)->default(1);
            $table->json('modifier_ids')->nullable();
            $table->string('note', 120)->nullable();

            // ราคาไว้โชว์เฉย ๆ (อ่านคำอธิบายด้านบน)
            $table->decimal('unit_price', 10, 2)->default(0);

            /*
            | ใครเป็นคนใส่ — ไม่ได้ใช้เป็นสิทธิ์ เพราะตกลงกันว่าคนทั้งโต๊ะแก้ของกันได้หมด
            | มีไว้บอกว่า "จานนี้ใครสั่ง" ซึ่งติดไปกับ order_items.guest_name ตอนกดส่ง
            | แล้วไปโผล่บนบิลโต๊ะ ทำให้แยกบิลรายคนทีหลังได้
            |
            | guest_key มาจาก session ฝั่งเซิร์ฟเวอร์ ไม่ใช่ค่าที่ client ส่งมา
            | ไม่งั้นใครก็ใส่ชื่อคนอื่นลงจานที่ตัวเองสั่งได้
            */
            $table->string('guest_key', 40)->nullable();
            $table->string('guest_name', 30)->nullable();

            $table->timestamps();

            $table->unique(['table_session_id', 'line_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_cart_items');
    }
};
