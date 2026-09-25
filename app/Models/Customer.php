<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * ลูกค้า — เป็น Authenticatable เพราะล็อกอินได้ด้วยเบอร์ + OTP
 * ไม่มีคอลัมน์ password เพราะระบบนี้ไม่ใช้รหัสผ่านกับลูกค้าเลย
 */
class Customer extends Authenticatable
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'employee_status' => EmployeeStatus::class,
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'employee_requested_at' => 'datetime',
            'employee_reviewed_at' => 'datetime',
            'points' => 'integer',
            'total_spent' => 'decimal:2',
            'visit_count' => 'integer',
            'last_visit_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(CustomerPointTransaction::class);
    }

    public function employeeReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_reviewed_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(OrderReview::class);
    }

    public function benefitUsages(): HasMany
    {
        return $this->hasMany(StaffBenefitUsage::class);
    }

    /** ได้รับสิทธิ์พนักงานองค์กรแล้วหรือยัง */
    public function isVerifiedEmployee(): bool
    {
        return $this->employee_status === EmployeeStatus::Approved;
    }

    /**
     * ยอดส่วนลดที่ใช้ไปแล้วในงวดเดือนที่ระบุ (รูปแบบ Y-m)
     *
     * ── ทำไม $period ไม่มีค่าเริ่มต้น ─────────────────────────────
     * เดิมมีค่าเริ่มต้นเป็น now()->format('Y-m') ซึ่งผิดสำหรับร้านที่ตัดรอบ 05:00
     * ตอนตีสองของวันที่ 1 ร้านยังขายอยู่ในวันขายของเดือนก่อน แต่ now() บอกเดือนใหม่
     * ตัวบันทึก (StaffBenefitService::record) ใช้ business_date เป็นงวดอยู่แล้ว
     * ยอดสะสมจึงถูกมองข้าม = เพดานวงเงินรีเซ็ตเร็วไปไม่กี่ชั่วโมง ใช้เกินเพดานได้
     *
     * ค่าเริ่มต้นที่ถูกเกือบตลอดแต่ผิดในช่วงที่ไม่มีใครเฝ้า เป็นค่าเริ่มต้นที่ไม่ควรมี
     * บังคับให้ระบุจึงดีกว่า — ใช้ Branch::currentPeriod() หรือเดือนของ business_date ของบิล
     */
    public function benefitUsedIn(string $period): float
    {
        return (float) $this->benefitUsages()
            ->where('period', $period)
            ->sum('discount_amount');
    }

    /** ลูกค้าล็อกอินด้วยเบอร์ ไม่ใช่อีเมล */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthPassword(): string
    {
        // ไม่ใช้รหัสผ่าน — ล็อกอินผ่าน OTP แล้ว Auth::login() เท่านั้น
        return '';
    }
}
