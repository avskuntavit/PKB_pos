<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;

/**
 * ตัดสินว่า request นี้ทำงานกับสาขาไหน
 * ลำดับ: ?branch_id ใน query -> ค่าใน session -> สาขาของ user -> สาขาแรกที่เข้าได้
 *
 * ── ทำไมสองแหล่งนี้ถูกปฏิบัติต่างกัน ─────────────────────────
 * `?branch_id` คือ "เจตนา" ของคนใช้ ณ ตอนนั้น ขอสาขาที่ไม่มีสิทธิ์ = 403 เสมอ
 * ถ้าเงียบ ๆ พาไปสาขาตัวเองแทน ลิงก์ข้ามสาขาจะดูเหมือนใช้ได้และไม่มีใครรู้ว่ากันอยู่
 *
 * ส่วน session กับ `users.branch_id` เป็นค่าที่ "ตกยุคได้เอง" — สาขานั้นอาจถูกปิดใช้งาน
 * ไปหลังจากที่ค่าถูกเก็บ กรณีนี้ต้องถอยไปสาขาที่เข้าได้ ไม่ใช่ 403
 * ไม่งั้นเจ้าของร้านที่เพิ่งปิดสาขาประจำตัวตัวเองจะเข้าหลังบ้านไม่ได้เลยทั้งระบบ
 */
class ResolveCurrentBranch
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $allowed = $user->accessibleBranchIds();
        $requested = $request->integer('branch_id');

        if ($requested) {
            abort_unless(in_array($requested, $allowed, true), 403, 'ไม่มีสิทธิ์เข้าถึงสาขานี้');
        }

        $branchId = collect([$requested, $request->session()->get('branch_id'), $user->branch_id])
            ->first(fn ($id) => $id && in_array((int) $id, $allowed, true))
            ?? ($allowed[0] ?? null);

        if ($branchId) {
            $request->session()->put('branch_id', (int) $branchId);
            CurrentBranch::set(Branch::find($branchId));
        }

        return $next($request);
    }
}
