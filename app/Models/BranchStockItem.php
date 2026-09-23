<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ยอดคงเหลือ ต้นทุน และจุดสั่งซื้อของของชิ้นหนึ่ง ในสาขาหนึ่ง
 *
 * แม่แบบของ (StockItem) เป็นของกลาง แต่ตัวเลขพวกนี้ต้องแยกสาขา
 * เพราะแต่ละสาขามีของไม่เท่ากัน ซื้อมาคนละราคา และตั้งจุดสั่งซื้อไม่เท่ากัน
 *
 * แถวนี้ถูกสร้างตอนที่สาขาแตะของชิ้นนั้นครั้งแรก (รับของ / ปรับยอด / ตั้งจุดสั่งซื้อ)
 * ไม่ได้สร้างล่วงหน้าให้ทุกสาขา × ทุกชิ้น เพราะส่วนใหญ่จะเป็นแถวว่างเปล่า
 */
class BranchStockItem extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'stock_qty' => 0,
        'cost_per_unit' => 0,
        'reorder_level' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'stock_qty' => 'decimal:3',
            // 4 ตำแหน่ง เพราะราคาต่อกรัมเป็นเลขเล็ก ปัด 2 ตำแหน่งแล้วต้นทุนเพี้ยน
            'cost_per_unit' => 'decimal:4',
            'reorder_level' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /** ต่ำกว่าจุดสั่งซื้อแล้ว — ข้ามของที่ยังไม่ได้ตั้งจุดสั่งซื้อ (0) */
    public function scopeLow(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->whereColumn('stock_qty', '<=', 'reorder_level');
    }

    public function isLow(): bool
    {
        return (float) $this->reorder_level > 0
            && (float) $this->stock_qty <= (float) $this->reorder_level;
    }

    public function value(): float
    {
        return round((float) $this->stock_qty * (float) $this->cost_per_unit, 2);
    }
}
