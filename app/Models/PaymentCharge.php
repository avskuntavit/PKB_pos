<?php

namespace App\Models;

use App\Enums\ChargeStatus;
use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * QR รับเงินหนึ่งใบที่ออกไปแล้ว
 *
 * ไม่ใช้ BelongsToBranch เพราะตัวไล่ถามทำงานข้ามสาขาในรอบเดียว
 * (ไม่มีใครล็อกอินอยู่ตอน schedule:work รัน จึงไม่มี "สาขาที่กำลังทำงานอยู่")
 * ทุกคิวรีที่มีคนดูจึงต้องกรองสาขาเองให้ชัด
 */
class PaymentCharge extends Model
{
    /**
     * คำนำหน้าที่ `PaymentChargeService::poll()` เขียนไว้เมื่อ **ติดต่อเกตเวย์ไม่ได้**
     *
     * เป็นค่าคงที่เพราะมีสองฝ่ายต้องเห็นตรงกัน: ฝ่ายที่เขียน (poll) กับฝ่ายที่อ่าน
     * (`lastPollFailed()` ซึ่งหน้าจอใช้ตัดสินว่าจะขึ้นป้าย "ตรวจไม่ได้ชั่วคราว")
     * ถ้าปล่อยให้แต่ละฝ่ายถือสตริงของตัวเอง วันที่ใครแก้ข้อความ ป้ายจะหายไปเงียบ ๆ
     */
    public const UNREACHABLE_PREFIX = 'ถามเกตเวย์ไม่ได้: ';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => ChargeStatus::class,
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'raw' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'poll_attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $charge) {
            // อ้างอิงที่ส่งให้เกตเวย์เป็น merchant reference — ต้องมีตั้งแต่ก่อนคุยกับเขา
            $charge->uuid ??= (string) Str::uuid();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** แถวใน payments ที่เกิดจากรายการนี้ — มีค่า = ลงบิลไปแล้ว */
    public function settledPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'settled_payment_id');
    }

    /** รายการที่ตัวไล่ถามต้องไปถามต่อ */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ChargeStatus::Pending->value);
    }

    /** เงินที่เข้ามาแล้วแต่ยังไม่มีบิลรองรับ — รอผู้จัดการตัดสิน */
    public function scopeNeedsDecision(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ChargeStatus::Mismatch->value,
            ChargeStatus::Unmatched->value,
        ]);
    }

    public function isSettled(): bool
    {
        return $this->settled_payment_id !== null;
    }

    /**
     * รอบถามล่าสุดติดต่อเกตเวย์ไม่ได้
     *
     * ── ทำไมต้องรู้เรื่องนี้แยกจากสถานะ ────────────────────────────────
     * "ถามไม่ได้" กับ "ถามแล้วเขาบอกว่ายังไม่จ่าย" ให้สถานะเดียวกัน (pending)
     * แต่คนละความหมายสิ้นเชิงสำหรับพนักงานที่ยืนอยู่หน้าเคาน์เตอร์
     *
     * `poll()` จงใจ **ไม่โยน exception** ออกมา เพราะตัวไล่ถามต้องทำงานต่อได้
     * ทั้งรอบ มันเก็บเหตุผลไว้ใน failure_message แทน — ตัวนี้จึงเป็นทางเดียว
     * ที่หน้าจอจะรู้ได้ว่ารอบล่าสุดไปไม่ถึงเกตเวย์
     *
     * รอบที่ถามสำเร็จจะเขียน failure_message ทับด้วยข้อความของเกตเวย์
     * (หรือ null) ป้ายจึงหายไปเองเมื่อติดต่อได้อีกครั้ง ไม่ค้างอยู่
     */
    public function lastPollFailed(): bool
    {
        return $this->status->isOpen()
            && $this->failure_message !== null
            && str_starts_with($this->failure_message, self::UNREACHABLE_PREFIX);
    }

    /** หมดอายุแล้วตามเวลาของเกตเวย์ (ของ static ไม่มีวันหมดอายุ) */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
