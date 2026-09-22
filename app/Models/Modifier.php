<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ตัวเลือกหนึ่งอัน เช่น "จัมโบ้" "หมู" "รับลูกชิ้น"
 *
 * มีผลกับสต๊อกได้ 2 ทาง ใช้ทางใดทางหนึ่ง ห้ามใช้พร้อมกัน:
 *   portion_multiplier -> คูณสูตรฐานทั้งสูตร (กลุ่ม "ปริมาณ")
 *   recipeItems        -> เพิ่ม/ลดวัตถุดิบเป็นรายการ (กลุ่ม "เนื้อสัตว์")
 */
class Modifier extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'portion_multiplier' => 'decimal:2',
            'scales_with_portion' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'marks_takeaway' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    /** วัตถุดิบที่ตัวเลือกนี้เพิ่ม (qty บวก) หรือลด (qty ติดลบ) */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(ModifierRecipeItem::class);
    }

    /** ตัวเลือกนี้ปรับขนาดจานหรือไม่ */
    public function changesPortion(): bool
    {
        return (float) $this->portion_multiplier != 1.0;
    }
}
