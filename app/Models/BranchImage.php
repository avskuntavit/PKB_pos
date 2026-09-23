<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * รูปบรรยากาศร้านหนึ่งใบของสถานีหนึ่ง
 *
 * `path` เก็บเป็น path ไม่ใช่ URL เต็ม (เช่น /storage/branches/xxx.jpg)
 * ตามหลักเดียวกับ ImageService — ถ้าเก็บ URL เต็ม พอเปลี่ยนโดเมนหรือสลับ
 * localhost กับ IP ในวงแลน รูปจะพังทั้งชุด
 */
class BranchImage extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
