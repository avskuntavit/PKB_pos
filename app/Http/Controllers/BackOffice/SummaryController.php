<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Services\SummaryReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SummaryController extends Controller
{
    public function __invoke(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);
        $branchIds = $this->branchIds($request);

        $report = SummaryReportService::make($branchIds, $from, $to);

        return Inertia::render('BackOffice/Summary', [
            'filters' => [
                'from' => $from,
                'to' => $to,
                'branch_ids' => $branchIds,
                'hour_basis' => $request->input('hour_basis', 'closed_at'),
            ],
            'report' => $report->all(),
        ]);
    }
}
