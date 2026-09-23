<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * งวดบัญชีของสถานีหนึ่ง
 *
 * มีแถว + status = closed  -> ล็อก แก้บิลในเดือนนั้นไม่ได้
 * มีแถว + status = reopened -> เคยปิดแล้วเปิดกลับ ตอนนี้แก้ได้ (แต่มีประวัติค้างไว้)
 * ไม่มีแถว                  -> ยังไม่เคยปิด
 */
class AccountingPeriod extends Model
{
    use BelongsToBranch;

    public const CLOSED = 'closed';

    public const REOPENED = 'reopened';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'times_reopened' => 'integer',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', self::CLOSED);
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    /** เคยถูกเปิดกลับมาอย่างน้อยหนึ่งครั้ง — ผู้ตรวจบัญชีอยากเห็นตรงนี้ */
    public function wasReopened(): bool
    {
        return $this->times_reopened > 0;
    }
}
