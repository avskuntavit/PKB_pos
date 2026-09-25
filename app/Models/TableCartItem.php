<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หนึ่งบรรทัดในตะกร้าร่วมของโต๊ะ
 *
 * ไม่ใช้ BelongsToBranch — ตะกร้าผูกกับรอบการนั่งโต๊ะ ซึ่งผูกสาขาอยู่แล้ว
 * และทุกคิวรีของตะกร้าต้องผ่าน table_session เสมอ ไม่มีเส้นทางไหนที่จะหลุดข้ามสาขาได้
 */
class TableCartItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'modifier_ids' => 'array',
        ];
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * กุญแจยุบรายการซ้ำ
     *
     * เรียง modifier ก่อนเสมอ ไม่งั้น [2,5] กับ [5,2] จะกลายเป็นคนละบรรทัด
     * ทั้งที่ลูกค้าสั่งของอย่างเดียวกัน แล้วตะกร้าจะดูรกโดยไม่มีเหตุผล
     *
     * @param  array<int, int>  $modifierIds
     */
    public static function keyFor(int $productId, array $modifierIds, ?string $note): string
    {
        $ids = array_values(array_unique(array_map('intval', $modifierIds)));
        sort($ids);

        $note = trim((string) $note);

        // ตัดให้พอดีคอลัมน์ตั้งแต่ตรงนี้ หมายเหตุยาว ๆ จะได้ไม่ทำให้ insert ล้ม
        return mb_substr($productId.'|'.implode(',', $ids).'|'.$note, 0, 120);
    }

    /** ยอดของบรรทัดนี้ — ใช้โชว์ในตะกร้าเท่านั้น ราคาจริงคิดใหม่ตอนกดส่งครัว */
    public function lineTotal(): float
    {
        return round((float) $this->unit_price * (float) $this->qty, 2);
    }
}
