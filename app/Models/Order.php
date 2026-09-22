<?php

namespace App\Models;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'type' => OrderType::class,
            'source' => OrderSource::class,
            'fulfilment_status' => FulfilmentStatus::class,
            'payment_intent' => \App\Enums\PaymentIntent::class,
            'pickup_at' => 'datetime',
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'business_date' => 'date',
            'guest_count' => 'integer',
            'subtotal' => 'decimal:2',
            'item_discount' => 'decimal:2',
            'bill_discount' => 'decimal:2',
            'promotion_discount' => 'decimal:2',
            'voucher_discount' => 'decimal:2',
            'staff_discount' => 'decimal:2',
            'queue_number' => 'integer',
            'queue_called_at' => 'datetime',
            'queue_called_count' => 'integer',
            'queue_skipped_at' => 'datetime',
            'service_charge' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'rounding' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->uuid ??= (string) Str::uuid();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** เฉพาะรายการที่ยังไม่ถูกยกเลิก */
    public function activeItems(): HasMany
    {
        return $this->hasMany(OrderItem::class)->where('status', '!=', 'void');
    }

    /** รายการที่ลูกค้าสั่งเองและยังรอพนักงานกดยืนยัน */
    public function pendingApprovalItems(): HasMany
    {
        return $this->hasMany(OrderItem::class)
            ->where('approval_status', 'pending')
            ->where('status', '!=', 'void');
    }

    public function kitchenTickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(OrderReview::class);
    }

    public function staffBenefitUsage(): HasOne
    {
        return $this->hasOne(StaffBenefitUsage::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class)->orderBy('created_at');
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    public function tableSession(): HasOne
    {
        return $this->hasOne(TableSession::class);
    }

    public function serviceCalls(): HasMany
    {
        return $this->hasMany(ServiceCall::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function orderPromotions(): HasMany
    {
        return $this->hasMany(OrderPromotion::class);
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /* ---------- Scopes ---------- */

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Paid);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Open);
    }

    /**
     * ออเดอร์ล่วงหน้าที่ยังไม่ส่งมอบ — ใช้ทำคิวฝั่งพนักงาน
     *
     * นับทุกใบที่มี fulfilment_status ไม่ว่าลูกค้าสั่งเองหรือพนักงานสั่งแทน
     * เพราะสุดท้ายก็ต้องมีคนยกของให้ลูกค้าเหมือนกัน
     */
    public function scopeAwaitingFulfilment(Builder $query): Builder
    {
        return $query->whereNotNull('fulfilment_status')
            ->whereIn('fulfilment_status', [
                FulfilmentStatus::Placed->value,
                FulfilmentStatus::Accepted->value,
                FulfilmentStatus::Preparing->value,
                FulfilmentStatus::Ready->value,
            ]);
    }

    /** ช่วงวันขาย — ใช้ business_date ไม่ใช่ created_at เพราะร้านอาจขายข้ามเที่ยงคืน */
    public function scopeBusinessDateBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('business_date', [$from, $to]);
    }

    /* ---------- Helpers ---------- */

    public function isOpen(): bool
    {
        return $this->status === OrderStatus::Open;
    }

    /** เป็นออเดอร์ที่สั่งล่วงหน้ามาจากหน้าร้านออนไลน์ไหม */
    public function isOnline(): bool
    {
        return $this->source === OrderSource::Online;
    }

    public function trackUrl(): ?string
    {
        return $this->track_token ? url('/order/track/'.$this->track_token) : null;
    }

    public function outstanding(): float
    {
        return round((float) $this->grand_total - (float) $this->paid_amount, 2);
    }
}
