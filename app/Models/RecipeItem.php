<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หนึ่งบรรทัดในสูตรอาหาร — เมนู X ของสาขา Y ใช้ของในคลัง Z กี่หน่วย
 *
 * ── ทำไมยังผูกสาขา ทั้งที่ของในคลังเป็นกลางแล้ว ────────────
 * เพราะปริมาณต่อจานเป็นเรื่องของครัวแต่ละที่จริง ๆ สาขาหนึ่งใส่เส้น 150 กรัม
 * อีกสาขาใส่ 120 กรัม ของชิ้นเดียวกันแต่ตัวเลขคนละค่า
 * สิ่งที่เปลี่ยนไปคือ stock_item_id ชี้แม่แบบกลางได้แล้ว ไม่ต้องสร้างของซ้ำทุกสาขา
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

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
