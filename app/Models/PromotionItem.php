<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * เมนูหรือหมวดหนึ่งแถวที่ผูกกับโปรโมชั่น
 *
 * หนึ่งแถวมีได้อย่างเดียว — product_id หรือ category_id
 * ผูกหมวดหมายถึง "ทุกเมนูในหมวดนี้" รวมเมนูที่เพิ่มเข้ามาทีหลังด้วย
 * ซึ่งต่างจากผูกรายเมนูตรงที่ไม่ต้องกลับมาแก้โปรทุกครั้งที่ออกเมนูใหม่
 */
class PromotionItem extends Model
{
    public const ROLE_TRIGGER = 'trigger';

    public const ROLE_REWARD = 'reward';

    public $timestamps = false;

    protected $guarded = [];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
