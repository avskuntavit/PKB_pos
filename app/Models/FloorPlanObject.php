<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * อ็อบเจ็กต์ตกแต่งและสิ่งอำนวยความสะดวกในผังร้าน (เคาน์เตอร์บาร์, จุดชำระเงิน, ครัว, ทางเข้า ฯลฯ)
 */
class FloorPlanObject extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
