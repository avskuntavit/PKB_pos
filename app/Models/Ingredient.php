<?php

namespace App\Models;

use App\Enums\StockUnit;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * วัตถุดิบ / ของในคลัง — เก็บได้ทุกอย่างที่ร้านใช้
 * ไม่ใช่แค่ของกิน ถุงพลาสติก หลอด กล่อง ก็อยู่ในนี้และตัดสต๊อกได้
 *
 * ยอดคงเหลือและสูตรอยู่ใน "หน่วยฐาน" (unit) เสมอ
 * ส่วน purchase_unit ใช้เฉพาะตอนรับของเข้า เพื่อไม่ต้องคิดเลขเอง
 */
class Ingredient extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit' => StockUnit::class,
            'purchase_factor' => 'decimal:4',
            'stock_qty' => 'decimal:3',
            // 4 ตำแหน่ง เพราะราคาต่อกรัมเป็นเลขเล็ก ปัด 2 ตำแหน่งแล้วต้นทุนเพี้ยน
            'cost_per_unit' => 'decimal:4',
            'reorder_level' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** ตัวเลือกที่ใช้วัตถุดิบตัวนี้ เช่น "เพิ่มเนื้อ" ใช้หมูหมัก */
    public function modifierRecipeItems(): HasMany
    {
        return $this->hasMany(ModifierRecipeItem::class);
    }

    public function isBelowReorderLevel(): bool
    {
        return (float) $this->stock_qty <= (float) $this->reorder_level;
    }

    public function stockValue(): float
    {
        return round((float) $this->stock_qty * (float) $this->cost_per_unit, 2);
    }

    /* ---------- หน่วย ---------- */

    public function unitLabel(): string
    {
        return $this->unit->label();
    }

    /** ตัวคูณแปลงหน่วยซื้อเป็นหน่วยฐาน — อย่างน้อย 1 เสมอ กันหารด้วยศูนย์ */
    public function purchaseFactor(): float
    {
        $factor = (float) $this->purchase_factor;

        return $factor > 0 ? $factor : 1.0;
    }

    /** แปลงจำนวนหน่วยซื้อเป็นหน่วยฐาน เช่น 2 กก. -> 2000 กรัม */
    public function toBaseQty(float $purchaseQty): float
    {
        return round($purchaseQty * $this->purchaseFactor(), 3);
    }

    /** ตั้งหน่วยซื้อไว้และต่างจากหน่วยฐานจริงหรือไม่ */
    public function hasPurchaseUnit(): bool
    {
        return filled($this->purchase_unit) && $this->purchaseFactor() != 1.0;
    }

    /** ข้อความอธิบายหน่วยซื้อ เช่น "กก. (1 = 1,000 กรัม)" */
    public function purchaseUnitLabel(): ?string
    {
        if (! $this->hasPurchaseUnit()) {
            return null;
        }

        $factor = rtrim(rtrim(number_format($this->purchaseFactor(), 4), '0'), '.');

        return $this->purchase_unit." (1 = {$factor} ".$this->unitLabel().')';
    }
}
