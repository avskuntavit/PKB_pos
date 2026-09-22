<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\CashSettlementStatus;
use App\Http\Controllers\Controller;
use App\Models\CashSettlement;
use App\Services\CashSettlementService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้าตรวจการนำส่งเงินสด ฝั่งผู้จัดการ
 *
 * งานของหน้านี้คือตอบคำถามเดียว: เงินสดของแต่ละวันเข้าบัญชีบริษัทครบหรือยัง
 * จึงเรียงจากวันที่ค้างนานที่สุดขึ้นก่อน ไม่ใช่เรียงวันที่ล่าสุดเหมือนหน้ารายการทั่วไป
 */
class CashSettlementController extends Controller
{
    public function __construct(protected CashSettlementService $settlements) {}

    public function index(Request $request): Response
    {
        $branch = CurrentBranch::getOrFail();

        $status = $request->query('status');
        $status = in_array($status, array_column(CashSettlementStatus::cases(), 'value'), true)
            ? $status
            : null;

        $rows = CashSettlement::with('settledBy:id,name', 'verifiedBy:id,name')
            ->where('branch_id', $branch->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('business_date')
            ->limit(120)
            ->get()
            ->map(fn (CashSettlement $s) => [
                'id' => $s->id,
                'business_date' => $s->business_date->toDateString(),
                'expected_amount' => (float) $s->expected_amount,
                'counted_amount' => (float) $s->counted_amount,
                'transferred_amount' => (float) $s->transferred_amount,
                'diff_amount' => (float) $s->diff_amount,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'settled_by' => $s->settledBy?->name,
                'transferred_at' => $s->transferred_at?->toIso8601String(),
                'reference' => $s->reference,
                'slip_path' => $s->slip_path,
                'verified_by' => $s->verifiedBy?->name,
                'verified_at' => $s->verified_at?->toIso8601String(),
                'note' => $s->note,
                'days_overdue' => $s->daysOverdue(),
            ]);

        return Inertia::render('BackOffice/CashSettlements/Index', [
            'settlements' => $rows,
            'statuses' => CashSettlementStatus::options(),
            'filters' => ['status' => $status],
            // วันที่ยังไม่มีใครกดนำส่งเลย — อันตรายกว่าวันที่นำส่งแล้วยอดไม่ตรง
            'missing' => collect($this->settlements->outstanding($branch))
                ->filter(fn (array $row) => $row['settlement'] === null)
                ->map(fn (array $row) => [
                    'business_date' => $row['business_date'],
                    'expected' => $row['expected'],
                ])
                ->values(),
        ]);
    }

    public function verify(Request $request, CashSettlement $settlement): RedirectResponse
    {
        $this->authorizeSettlement($settlement);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $this->settlements->verify($settlement, $request->user(), $data['note'] ?? null);

        return back()->with('success', 'ยืนยันว่าเงินเข้าบัญชีแล้ว');
    }

    public function dispute(Request $request, CashSettlement $settlement): RedirectResponse
    {
        $this->authorizeSettlement($settlement);

        // บังคับให้เขียนเหตุผล เพราะใบที่ถูกตีกลับต้องมีคนตามต่อ
        // ถ้าไม่รู้ว่าติดตรงไหน คนที่มารับเรื่องต่อจะเริ่มจากศูนย์
        $data = $request->validate([
            'note' => ['required', 'string', 'max:255'],
        ], [
            'note.required' => 'กรุณาระบุว่ายอดไม่ตรงตรงไหน',
        ]);

        $this->settlements->dispute($settlement, $request->user(), $data['note']);

        return back()->with('success', 'บันทึกว่ายอดไม่ตรงแล้ว');
    }

    protected function authorizeSettlement(CashSettlement $settlement): void
    {
        abort_unless($settlement->branch_id === CurrentBranch::id(), 403);
    }
}
