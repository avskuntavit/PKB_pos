<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;

/**
 * ตัดสินว่า request นี้ทำงานกับสาขาไหน
 * ลำดับ: ?branch_id ใน query -> ค่าใน session -> สาขาของ user
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

        $branchId = $request->integer('branch_id')
            ?: $request->session()->get('branch_id')
            ?: $user->branch_id
            ?: ($allowed[0] ?? null);

        if ($branchId && ! in_array((int) $branchId, $allowed, true)) {
            abort(403, 'ไม่มีสิทธิ์เข้าถึงสาขานี้');
        }

        if ($branchId) {
            $request->session()->put('branch_id', (int) $branchId);
            CurrentBranch::set(Branch::find($branchId));
        }

        return $next($request);
    }
}
