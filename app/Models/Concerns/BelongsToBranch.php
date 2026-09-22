<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ใส่ใน model ที่ผูกกับสาขา — auto-scope ตามสาขาปัจจุบัน
 * และเติม branch_id ให้อัตโนมัติตอนสร้าง
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::creating(function ($model) {
            /*
            | เติมสาขาให้เฉพาะตอนที่ "ไม่ได้ระบุมาเลย"
            |
            | เทียบด้วย array_key_exists ไม่ใช่ empty() เพราะต้องแยกสองกรณีนี้ออกจากกัน:
            |   ไม่ได้ส่ง branch_id มา        -> เติมสาขาปัจจุบันให้ (พฤติกรรมเดิม)
            |   ส่ง branch_id => null มา      -> ตั้งใจให้เป็นของกลาง ห้ามเติมทับ
            |
            | ถ้าใช้ empty() เมนูกลางจะถูกยัดสาขาปัจจุบันให้ทุกครั้งที่สร้าง
            | แล้วกลายเป็นเมนูเฉพาะสาขาโดยไม่มีใครรู้
            */
            if (! array_key_exists('branch_id', $model->getAttributes()) && ($id = CurrentBranch::id())) {
                $model->branch_id = $id;
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** จำกัดเฉพาะสาขาที่ระบุ (ใช้ชัดเจนดีกว่า global scope เวลาทำรายงานหลายสาขา) */
    public function scopeForBranch(Builder $query, int|array|null $branchId): Builder
    {
        if (blank($branchId)) {
            return $query;
        }

        return is_array($branchId)
            ? $query->whereIn($this->qualifyColumn('branch_id'), $branchId)
            : $query->where($this->qualifyColumn('branch_id'), $branchId);
    }
}
