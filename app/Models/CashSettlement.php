<?php

namespace App\Models;

use App\Enums\CashSettlementStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBusinessDate;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashSettlement extends Model
{
    use BelongsToBranch, HasBusinessDate, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'expected_amount' => 'decimal:2',
            'held_cash_amount' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'transferred_amount' => 'decimal:2',
            'diff_amount' => 'decimal:2',
            'status' => CashSettlementStatus::class,
            'transferred_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * ยอดที่พนักงานต้องนำส่งจริง
     *
     * = ยอดจากบิล + เงินสดที่รับมาแล้วตอนเน็ตหลุดแต่ยังไม่มีบิลรองรับ
     *
     * สองก้อนนี้เก็บแยกคอลัมน์เพราะ expected_amount ต้องคงความหมายว่า "มาจากบิล"
     * แต่เวลาเทียบกับเงินที่โอนต้องใช้ยอดรวม ไม่งั้นเงินที่ค้างจะโผล่เป็น "เงินเกิน" ปริศนา
     */
    public function due(): float
    {
        return Money::round((float) $this->expected_amount + (float) $this->held_cash_amount);
    }

    /** ยังไม่จบเรื่อง — ผู้จัดการต้องตามต่อ */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', CashSettlementStatus::Verified->value);
    }

    /** ค้างมากี่วันแล้ว — 0 = ของวันนี้ */
    public function daysOverdue(): int
    {
        return max(0, (int) $this->business_date->diffInDays(now()->startOfDay()));
    }
}
