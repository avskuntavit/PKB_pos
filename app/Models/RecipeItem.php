<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หนึ่งบรรทัดในสูตรอาหาร — เมนู X ของสาขา Y ใช้วัตถุดิบ Z กี่หน่วย
 *
 * ผูกสาขาด้วย เพราะเมนูเป็นของกลางแต่วัตถุดิบยังเป็นของสาขา
 * ถ้าไม่ผูก สูตรของเมนูเดียวจะลากวัตถุดิบของทุกสาขามารวมกัน
 */
class RecipeItem extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
