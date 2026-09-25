<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBusinessDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReview extends Model
{
    use BelongsToBranch, HasBusinessDate, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'tags' => 'array',
            'business_date' => 'date',
            'replied_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    /** แท็กสำเร็จรูปให้ลูกค้าเลือก จะได้ไม่ต้องพิมพ์เอง */
    public static function positiveTags(): array
    {
        return ['อาหารอร่อย', 'เสิร์ฟเร็ว', 'พนักงานบริการดี', 'ร้านสะอาด', 'คุ้มราคา'];
    }

    public static function negativeTags(): array
    {
        return ['รอนาน', 'รสชาติไม่ถูกปาก', 'อาหารไม่ร้อน', 'สั่งผิดรายการ', 'ร้านไม่สะอาด'];
    }
}
