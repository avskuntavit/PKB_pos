<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หนึ่งบรรทัดในใบโอน — ของหนึ่งชิ้น ส่งเท่าไร รับได้เท่าไร
 *
 * qty_received เป็น null จนกว่าปลายทางจะกดรับ
 * null = ยังไม่ได้รับ / 0 = รับแล้วแต่ไม่ได้ของเลย — คนละเรื่องกัน ห้ามรวมกัน
 */
class StockTransferItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty_sent' => 'decimal:3',
            'qty_received' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /** ของที่หายระหว่างทาง — 0 ถ้ายังไม่ได้รับ หรือรับครบแล้ว */
    public function shortfall(): float
    {
        if ($this->qty_received === null) {
            return 0.0;
        }

        return round(max(0, (float) $this->qty_sent - (float) $this->qty_received), 3);
    }
}
