<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $shifts = Shift::with(['openedBy:id,name', 'closedBy:id,name'])
            ->whereIn('branch_id', $this->branchIds($request))
            ->whereBetween('business_date', [$from, $to])
            ->withCount('orders')
            ->latest('opened_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('BackOffice/Shifts/Index', [
            'shifts' => $shifts,
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function show(Shift $shift): Response
    {
        abort_unless(in_array($shift->branch_id, request()->user()->accessibleBranchIds(), true), 403);

        $shift->load(['openedBy:id,name', 'closedBy:id,name', 'cashMovements.user:id,name']);

        // ยอดขายแยกตามช่องทางการชำระเงินของรอบนี้
        // Laravel เติม prefix ให้ alias ด้วย SQL ดิบจึงต้องอ้างชื่อที่ผ่าน prefix แล้ว
        $pm = DB::getTablePrefix().'pm';

        $byMethod = DB::table('payments AS pm')
            ->selectRaw("
                  {$pm}.method
                , COUNT(*) AS count
                , SUM({$pm}.amount) AS amount
            ")
            ->where('pm.shift_id', $shift->id)
            ->groupBy('pm.method')
            ->get()
            ->map(fn ($r) => [
                'method' => $r->method,
                'label' => PaymentMethod::tryFrom($r->method)?->label() ?? $r->method,
                'count' => (int) $r->count,
                'amount' => (float) $r->amount,
            ]);

        return Inertia::render('BackOffice/Shifts/Show', [
            'shift' => $shift,
            'byMethod' => $byMethod,
            'totals' => [
                'orders' => $shift->orders()->count(),
                'sales' => (float) $shift->orders()->paid()->sum('grand_total'),
            ],
        ]);
    }
}
