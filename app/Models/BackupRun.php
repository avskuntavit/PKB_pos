<?php

namespace App\Models;

use App\Enums\BackupKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ผลของการสำรองข้อมูลหนึ่งครั้ง
 *
 * ไม่ใช้ BelongsToBranch — การสำรองเป็นเรื่องของทั้งระบบ ไม่ใช่ของสาขาใดสาขาหนึ่ง
 */
class BackupRun extends Model
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    /** ข้ามเพราะไม่มีอะไรให้สำรอง เช่นยังไม่เคยส่งไฟล์ให้บัญชีเลยสักครั้ง — ไม่ใช่ความผิดพลาด */
    public const SKIPPED = 'skipped';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => BackupKind::class,
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'verified_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function sizeLabel(): string
    {
        $bytes = (int) $this->size_bytes;

        if ($bytes <= 0) {
            return '—';
        }

        foreach ([['GB', 1073741824], ['MB', 1048576], ['KB', 1024]] as [$unit, $step]) {
            if ($bytes >= $step) {
                return round($bytes / $step, $bytes / $step >= 10 ? 0 : 1).' '.$unit;
            }
        }

        return $bytes.' B';
    }

    public function durationLabel(): string
    {
        $ms = (int) $this->duration_ms;

        if ($ms <= 0) {
            return '—';
        }

        return $ms < 1000
            ? $ms.' ms'
            : round($ms / 1000, $ms < 10000 ? 1 : 0).' วินาที';
    }
}
