<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * เป้ายอดขายรายเดือนของสาขา
 *
 * เก็บแค่ตัวเลขเดือน ส่วนการกระจายเป็นรายวันเป็นหน้าที่ของ SalesTargetService
 * เพื่อให้เปลี่ยนวิธีเกลี่ยได้ทีหลังโดยไม่ต้องแก้ข้อมูลที่กรอกไปแล้ว
 */
class SalesTarget extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'target_amount' => 'decimal:2',
            'food_cost_percent' => 'decimal:2',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** ตั้งเป้าไว้จริงหรือแค่มีแถวเปล่า */
    public function isSet(): bool
    {
        return (float) $this->target_amount > 0;
    }
}
