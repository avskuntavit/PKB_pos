<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ของในคลังที่ตัวเลือกหนึ่งเพิ่ม (qty บวก) หรือลด (qty ติดลบ) จากสูตรฐาน */
class ModifierRecipeItem extends Model
{
    // ผูกสาขาด้วยเหตุผลเดียวกับ RecipeItem — ปริมาณเป็นเรื่องของครัวแต่ละที่
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

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
