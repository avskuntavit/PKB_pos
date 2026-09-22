<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesTarget;
use App\Services\ActivityLogger;
use App\Services\SalesTargetService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ตั้งเป้ายอดขายรายเดือน
 *
 * หน้านี้โชว์ยอดจริงของปีที่แล้วกับปีนี้ไว้ข้าง ๆ ช่องกรอกโดยตั้งใจ
 * เพราะเป้าที่ตั้งโดยไม่ดูของเดิมมักกลายเป็นเลขกลม ๆ ที่ไม่มีใครเชื่อ
 */
class SalesTargetController extends Controller
{
    public function index(Request $request, SalesTargetService $targets): Response
    {
        $branch = $this->resolveBranch($request);
        $year = $this->resolveYear($request);

        $actual = $this->actualByMonth($branch->id, $year);
        $previous = $this->actualByMonth($branch->id, $year - 1);

        $saved = SalesTarget::where('branch_id', $branch->id)
            ->where('year', $year)
            ->get()
            ->keyBy('month');

        $months = collect(range(1, 12))->map(function (int $month) use ($saved, $actual, $previous, $year) {
            // เดือนที่ยังไม่เคยตั้งเป้าจะไม่มีแถวในตาราง ต้องหยิบผ่าน get() แล้วใช้ nullsafe
            $row = $saved->get($month);

            $target = (float) ($row?->target_amount ?? 0);
            $sold = (float) ($actual[$month] ?? 0);

            return [
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->locale('th')->translatedFormat('F'),
                'target_amount' => $target,
                'food_cost_percent' => $row?->food_cost_percent !== null
                    ? (float) $row->food_cost_percent
                    : null,
                'note' => $row?->note,
                'actual' => $sold,
                'last_year' => (float) ($previous[$month] ?? 0),
                'percent' => $target > 0 ? round($sold / $target * 100, 1) : null,
                // เป้ารายวันโดยเฉลี่ย ช่วยให้เห็นว่าเลขที่ตั้งสมเหตุสมผลไหมตอนกรอก
                'avg_per_day' => $target > 0
                    ? round($target / Carbon::create($year, $month, 1)->daysInMonth, 0)
                    : 0.0,
            ];
        })->all();

        return Inertia::render('BackOffice/Settings/Targets', [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'branches' => Branch::whereIn('id', $request->user()->accessibleBranchIds())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])
                ->all(),
            'year' => $year,
            'years' => range(now()->year - 2, now()->year + 1),
            'months' => $months,
            'weekdayWeights' => $this->weekdayPreview($targets, $branch->id),
            'minHistoryDays' => SalesTargetService::MIN_HISTORY_DAYS,
            'historyWeeks' => SalesTargetService::HISTORY_WEEKS,
        ]);
    }

    public function update(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $branch = $this->resolveBranch($request);

        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2200'],
            'months' => ['required', 'array', 'size:12'],
            'months.*.month' => ['required', 'integer', 'between:1,12'],
            'months.*.target_amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'months.*.food_cost_percent' => ['nullable', 'numeric', 'between:0,100'],
            'months.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $branch, $request) {
            foreach ($data['months'] as $row) {
                SalesTarget::updateOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'year' => (int) $data['year'],
                        'month' => (int) $row['month'],
                    ],
                    [
                        'target_amount' => (float) $row['target_amount'],
                        'food_cost_percent' => $row['food_cost_percent'] !== null
                            ? (float) $row['food_cost_percent']
                            : null,
                        'note' => $row['note'] ?? null,
                        'updated_by' => $request->user()->id,
                    ],
                );
            }
        });

        $logger->log('target.update', null, [
            'branch' => $branch->name,
            'year' => $data['year'],
            'total' => array_sum(array_column($data['months'], 'target_amount')),
        ]);

        return back()->with('success', "บันทึกเป้าปี {$data['year']} ของ{$branch->name} แล้ว");
    }

    /* ---------- ภายใน ---------- */

    protected function resolveBranch(Request $request): Branch
    {
        $allowed = $request->user()->accessibleBranchIds();
        $requested = (int) $request->input('branch_id', 0);

        $id = in_array($requested, $allowed, true)
            ? $requested
            : (CurrentBranch::id() ?? ($allowed[0] ?? 0));

        abort_if(! $id, 403, 'ยังไม่ได้เลือกสาขา');

        return Branch::findOrFail($id);
    }

    protected function resolveYear(Request $request): int
    {
        $year = (int) $request->input('year', now()->year);

        return $year >= 2000 && $year <= 2200 ? $year : (int) now()->year;
    }

    /**
     * ยอดขายจริงแยกรายเดือนของปีนั้น
     *
     * @return array<int, float>
     */
    protected function actualByMonth(int $branchId, int $year): array
    {
        $rows = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('business_date', ["{$year}-01-01", "{$year}-12-31"])
            ->selectRaw('
                  business_date
                , COALESCE(SUM(grand_total), 0) AS amount
            ')
            ->groupBy('business_date')
            ->get();

        $byMonth = [];

        foreach ($rows as $row) {
            // ตัดเอาเลขเดือนจาก Y-m-d ตรง ๆ แทนที่จะ GROUP BY ด้วยฟังก์ชันเดือน
            // ซึ่งเขียนไม่เหมือนกันระหว่าง SQLite กับ SQL Server
            $month = (int) substr((string) $row->business_date, 5, 2);
            $byMonth[$month] = ($byMonth[$month] ?? 0) + (float) $row->amount;
        }

        return $byMonth;
    }

    /** น้ำหนักวันในสัปดาห์ที่ระบบจะใช้เกลี่ยเป้า — โชว์ให้เห็นว่าไม่ได้หาร 30 ตรง ๆ */
    protected function weekdayPreview(SalesTargetService $targets, int $branchId): array
    {
        $labels = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
        $weights = $targets->weekdayWeights($branchId);

        $percents = collect(range(0, 6))
            ->map(fn (int $d) => round(($weights[$d] ?? 0) * 100, 1))
            ->all();

        /*
        | น้ำหนักเท่ากันหมดทั้ง 7 วัน = ยังไม่มีประวัติพอ ระบบเกลี่ยเท่ากันไว้ก่อน
        |
        | กรณีนี้ห้ามไฮไลต์ "วันขายดี" เด็ดขาด เพราะจะไปชี้วันที่ไม่ได้ขายดีจริง
        | ให้ดูเหมือนมีความหมาย แล้วเจ้าของร้านอาจเอาไปวางแผนคนหรือสั่งของตามนั้น
        */
        $estimated = count(array_unique($percents)) === 1;

        $ranked = $estimated ? [] : $this->topThree($percents);

        return collect(range(0, 6))->map(fn (int $d) => [
            'weekday' => $d,
            'label' => $labels[$d],
            'percent' => $percents[$d],
            // ส่งเฉพาะอันดับ 1-3 ที่เหลือเป็น null หน้าเว็บจะได้ไม่ต้องรู้เกณฑ์เอง
            'rank' => $ranked[(string) $percents[$d]] ?? null,
        ])->all();
    }

    /**
     * หา 3 วันขายดีที่สุด — คืน [ค่าเปอร์เซ็นต์ => อันดับ]
     *
     * วันที่ยอดเท่ากันต้องได้อันดับเดียวกัน ไม่งั้นจะดูเหมือนระบบชี้ว่าวันหนึ่งดีกว่าอีกวัน
     * ทั้งที่ตัวเลขเท่ากันเป๊ะ แต่ถ้ากลุ่มที่เสมอกันทำให้ไฮไลต์เกิน 3 วัน ให้ตัดทิ้งทั้งกลุ่ม
     * เพราะไฮไลต์ 6 จาก 7 วันไม่ได้บอกอะไรเลย — สู้ไม่ไฮไลต์ยังดีกว่า
     *
     * @param  array<int, float>  $percents
     * @return array<string, int>
     */
    protected function topThree(array $percents): array
    {
        $ranked = [];
        $taken = 0;

        foreach (collect($percents)->unique()->sortDesc()->values() as $value) {
            // วันที่ร้านปิด (0%) ไม่นับเป็นวันขายดีแม้จะเหลือช่องว่าง
            if ($value <= 0) {
                break;
            }

            $tied = count(array_keys($percents, $value, true));

            if ($taken + $tied > 3) {
                break;
            }

            $ranked[(string) $value] = count($ranked) + 1;
            $taken += $tied;
        }

        return $ranked;
    }
}
