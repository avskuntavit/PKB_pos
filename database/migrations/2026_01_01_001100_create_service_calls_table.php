<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ลูกค้ากดเรียกพนักงานจากหน้าโต๊ะ — เรียกเก็บเงิน / เรียกพนักงาน / ขอน้ำ-อุปกรณ์
        Schema::create('service_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ
            $table->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained();  // ไม่ cascade — SQL Server ห้ามหลายเส้นทางลบต่อ

            $table->string('type', 20);                       // bill|assist|water
            $table->string('status', 20)->default('open');    // open|acknowledged|done|cancelled
            $table->string('note')->nullable();

            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->date('business_date');
            $table->timestamps();

            $table->index(['branch_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_calls');
    }
};
