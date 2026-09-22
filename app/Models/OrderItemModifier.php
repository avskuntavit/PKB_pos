<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemModifier extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * ตัวเลือกต้นทางในแคตตาล็อก
     *
     * null ได้ เพราะร้านลบตัวเลือกทิ้งทีหลังได้ บิลเก่ายังอ่านออกอยู่ดี
     * เพราะชื่อกับราคาถูก snapshot ลงแถวนี้ไว้แล้วตั้งแต่ตอนสั่ง
     *
     * ที่ต้องมีความสัมพันธ์นี้เพราะตอนปิดบิล StockService ต้องย้อนไปอ่าน
     * สูตรวัตถุดิบและตัวคูณขนาดจานของตัวเลือก ซึ่งไม่ได้ snapshot ไว้
     */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }
}
