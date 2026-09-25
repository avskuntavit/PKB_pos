<?php

namespace App\Models;

use App\Enums\StockTransferStatus;
use App\Models\Concerns\HasBusinessDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ใบโอนของจากสถานีหนึ่งไปอีกสถานีหนึ่ง
 *
 * ── ทำไมไม่ใช้ BelongsToBranch ──────────────────────────────
 * ใบนี้เป็นของสองสาขาพร้อมกัน ไม่ใช่ของสาขาเดียว
 * `branch_id` ตัวเดียวจะตอบไม่ได้ว่าใบนี้ควรอยู่ในรายการของใคร
 * การกรองจึงต้องเขียนเองเสมอ ด้วย scopeSentFrom / scopeSentTo / scopeVisibleTo
 */
class StockTransfer extends Model
{
    use HasBusinessDate, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => StockTransferStatus::class,
            'business_date' => 'date',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /* ---------- การกรอง ---------- */

    public function scopeSentFrom($query, int|array $branchId)
    {
        return is_array($branchId)
            ? $query->whereIn('from_branch_id', $branchId)
            : $query->where('from_branch_id', $branchId);
    }

    public function scopeSentTo($query, int|array $branchId)
    {
        return is_array($branchId)
            ? $query->whereIn('to_branch_id', $branchId)
            : $query->where('to_branch_id', $branchId);
    }

    /**
     * ใบที่คนนี้มีสิทธิ์เห็น — เป็นต้นทางหรือปลายทางก็เห็นได้
     *
     * ต้องใช้ตัวนี้เสมอแทนการ where ตรง ๆ ไม่งั้นใบโอนของคู่สาขาอื่น
     * จะโผล่มาให้คนที่ไม่เกี่ยวข้องเห็นทั้งยอดและต้นทุน
     *
     * @param  array<int, int>  $branchIds
     */
    public function scopeVisibleTo($query, array $branchIds)
    {
        return $query->where(fn ($q) => $q
            ->whereIn('from_branch_id', $branchIds)
            ->orWhereIn('to_branch_id', $branchIds));
    }

    /* ---------- ตัวเลขสรุป ---------- */

    /** มูลค่าของที่ส่งออกไป คิดจากต้นทุนต้นทาง ณ ตอนกดส่ง */
    public function sentValue(): float
    {
        return round($this->items->sum(
            fn (StockTransferItem $item) => (float) $item->qty_sent * (float) $item->unit_cost,
        ), 2);
    }

    /**
     * ของที่หายระหว่างทาง (ส่ง − รับ) นับเฉพาะใบที่รับแล้ว
     *
     * ไม่ใช่ศูนย์เมื่อไหร่ แปลว่ามีของหายจริงและควรมีคนตามหา
     */
    public function shortfallLines(): array
    {
        if ($this->status !== StockTransferStatus::Received) {
            return [];
        }

        return $this->items
            ->filter(fn (StockTransferItem $item) => $item->shortfall() > 0.0001)
            ->values()
            ->all();
    }

    public function hasShortfall(): bool
    {
        return $this->shortfallLines() !== [];
    }
}
