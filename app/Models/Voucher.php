<?php

namespace App\Models;

use App\Enums\VoucherBase;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    /**
     * ค่าตั้งต้นเดียวกับที่ migration ตั้งไว้ให้คอลัมน์
     *
     * ── ทำไมต้องเขียนซ้ำที่นี่ ─────────────────────────────────────────────
     * `Voucher::create([...])` คืน object ที่มีแค่คอลัมน์ที่เราส่งไป
     * คอลัมน์ที่ปล่อยให้ฐานข้อมูลเติมค่าตั้งต้นจะเป็น **null ใน object นั้น**
     * Eloquent ไม่โหลดกลับมาให้และไม่ฟ้องอะไรเลย
     *
     * ผลที่เกิดขึ้นจริงคือ `is_active` เป็น null → `isRedeemable()` คืน false
     * → `discountFor()` คืน **0** โดยไม่มีข้อผิดพลาดใด ๆ
     * คูปองที่ควรลด 50 บาทกลายเป็นลด 0 บาท และไม่มีใครรู้ว่าทำไม
     *
     * อันตรายกว่านั้นคือ `base_mode` ที่เป็น null แล้ว `$voucher->base_mode->baseFor()`
     * จะเป็น error ตรง ๆ ตอนคิดเงิน
     *
     * ค่าพวกนี้ไม่ใช่การเดา — มันคือค่าเดียวกับที่ฐานข้อมูลจะเติมให้อยู่แล้ว
     * การประกาศไว้ทำให้ object ในหน่วยความจำพูดความจริงเรื่องแถวที่มันจะกลายเป็น
     * (เทสต์ `a fresh voucher behaves the same in memory and after reloading`
     *  คุมไว้ว่าสองฝั่งต้องไม่หลุดจากกัน)
     */
    protected $attributes = [
        'type' => 'amount',
        'value' => 0,
        'min_spend' => 0,
        'base_mode' => 'menu_total',
        'usage_limit' => 1,
        'used_count' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'base_mode' => VoucherBase::class,
            'value' => 'decimal:2',
            'min_spend' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    public function isRedeemable(float $amount): bool
    {
        return $this->is_active
            && $this->used_count < $this->usage_limit
            && $amount >= (float) $this->min_spend
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->ends_at || $this->ends_at->isFuture());
    }

    public function discountFor(float $amount): float
    {
        if (! $this->isRedeemable($amount)) {
            return 0.0;
        }

        $discount = $this->type === 'percent'
            ? $amount * (float) $this->value / 100
            : (float) $this->value;

        if ($this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        return round(min($discount, $amount), 2);
    }
}
