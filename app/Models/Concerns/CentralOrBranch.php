<?php

namespace App\Models\Concerns;

use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;

/**
 * ข้อมูลที่เป็นได้ทั้ง "ของกลาง" และ "ของเฉพาะสาขา"
 *
 * branch_id = NULL คือของกลาง ทุกสาขาเห็น
 * branch_id = X    คือของเฉพาะสาขานั้น สาขาอื่นไม่เห็น
 *
 * ใช้กับ products / categories / modifier_groups ซึ่งต้องเป็นกลางพร้อมกันทั้งชุด —
 * ถ้าเมนูกลางแต่หมวดยังผูกสาขา category_id ของเมนูกลางจะชี้หมวดของสาขาใดสาขาหนึ่ง
 * แล้วสาขาอื่นจะเห็นเมนูลอยไม่มีหมวด
 */
trait CentralOrBranch
{
    /** ของกลาง + ของเฉพาะสาขานี้ — ไม่กรองเปิด/ปิด */
    public function scopeForCatalog(Builder $query, ?int $branchId = null): Builder
    {
        $branchId ??= CurrentBranch::id();
        $column = $this->qualifyColumn('branch_id');

        // ไม่รู้ว่าสาขาไหน ให้เห็นเฉพาะของกลาง ปลอดภัยกว่าเห็นของทุกสาขาปนกัน
        if ($branchId === null) {
            return $query->whereNull($column);
        }

        return $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, $branchId));
    }

    /** เป็นของกลางไหม */
    public function isCentral(): bool
    {
        return $this->branch_id === null;
    }
}
