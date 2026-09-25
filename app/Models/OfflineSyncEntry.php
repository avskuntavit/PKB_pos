<?php

namespace App\Models;

use App\Enums\OfflineEntryKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * บันทึกหนึ่งรายการที่ถูกคีย์ตอนเน็ตหลุด แล้วส่งขึ้นระบบทีหลัง
 *
 * ไม่ใช้ BelongsToBranch เพราะตัวนี้เป็นบันทึกเหตุการณ์ ไม่ใช่ข้อมูลดำเนินงาน
 * สาขาถูกบันทึกไว้ตอนสร้างจากสาขาที่พนักงานทำงานอยู่ และไม่ต้องกรองอัตโนมัติที่ไหน
 */
class OfflineSyncEntry extends Model
{
    public const STATUS_APPLIED = 'applied';

    /** เคยส่งสำเร็จไปแล้ว รอบนี้จึงไม่ทำอะไรซ้ำ */
    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_FAILED = 'failed';

    /**
     * รับเงินจากลูกค้ามาแล้วจริง แต่ลงบิลไม่ได้
     *
     * ต่างจาก failed ตรงที่ failed คือ "ไม่มีอะไรเกิดขึ้น คีย์ใหม่ได้"
     * ส่วน held คือ "เงินอยู่ในลิ้นชักแล้ว ต้องมีคนตัดสินว่าจะทำอะไรกับมัน"
     */
    public const STATUS_HELD = 'held';

    /** ผู้จัดการเปิดบิลใหม่แล้วเก็บเงินตามปกติ — เงินก้อนนี้เข้าระบบแล้วทางอื่น */
    public const RESOLUTION_BOOKED = 'booked';

    /** คืนเงินให้ลูกค้าไปแล้ว */
    public const RESOLUTION_REFUNDED = 'refunded';

    /** บันทึกเป็นเงินสดรับเกินในลิ้นชัก — ยังหาเจ้าของไม่ได้ */
    public const RESOLUTION_OVERAGE = 'overage';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => OfflineEntryKind::class,
            'payload' => 'array',
            'amount' => 'decimal:2',
            'client_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** เงินที่ยังรอผู้จัดการตัดสิน */
    public function scopeOpenHolds(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HELD)->whereNull('resolved_at');
    }

    /** @return array<string, string> */
    public static function resolutionLabels(): array
    {
        return [
            self::RESOLUTION_BOOKED => 'เปิดบิลใหม่แล้วเก็บเงินตามปกติ',
            self::RESOLUTION_REFUNDED => 'คืนเงินให้ลูกค้าไปแล้ว',
            self::RESOLUTION_OVERAGE => 'บันทึกเป็นเงินสดรับเกินในลิ้นชัก',
        ];
    }
}
