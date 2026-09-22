<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * งานพิมพ์หนึ่งชิ้นในคิว
 *
 * payload คือไบต์ที่พร้อมส่งอยู่แล้ว ไม่ใช่ข้อมูลดิบที่ต้องเอาไปสร้างใหม่
 * ทำแบบนี้เพราะใบสั่งครัวต้องเป็นภาพ ณ ตอนกดส่ง ถ้าเครื่องพิมพ์ดับไปสิบนาที
 * แล้วกลับมา กระดาษต้องตรงกับที่สั่งตอนนั้น ไม่ใช่สถานะล่าสุดของบิล
 */
class PrintJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PRINTING = 'printing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** รอคิวอยู่และถึงเวลาลองแล้ว */
    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }

    /** ยอมแพ้แล้ว — รอคนกดสั่งพิมพ์ซ้ำเอง */
    public function isExhausted(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }
}
