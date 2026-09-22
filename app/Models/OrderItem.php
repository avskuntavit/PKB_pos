<?php

namespace App\Models;

use App\Enums\Course;
use App\Enums\OrderSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'qty' => 'decimal:3',
            'modifier_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'source' => OrderSource::class,
            'course' => Course::class,
            'round' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** พร้อมส่งครัวไหม — พนักงานสั่งเองส่งได้เลย ลูกค้าสั่งต้องผ่านการยืนยันก่อน */
    public function isReadyForKitchen(): bool
    {
        return $this->status === 'pending'
            && $this->approval_status !== 'pending'
            && $this->approval_status !== 'rejected';
    }

    /** ข้อความตัวเลือกแบบบรรทัดเดียว สำหรับใบสั่งครัว */
    public function modifiersText(): ?string
    {
        $names = $this->modifiers->pluck('name')->filter()->all();

        return $names ? implode(', ', $names) : null;
    }

    /** ราคาต่อหน่วยรวมตัวเลือกแล้ว */
    public function effectiveUnitPrice(): float
    {
        $qty = (float) $this->qty ?: 1;

        return round((float) $this->unit_price + ((float) $this->modifier_total / $qty), 2);
    }

    public function recalculate(): static
    {
        $this->line_total = round(
            ((float) $this->unit_price * (float) $this->qty)
            + (float) $this->modifier_total
            - (float) $this->discount,
            2
        );

        return $this;
    }
}
