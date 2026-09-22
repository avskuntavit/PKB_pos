<?php

namespace App\Http\Controllers;

use App\Support\CurrentBranch;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use ValidatesRequests;

    /** ช่วงวันขายที่เลือกอยู่ (default = วันนี้) */
    protected function dateRange(Request $request): array
    {
        $branch = CurrentBranch::get();
        $today = $branch ? $branch->businessDateFor()->toDateString() : now()->toDateString();

        $from = $request->date('from')?->toDateString() ?? $today;
        $to = $request->date('to')?->toDateString() ?? $today;

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /** สาขาที่ต้องการดูข้อมูล — เลือกได้หลายสาขาสำหรับเจ้าของ */
    protected function branchIds(Request $request): array
    {
        $user = $request->user();
        $allowed = $user->accessibleBranchIds();

        $requested = array_filter(array_map('intval', (array) $request->input('branch_ids', [])));

        if ($requested) {
            return array_values(array_intersect($requested, $allowed));
        }

        return array_values(array_filter([CurrentBranch::id()])) ?: $allowed;
    }
}
