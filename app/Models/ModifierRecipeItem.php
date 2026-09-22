<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** วัตถุดิบที่ตัวเลือกหนึ่งเพิ่ม (qty บวก) หรือลด (qty ติดลบ) จากสูตรฐาน */
class ModifierRecipeItem extends Model
{
    // ผูกสาขาด้วยเหตุผลเดียวกับ RecipeItem — ตัวเลือกเป็นของกลาง แต่วัตถุดิบเป็นของสาขา
    use BelongsToBranch;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
