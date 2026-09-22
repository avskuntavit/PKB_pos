<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);
        $branchIds = $this->branchIds($request);

        // สรุปจำนวน action ของพนักงานแต่ละคนในช่วงที่เลือก
        // Laravel เติม prefix ให้ alias ด้วย SQL ดิบจึงต้องอ้างชื่อที่ผ่าน prefix แล้ว
        $al = DB::getTablePrefix().'al';
        $u = DB::getTablePrefix().'u';

        $activity = DB::table('activity_logs AS al')
            ->leftJoin('users AS u', 'u.id', '=', 'al.user_id')
            ->selectRaw("
                  {$al}.user_id
                , {$u}.name AS user_name
                , {$al}.action
                , COUNT(*) AS count
            ")
            ->whereIn('al.branch_id', $branchIds)
            ->whereBetween('al.business_date', [$from, $to])
            ->groupBy('al.user_id', 'u.name', 'al.action')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => [
                'user_id' => $rows->first()->user_id,
                'name' => $rows->first()->user_name ?? 'ไม่ระบุ',
                'total' => (int) $rows->sum('count'),
                'actions' => $rows->pluck('count', 'action')->map(fn ($c) => (int) $c),
            ])
            ->sortByDesc('total')
            ->values();

        return Inertia::render('BackOffice/Staff/Index', [
            'staff' => User::whereIn('branch_id', $branchIds)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'employee_code', 'is_active']),
            'activity' => $activity,
            'actionLabels' => ActivityLog::actionLabels(),
            'roles' => collect(UserRole::cases())->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()]),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }
}
