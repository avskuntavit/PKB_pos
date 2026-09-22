<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\CentralOrBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * เซ็ตตัวเลือก — ชุดตัวเลือกที่เอาไปแปะเมนูไหนก็ได้
 *
 * name         ชื่อภายใน  "ก๋วยเตี๋ยว - เพิ่มเติม"
 * display_name ชื่อหน้าบ้าน "เพิ่มเติม" (ว่าง = ใช้ name)
 *
 * branch_id = NULL คือเซ็ตกลาง — เมนูกลางต้องผูกเซ็ตกลางเท่านั้น
 * ไม่งั้นสาขาอื่นจะเปิดหน้าต่างสั่งแล้วไม่เห็นตัวเลือก
 */
class ModifierGroup extends Model
{
    use BelongsToBranch, CentralOrBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'modifier_group_product')
            ->withPivot('sort_order', 'is_active');
    }

    /** ชื่อที่ลูกค้าเห็นตอนสั่ง — ไม่ได้ตั้งไว้ก็ใช้ชื่อภายใน */
    public function displayName(): string
    {
        return filled($this->display_name) ? $this->display_name : $this->name;
    }

    /**
     * ระวัง: ตาราง pivot ก็มีคอลัมน์ is_active เหมือนกัน
     * ถ้าเขียน where('is_active', ...) เฉย ๆ จะ ambiguous ตอน join
     */
    public function scopeActive($query)
    {
        return $query->where('modifier_groups.is_active', true);
    }
}
