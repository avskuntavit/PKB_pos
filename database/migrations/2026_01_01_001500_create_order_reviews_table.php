<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ประเมินความพึงพอใจต่อบิล — 1 บิลให้คะแนนได้ครั้งเดียว
        Schema::create('order_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('rating');          // 1-5 ดาว
            $table->json('tags')->nullable();               // ['อาหารอร่อย','เสิร์ฟเร็ว']
            $table->text('comment')->nullable();
            $table->date('business_date');

            // ร้านตอบกลับรีวิว
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users');  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->timestamp('replied_at')->nullable();

            $table->timestamps();

            $table->unique('order_id');
            $table->index(['branch_id', 'business_date']);
            $table->index(['branch_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_reviews');
    }
};
