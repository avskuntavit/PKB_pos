<?php

namespace App\Models;

use App\Enums\ExportStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesExport extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'status' => ExportStatus::class,
            'grand_total' => 'decimal:2',
            'bill_count' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ยังไม่จบเรื่อง — ต้องส่งใหม่ */
    public function scopeUnsent(Builder $query): Builder
    {
        return $query->where('status', '!=', ExportStatus::Sent->value);
    }

    /** ไฟล์ที่ driver แบบไฟล์เขียนไว้ — คืน array ว่างถ้าเป็น driver อื่น */
    public function files(): array
    {
        if ($this->driver !== 'file' || blank($this->reference)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $this->reference))));
    }
}
