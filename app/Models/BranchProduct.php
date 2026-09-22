<?php

namespace App\Models;

use App\Enums\PrintGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ค่าที่สาขาหนึ่งปรับต่างจากเมนูกลาง
 *
 * ทุกคอลัมน์เป็น null ได้ และ null แปลว่า "ข้อนี้ใช้ค่ากลาง"
 * แยก null กับ 0 ให้ออกเสมอ — ราคา 0 คือแจกฟรี ไม่ใช่ไม่ได้ตั้งราคา
 * โค้ดที่เขียนว่า `$override->price ?: $product->price` จึงผิด ต้องใช้ `??`
 *
 * ไม่มีแถวสำหรับสาขาหนึ่ง = สาขานั้นใช้ค่ากลางทั้งหมด ซึ่งเป็นกรณีปกติ
 * จึงไม่ต้องสร้างแถวล่วงหน้าให้ทุกคู่ (สาขา × เมนู)
 */
class BranchProduct extends Model
{
    protected $table = 'branch_product';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'unavailable_until' => 'datetime',
            'sort_order' => 'integer',
            'print_group' => PrintGroup::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** ไม่ได้ทับอะไรไว้เลย — เก็บแถวไว้ก็เปลือง ลบทิ้งได้ */
    public function isEmpty(): bool
    {
        return $this->price === null
            && $this->is_active === null
            && $this->unavailable_until === null
            && $this->sort_order === null
            && $this->print_group === null;
    }
}
