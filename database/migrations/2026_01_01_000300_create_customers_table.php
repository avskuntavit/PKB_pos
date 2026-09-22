<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20)->nullable()->unique();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('tier', 20)->default('regular');   // regular|silver|gold|platinum
            $table->integer('points')->default(0);
            $table->decimal('total_spent', 14, 2)->default(0);
            $table->unsignedInteger('visit_count')->default(0);
            $table->timestamp('last_visit_at')->nullable();
            $table->text('note')->nullable();

            // สมาชิก — ล็อกอินด้วยเบอร์ + OTP ไม่มีรหัสผ่าน
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();

            // สิทธิ์พนักงานองค์กร
            $table->string('employee_code', 40)->nullable();
            $table->string('employee_status', 20)->nullable();   // pending|approved|rejected
            $table->string('employee_department', 100)->nullable();
            $table->string('employee_note')->nullable();
            $table->timestamp('employee_requested_at')->nullable();
            $table->timestamp('employee_reviewed_at')->nullable();
            $table->foreignId('employee_reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'phone']);
            $table->index('employee_status');
            $table->unique(['branch_id', 'employee_code']);
        });

        Schema::create('customer_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable();
            $table->string('type', 20);           // earn|redeem|adjust|expire
            $table->integer('points');            // + / -
            $table->integer('balance_after')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_point_transactions');
        Schema::dropIfExists('customers');
    }
};
