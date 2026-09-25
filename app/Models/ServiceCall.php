<?php

namespace App\Models;

use App\Enums\ServiceCallType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCall extends Model
{
    use BelongsToBranch, HasBusinessDate, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => ServiceCallType::class,
            'business_date' => 'date',
            'acknowledged_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'acknowledged']);
    }

    /**
     * คำขอเปิดโต๊ะที่ยังค้างอยู่ของโต๊ะนี้
     *
     * ใช้ทั้งตอนกันลูกค้ากดซ้ำ และตอนปิดคำขอให้เองเมื่อพนักงานเปิดโต๊ะแล้ว
     * ผูกกับโต๊ะไม่ใช่บิล เพราะตอนขอยังไม่มีบิลให้ผูก
     */
    public function scopeOpenRequestFor(Builder $query, int $tableId): Builder
    {
        return $query->where('dining_table_id', $tableId)
            ->where('type', ServiceCallType::OpenTable->value)
            ->pending();
    }
}
