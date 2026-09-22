<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\ExecutiveDashboardService;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้าแรกของผู้บริหาร
 *
 * ต่างจากหน้ารายงานสรุปตรงที่ไม่มีตัวกรองวัน — ตั้งใจให้ตอบ "วันนี้กับเดือนนี้" เท่านั้น
 * อยากเจาะย้อนหลังค่อยไปหน้ารายงานสรุป ซึ่งมีตัวกรองครบอยู่แล้ว
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ExecutiveDashboardService $dashboard): Response
    {
        $branchIds = $this->branchIds($request);
        $primary = CurrentBranch::get() ?? Branch::whereIn('id', $branchIds)->firstOrFail();

        return Inertia::render('BackOffice/Dashboard', [
            'filters' => ['branch_ids' => $branchIds],
            'branches' => Branch::whereIn('id', $request->user()->accessibleBranchIds())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])
                ->all(),
            'data' => $dashboard->build($branchIds, $primary),
        ]);
    }
}
