<?php

namespace App\Models;

use App\Enums\StockUnit;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\CentralOrBranch;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ของในคลัง — แม่แบบกลาง
 *
 * เก็บได้ทุกอย่างที่ร้านใช้ ไม่ใช่แค่ของกิน ถุงพลาสติก หลอด กล่อง ก็อยู่ในนี้
 * (เดิมชื่อ Ingredient ซึ่งแคบเกินกว่าที่ตารางนี้เก็บจริง)
 *
 * ── สิ่งที่ไม่ได้อยู่ในคลาสนี้ ──────────────────────────────
 * ยอดคงเหลือ ต้นทุน และจุดสั่งซื้อ **เป็นของรายสาขา** อยู่ใน BranchStockItem
 * เรียกผ่าน stockAt() / qtyAt() / costAt() เสมอ อย่าเผลอหาบนตัวนี้
 *
 * ── หน่วย ─────────────────────────────────────────────────
 * ยอดคงเหลือและสูตรอยู่ใน "หน่วยฐาน" (unit) เสมอ
 * ส่วน purchase_unit ใช้เฉพาะตอนรับของเข้า เพื่อไม่ต้องคิดเลขเอง
 */
class StockItem extends Model
{
    use BelongsToBranch, CentralOrBranch, HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit' => StockUnit::class,
            'purchase_factor' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        /*
        | branch_key เป็นเงาของ branch_id ที่แปลง NULL เป็น 0
        |
        | มีไว้อย่างเดียวคือให้ unique(branch_key, code) ทำงานกับของกลาง
        | เพราะ SQL ถือว่า NULL ไม่เท่ากับ NULL จึงกันรหัสซ้ำของของกลางไม่ได้
        | วิธีเดียวกับ Product
        */
        static::saving(function (self $item) {
            $item->branch_key = $item->branch_id ?? 0;
        });
    }

    /* ---------- ความสัมพันธ์ ---------- */

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** ตัวเลือกที่ใช้ของชิ้นนี้ เช่น "เพิ่มเนื้อ" ใช้หมูหมัก */
    public function modifierRecipeItems(): HasMany
    {
        return $this->hasMany(ModifierRecipeItem::class);
    }

    /** ยอดคงเหลือของทุกสาขา */
    public function branchStock(): HasMany
    {
        return $this->hasMany(BranchStockItem::class);
    }

    /* ---------- ยอดของสาขา ---------- */

    /**
     * แถวสต๊อกของสาขาหนึ่ง — สร้างในหน่วยความจำให้ถ้ายังไม่มี แต่ยังไม่บันทึก
     *
     * คืนแถวเปล่าแทน null เพราะฝั่งที่เรียกใช้ต้องการตัวเลขไปคำนวณต่อเสมอ
     * ถ้าคืน null ทุกที่ที่เรียกจะต้องเขียน ?? 0 เอง แล้วสักที่จะลืม
     *
     * ตัวที่จะบันทึกจริงคือ StockService::move() ซึ่งล็อกแถวก่อนแก้
     */
    public function stockAt(?int $branchId = null): BranchStockItem
    {
        $branchId ??= CurrentBranch::id();

        $loaded = $this->relationLoaded('branchStock')
            ? $this->branchStock->firstWhere('branch_id', $branchId)
            : null;

        return $loaded ?? BranchStockItem::firstOrNew([
            'branch_id' => $branchId,
            'stock_item_id' => $this->id,
        ]);
    }

    public function qtyAt(?int $branchId = null): float
    {
        return (float) $this->stockAt($branchId)->stock_qty;
    }

    public function costAt(?int $branchId = null): float
    {
        return (float) $this->stockAt($branchId)->cost_per_unit;
    }

    public function reorderLevelAt(?int $branchId = null): float
    {
        return (float) $this->stockAt($branchId)->reorder_level;
    }

    /** ของชิ้นนี้สาขานี้เปิดใช้อยู่ไหม — ต้องเปิดทั้งของกลางและของสาขา */
    public function isUsableAt(?int $branchId = null): bool
    {
        return $this->is_active && (bool) $this->stockAt($branchId)->is_active;
    }

    public function isBelowReorderLevelAt(?int $branchId = null): bool
    {
        $stock = $this->stockAt($branchId);

        return (float) $stock->stock_qty <= (float) $stock->reorder_level;
    }

    public function stockValueAt(?int $branchId = null): float
    {
        $stock = $this->stockAt($branchId);

        return round((float) $stock->stock_qty * (float) $stock->cost_per_unit, 2);
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
