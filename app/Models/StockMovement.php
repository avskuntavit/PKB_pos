<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ความเคลื่อนไหวสต๊อกหนึ่งรายการ
 *
 * branch_id สำคัญกว่าเดิมมาก — เมื่อก่อนอ่านจากตัววัตถุดิบได้เพราะวัตถุดิบผูกสาขา
 * ตอนนี้ของในคลังเป็นของกลาง สาขาจึงต้องระบุมาตรง ๆ เสมอ
 */
class StockMovement extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'qty' => 'decimal:3',
            'cost' => 'decimal:2',
            'balance_after' => 'decimal:3',
            'business_date' => 'date',
            'occurred_at' => 'datetime',
        ];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
