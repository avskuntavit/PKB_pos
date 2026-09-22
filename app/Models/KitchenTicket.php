<?php

namespace App\Models;

use App\Enums\Course;
use App\Enums\KitchenTicketStatus;
use App\Enums\OrderSource;
use App\Enums\PrintGroup;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitchenTicket extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => KitchenTicketStatus::class,
            'source' => OrderSource::class,
            'print_group' => PrintGroup::class,
            'course' => Course::class,
            'round' => 'integer',
            'business_date' => 'date',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(KitchenTicketItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ใบที่ยังค้างบนหน้าจอครัว */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            KitchenTicketStatus::Queued->value,
            KitchenTicketStatus::Preparing->value,
            KitchenTicketStatus::Ready->value,
        ]);
    }

    /** รอมากี่นาทีแล้ว — ใช้ไฮไลต์ใบที่ค้างนาน */
    public function waitingMinutes(): int
    {
        $until = $this->served_at ?? $this->ready_at ?? now();

        return (int) $this->queued_at->diffInMinutes($until);
    }
}
