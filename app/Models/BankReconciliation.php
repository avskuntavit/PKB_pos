<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\ReconcileStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliation extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'expected_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'diff_amount' => 'decimal:2',
            'status' => ReconcileStatus::class,
            'reconciled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    /** ยังต้องตามต่อ */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', ReconcileStatus::Matched->value);
    }

    /**
     * ช่องทางเป็น enum ไหม
     *
     * เก็บเป็น string ในฐานข้อมูลเพราะช่องทางอาจเพิ่มทีหลัง
     * และข้อมูลเก่าที่ช่องทางถูกถอดออกแล้วต้องยังอ่านได้ ไม่ใช่ระเบิดตอน cast
     */
    public function method(): ?PaymentMethod
    {
        return PaymentMethod::tryFrom((string) $this->channel);
    }

    public function channelLabel(): string
    {
        return $this->method()?->label() ?? (string) $this->channel;
    }
}
