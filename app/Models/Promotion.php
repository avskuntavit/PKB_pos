<?php

namespace App\Models;

use App\Enums\PromotionReward;
use App\Enums\PromotionTrigger;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * โปรโมชั่นของสาขา — เงื่อนไข × รางวัล
 *
 * ตัวคำนวณจริงอยู่ที่ PromotionService ไม่ใช่ในโมเดล
 * เพราะต้องดูทั้งบิล (รายการไหนเข้าโปร ครบกี่รอบ) ไม่ใช่แค่ยอดก้อนเดียว
 */
class Promotion extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trigger_type' => PromotionTrigger::class,
            'trigger_value' => 'decimal:2',
            'reward_type' => PromotionReward::class,
            'reward_value' => 'decimal:2',
            'free_qty' => 'integer',
            'max_discount' => 'decimal:2',
            'max_rounds' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }

    /** เมนู/หมวดที่นับเป็น "รายการที่เข้าโปร" — ว่างเปล่า = ทั้งร้าน */
    public function triggerItems(): HasMany
    {
        return $this->items()->where('role', PromotionItem::ROLE_TRIGGER);
    }

    /** เมนูของแถมที่ให้ลูกค้าเลือก */
    public function rewardItems(): HasMany
    {
        return $this->items()->where('role', PromotionItem::ROLE_REWARD);
    }

    public function scopeActiveNow($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** คำอธิบายเงื่อนไขแบบอ่านรู้เรื่อง ใช้ทั้งหลังบ้านและใบเสร็จ */
    public function conditionText(): string
    {
        return match ($this->trigger_type) {
            PromotionTrigger::Qty => 'ซื้อครบ '.rtrim(rtrim(number_format((float) $this->trigger_value, 2), '0'), '.').' ชิ้น',
            PromotionTrigger::Amount => 'ซื้อครบ '.number_format((float) $this->trigger_value, 2).' บาท',
            PromotionTrigger::None => 'ไม่มีขั้นต่ำ',
        };
    }
}
