<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'business_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    /** ป้ายชื่อภาษาไทยของแต่ละ action — ใช้ในรายงานพนักงาน */
    public static function actionLabels(): array
    {
        return [
            'order.open' => 'เปิดบิล',
            'order.reopen' => 'เปิดบิลอีกครั้ง',
            'order.item_sent' => 'ส่งรายการ',
            'order.item_void' => 'ยกเลิกรายการ',
            'order.pay' => 'จ่ายเงิน',
            'order.void' => 'ทำลายบิล',
            'order.refund' => 'คืนเงิน',
            'promotion.update' => 'แก้ไขโปรโมชั่น',
            'table.update' => 'แก้ไขรูปแบบโต๊ะ',
            'menu.update' => 'แก้ไขเทมเพลตเมนู',
            'category.update' => 'แก้ไขหมวดหมู่',
            'modifier.group' => 'แก้ไขเซ็ตตัวเลือก',
            'modifier.item' => 'แก้ไขตัวเลือก',
            'product.update' => 'แก้ไขเมนู',
            'product.modifiers' => 'ผูกเซ็ตตัวเลือกกับเมนู',
            'promo.update' => 'แก้ไขกลุ่มโปรโมท',
            'target.update' => 'ตั้งเป้ายอดขาย',
            'shift.open' => 'เปิดรอบการขาย',
            'shift.close' => 'ปิดรอบการขาย',
            'period.close' => 'ปิดงวดบัญชี',
            'period.reopen' => 'เปิดงวดบัญชีกลับมา',
        ];
    }
}
