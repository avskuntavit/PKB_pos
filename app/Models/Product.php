<?php

namespace App\Models;

use App\Enums\DietTag;
use App\Enums\PrintGroup;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\CentralOrBranch;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * เมนูหนึ่งรายการ
 *
 * branch_id = NULL คือเมนูกลาง ทุกสาขาขายได้ / branch_id = X คือเมนูเฉพาะสาขานั้น
 *
 * ราคา เปิด-ปิดขาย ลำดับ และจุดพิมพ์ สาขาทับได้ผ่าน branch_product
 * จึง **ห้ามอ่าน $product->price ตรง ๆ ในทางขาย** ให้ใช้ priceAt() เสมอ
 * ไม่งั้นสาขาที่ตั้งราคาเองจะคิดเงินด้วยราคากลาง แล้วยอดขายเพี้ยนแบบเงียบ ๆ
 */
class Product extends Model
{
    use BelongsToBranch, CentralOrBranch, HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'is_open_price' => 'boolean',
            'staff_price' => 'decimal:2',
            'is_alcohol' => 'boolean',
            'diet_tags' => 'array',
            'unavailable_until' => 'datetime',
            'sort_order' => 'integer',
            'print_group' => PrintGroup::class,
            'is_featured' => 'boolean',
            'is_promoted' => 'boolean',
            'promo_sort' => 'integer',
        ];
    }

    /**
     * ป้ายข้อมูลอาหารที่ระบบรู้จัก
     *
     * กรองค่าที่ไม่รู้จักทิ้งตรงนี้ที่เดียว หน้าอื่นจึงไม่ต้องระวังข้อมูลเพี้ยน
     *
     * @return array<int, DietTag>
     */
    public function dietTags(): array
    {
        return DietTag::parse($this->diet_tags);
    }

    protected static function booted(): void
    {
        /*
        | branch_key เป็นเงาของ branch_id ที่แปลง NULL เป็น 0
        |
        | มีไว้อย่างเดียวคือให้ unique(branch_key, sku) ทำงานกับเมนูกลาง
        | เพราะ MySQL/SQLite ถือว่า NULL ไม่เท่ากับ NULL จึงปล่อยให้ซ้ำได้ไม่จำกัด
        | ตั้งตรงนี้ทีเดียว ไม่ปล่อยให้ controller แต่ละที่จำเอง
        */
        static::saving(function (self $product) {
            $product->branch_key = $product->branch_id ?? 0;
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** เซ็ตตัวเลือกทั้งหมดที่ผูกไว้ รวมที่ปิดอยู่ — ใช้ในหลังบ้าน */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'modifier_group_product')
            ->withPivot('sort_order', 'is_active')
            ->orderBy('modifier_group_product.sort_order');
    }

    /**
     * เซ็ตที่ใช้ได้จริงตอนนี้ — ต้องเปิดทั้งสองระดับ
     *
     *   modifier_groups.is_active         ปิดที่เซ็ต = หายจากทุกเมนู
     *   modifier_group_product.is_active  ปิดที่ pivot = หายเฉพาะเมนูนี้
     *
     * ต้องระบุชื่อตารางเสมอ เพราะสองตารางมีคอลัมน์ชื่อเดียวกัน
     */
    public function activeModifierGroups(): BelongsToMany
    {
        return $this->modifierGroups()
            ->where('modifier_groups.is_active', true)
            ->where('modifier_group_product.is_active', true);
    }

    /** สูตรทุกสาขาของเมนูนี้ — ใช้ในหลังบ้านเวลาดูภาพรวม */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** สูตรของสาขาเดียว — ทางขายต้องใช้ตัวนี้เสมอ ไม่งั้นตัดสต๊อกรวมทุกสาขา */
    public function recipeItemsAt(?int $branchId = null): HasMany
    {
        return $this->recipeItems()->where('branch_id', $branchId ?? CurrentBranch::id());
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** ค่าที่สาขาปัจจุบันทับไว้ — โหลดผ่าน withOverride() เพื่อไม่ให้เป็น query รายเมนู */
    public function override(): HasOne
    {
        return $this->hasOne(BranchProduct::class);
    }

    /* ---------- ค่าที่ใช้จริงของสาขาหนึ่ง ---------- */

    /**
     * ค่า override ของสาขานี้ — คืน null ถ้าสาขานั้นไม่ได้ทับอะไรไว้
     *
     * ถ้า relation ถูก eager load มาด้วย withOverride($branchId) แล้ว
     * จะหยิบจากที่โหลดไว้ ไม่ยิง query ใหม่
     */
    public function overrideFor(?int $branchId): ?BranchProduct
    {
        if ($branchId === null) {
            return null;
        }

        if ($this->relationLoaded('override')) {
            $loaded = $this->getRelation('override');

            return $loaded && (int) $loaded->branch_id === $branchId ? $loaded : null;
        }

        return $this->override()->where('branch_id', $branchId)->first();
    }

    /** ราคาที่สาขานี้ขายจริง */
    public function priceAt(?int $branchId = null): float
    {
        $branchId ??= CurrentBranch::id();

        // ?? ไม่ใช่ ?: — ราคา 0 คือแจกฟรี ต้องไม่ถอยไปใช้ราคากลาง
        return (float) ($this->overrideFor($branchId)?->price ?? $this->price);
    }

    /** จุดพิมพ์ที่สาขานี้ใช้ */
    public function printGroupAt(?int $branchId = null): PrintGroup
    {
        $branchId ??= CurrentBranch::id();

        return $this->overrideFor($branchId)?->print_group ?? $this->print_group;
    }

    public function sortOrderAt(?int $branchId = null): int
    {
        $branchId ??= CurrentBranch::id();

        return (int) ($this->overrideFor($branchId)?->sort_order ?? $this->sort_order);
    }

    /**
     * สาขานี้ขายเมนูนี้ได้ตอนนี้ไหม
     *
     * ต้องผ่านสามด่าน: เป็นเมนูของสาขานี้หรือเมนูกลาง, ไม่ถูกปิดทั้งที่กลางและที่สาขา,
     * และไม่ติดของหมดชั่วคราว (ซึ่งตั้งแยกกันได้ ของหมดที่สาขาหนึ่งไม่กระทบอีกสาขา)
     */
    public function isSellableAt(?int $branchId = null): bool
    {
        $branchId ??= CurrentBranch::id();

        if ($this->branch_id !== null && (int) $this->branch_id !== $branchId) {
            return false;
        }

        $override = $this->overrideFor($branchId);

        if (! $this->is_active || $override?->is_active === false) {
            return false;
        }

        foreach ([$this->unavailable_until, $override?->unavailable_until] as $until) {
            if ($until !== null && $until->isFuture()) {
                return false;
            }
        }

        return true;
    }

    /* ---------- scope ---------- */

    /** ลาก override ของสาขานี้มาด้วย — ใส่ทุกครั้งที่จะเรียก priceAt/isSellableAt กับหลายเมนู */
    public function scopeWithOverride($query, ?int $branchId)
    {
        $branchId ??= CurrentBranch::id();

        return $query->with(['override' => fn ($q) => $q->where('branch_id', $branchId)]);
    }

    /**
     * เมนูที่สาขานี้ขายได้จริงตอนนี้ — กรองในฐานข้อมูล ไม่ใช่กรองใน PHP ทีหลัง
     *
     * ต้องกรองที่นี่เพราะหน้าเมนูมี paginate และ limit ถ้ากรองทีหลัง
     * จำนวนต่อหน้าจะไม่ตรงและเมนูจะหายไปดื้อ ๆ
     */
    public function scopeSellableAt($query, ?int $branchId)
    {
        $branchId ??= CurrentBranch::id();

        return $query->forCatalog($branchId)
            ->withOverride($branchId)
            ->where('products.is_active', true)
            ->where(fn ($q) => $q->whereNull('products.unavailable_until')
                ->orWhere('products.unavailable_until', '<=', now()))
            ->whereNotExists(function ($q) use ($branchId) {
                $q->selectRaw('1')
                    ->from('branch_product')
                    ->whereColumn('branch_product.product_id', 'products.id')
                    ->where('branch_product.branch_id', $branchId)
                    ->where(fn ($w) => $w->where('branch_product.is_active', false)
                        ->orWhere('branch_product.unavailable_until', '>', now()));
            });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** ขายได้ตอนนี้ไหม — ยังไม่ถูกปิดขายชั่วคราว */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('unavailable_until')->orWhere('unavailable_until', '<=', now()));
    }

    public function isAvailable(): bool
    {
        return $this->is_active
            && ($this->unavailable_until === null || $this->unavailable_until->isPast());
    }

    /** ราคาที่พนักงานองค์กรจ่าย — ไม่ตั้งไว้ = ไม่มีสิทธิ์ลดเมนูนี้ */
    public function staffPrice(): ?float
    {
        return $this->staff_price !== null ? (float) $this->staff_price : null;
    }

    /** ต้นทุนตามสูตรของสาขาหนึ่ง (ถ้าผูกวัตถุดิบไว้) */
    public function recipeCost(?int $branchId = null): float
    {
        $branchId ??= CurrentBranch::id();

        // โหลดมาแล้วก็กรองในหน่วยความจำ ไม่ยิง query ซ้ำตอนวนหลายเมนู
        $items = $this->relationLoaded('recipeItems')
            ? $this->recipeItems->where('branch_id', $branchId)
            : $this->recipeItemsAt($branchId)->with('ingredient')->get();

        return round(
            $items->sum(
                fn (RecipeItem $r) => (float) $r->qty * (float) ($r->ingredient?->cost_per_unit ?? 0)
            ),
            2
        );
    }

    /** เมนูในตะแกรงโปรโมท เรียงตามลำดับที่ตั้งไว้ */
    public function scopePromoted($query)
    {
        return $query->where('is_promoted', true)->orderBy('promo_sort')->orderBy('name');
    }

    public function scopeSearch($query, ?string $term)
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', $term);
        });
    }
}
