<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * บั๊กหนึ่งตัว (ไม่ใช่การเกิดหนึ่งครั้ง)
 *
 * error ตัวเดียวกันที่เกิดซ้ำจะรวมอยู่ในแถวนี้แถวเดียว แล้วนับที่ occurrences
 * ดูคำอธิบายเรื่อง fingerprint ได้ที่ migration และ ErrorMonitor
 */
class ErrorEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** ชื่อคลาสสั้น ๆ พอให้อ่านออกว่าเป็น error ชนิดไหน */
    public function shortClass(): string
    {
        $parts = explode('\\', (string) $this->exception_class);

        return end($parts) ?: (string) $this->exception_class;
    }

    /** ตำแหน่งในโค้ดแบบย่อ — ตัด path ของโปรเจกต์ออกให้เหลือที่อ่านรู้เรื่อง */
    public function location(): ?string
    {
        if (! $this->file) {
            return null;
        }

        $file = str_replace('\\', '/', $this->file);
        $base = str_replace('\\', '/', base_path()).'/';

        if (str_starts_with($file, $base)) {
            $file = substr($file, strlen($base));
        }

        return $file.($this->line ? ':'.$this->line : '');
    }
}
