<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // บิล
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dining_table_id')->nullable()->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('customer_id')->nullable()->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ

            $table->string('order_no', 30);                  // เลขที่บิล เช่น B26090001
            $table->string('receipt_no', 30)->nullable();    // เลขที่ใบเสร็จ (ออกตอนชำระเงิน)
            $table->date('business_date');                   // วันขาย (ตัดรอบตาม business_day_start)
            $table->string('type', 20)->default('dine_in');  // dine_in|takeaway|delivery
            $table->string('status', 20)->default('open');   // open|paid|void|refunded
            $table->string('channel', 30)->default('pos');   // pos|grab|lineman|shopeefood|foodpanda
            $table->string('source', 20)->default('pos');    // pos|self_order|online — บิลนี้เริ่มจากไหน

            // ออเดอร์ล่วงหน้าจากหน้าร้านออนไลน์
            $table->string('track_token', 64)->nullable()->unique();     // ลิงก์ติดตามของลูกค้า
            $table->string('fulfilment_status', 20)->nullable();         // placed|accepted|preparing|ready|completed|rejected
            $table->string('contact_name', 100)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->timestamp('pickup_at')->nullable();                  // เวลานัดรับ / เวลาที่จะมาถึงร้าน
            $table->string('payment_intent', 30)->nullable();            // pay_at_store|promptpay|khon_la_khrueng|thai_chuay_thai
            $table->string('reject_reason')->nullable();
            $table->foreignId('placed_by_user_id')->nullable()->constrained('users');  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // ระบบคิว — เลขรันรายวันต่อสาขา ใช้เรียกลูกค้ามารับ
            $table->unsignedInteger('queue_number')->nullable();
            $table->timestamp('queue_called_at')->nullable();              // เรียกคิวครั้งล่าสุดเมื่อไหร่ (จอหน้าร้านใช้จุดเสียง)
            $table->unsignedTinyInteger('queue_called_count')->default(0); // เรียกไปกี่ครั้งแล้ว
            $table->timestamp('queue_skipped_at')->nullable();             // เรียกแล้วลูกค้าไม่มา — พักไว้ก่อน
            $table->unsignedTinyInteger('guest_count')->default(1);

            // ยอดเงิน — เก็บทุกชั้นเพื่อทำรายงานย้อนหลังได้ตรงกับหน้าสรุป
            $table->decimal('subtotal', 14, 2)->default(0);        // ยอดขายก่อนส่วนลด
            $table->decimal('item_discount', 14, 2)->default(0);   // ลดราคา (รายสินค้า)
            $table->decimal('bill_discount', 14, 2)->default(0);   // ลดท้ายบิล
            $table->decimal('promotion_discount', 14, 2)->default(0);
            $table->decimal('voucher_discount', 14, 2)->default(0);
            $table->decimal('staff_discount', 14, 2)->default(0);   // ส่วนลดสวัสดิการพนักงานองค์กร
            $table->decimal('service_charge', 14, 2)->default(0);  // ค่าบริการ
            $table->decimal('delivery_fee', 14, 2)->default(0);    // ค่าจัดส่ง
            $table->decimal('tax_amount', 14, 2)->default(0);      // ภาษี
            $table->decimal('rounding', 14, 2)->default(0);        // ยอดปัดเศษ
            $table->decimal('grand_total', 14, 2)->default(0);     // รวมสุทธิ
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('change_amount', 14, 2)->default(0);
            $table->decimal('cost_total', 14, 2)->default(0);      // ต้นทุนรวม (คำนวณกำไร)

            $table->foreignId('opened_by')->nullable()->constrained('users');  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('closed_by')->nullable()->constrained('users');  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('voided_by')->nullable()->constrained('users');  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'order_no']);
            /*
            | เลขที่ใบเสร็จห้ามซ้ำในสาขาเดียวกัน
            |
            | เป็นด่านสุดท้าย ไม่ใช่ด่านแรก — ตัวออกเลขล็อกแถวอยู่แล้ว
            | แต่ถ้ามีอะไรหลุดมาได้ ต้องให้ insert ล้มดัง ๆ ดีกว่าออกใบกำกับภาษีเลขซ้ำ
            | ซึ่งเป็นความผิดที่แก้ย้อนหลังไม่ได้
            */
            $table->unique(['branch_id', 'receipt_no']);
            $table->index(['branch_id', 'business_date', 'status']);
            $table->index(['branch_id', 'closed_at']);
            $table->index(['branch_id', 'fulfilment_status']);
            $table->index(['branch_id', 'business_date', 'queue_number']);
            $table->index('contact_phone');
        });

        // รายการในบิล
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_item_id')->nullable()->constrained('order_items');  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ

            // snapshot ไว้กันข้อมูลเพี้ยนเมื่อสินค้าถูกแก้ไขภายหลัง
            $table->string('product_name');
            $table->string('category_name')->nullable();
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('qty', 10, 3)->default(1);
            $table->decimal('modifier_total', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);

            /*
            | คอร์สที่รายการนี้อยู่ — null = ไม่จัดคอร์ส (ค่าเริ่มต้น)
            |
            | เป็นแค่ป้ายบอกกอง ไม่ได้บังคับลำดับอะไร พนักงานเลือกส่งทีละกองเอง
            | เก็บที่รายการไม่ใช่ที่บิล เพราะบิลเดียวมีหลายคอร์สพร้อมกันเป็นเรื่องปกติ
            */
            $table->unsignedTinyInteger('course')->nullable();
            // รอบที่สั่ง — ลูกค้านั่งโต๊ะเดียวสั่งได้หลายรอบ บิลเดียวจึงมีหลายรอบ
            // ใช้จัดกลุ่มให้ลูกค้าดูย้อนว่ารอบไหนสั่งอะไรไป รอบแรกคือ 1 เสมอ
            $table->unsignedTinyInteger('round')->default(1);
            // ชื่อเล่นคนสั่ง — มาจากลูกค้าที่สแกน QR เท่านั้น พนักงานคีย์ให้จะเป็น null
            // ไว้ตอบคำถามที่โต๊ะถามกันเองว่า "จานนี้ของใคร" ตอนอาหารมาถึง
            $table->string('guest_name', 30)->nullable();

            $table->string('status', 20)->default('pending'); // pending|sent|served|void
            $table->string('source', 20)->default('pos');     // pos|self_order — ใครเป็นคนสั่ง
            // null = ไม่ต้องอนุมัติ (พนักงานสั่งเอง) / pending|approved|rejected = ลูกค้าสั่งผ่าน QR
            $table->string('approval_status', 20)->nullable();
            $table->foreignId('table_session_id')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('voided_by')->nullable()->constrained('users');  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['order_id', 'approval_status']);
            $table->index('product_id');
        });

        // ตัวเลือกของแต่ละรายการ
        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name')->nullable();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });

        // การชำระเงิน — 1 บิลจ่ายได้หลายช่องทาง (split payment)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->string('method', 30);          // cash|promptpay|credit_card|transfer|ewallet|delivery_app
            $table->decimal('amount', 14, 2);      // ยอดที่ตัดกับบิล
            $table->decimal('received', 14, 2)->default(0);  // เงินที่รับมา (เงินสด)
            $table->decimal('change', 14, 2)->default(0);
            $table->decimal('fee', 14, 2)->default(0);       // ค่าธรรมเนียม เช่น GP ของ delivery
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_id', 'method']);
        });

        // โปรโมชั่นที่ถูกใช้ในบิล
        Schema::create('order_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        // การคืนเงิน
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->decimal('amount', 14, 2);
            $table->string('method', 30)->default('cash');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('order_promotions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
