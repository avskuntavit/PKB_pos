<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\CashSettlementService;
use App\Services\ImageService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้านำส่งเงินสดสิ้นวัน ฝั่งพนักงาน
 *
 * เปิดหลังปิดรอบสุดท้ายของวัน กรอกยอดที่โอนจริงและแนบสลิป
 * ยอดที่ควรได้ระบบคิดให้ พนักงานแก้ไม่ได้ — กรอกได้เฉพาะยอดที่โอนจริง
 */
class CashSettlementController extends Controller
{
    public function __construct(protected CashSettlementService $settlements) {}

    public function show(Request $request): Response
    {
        $branch = CurrentBranch::getOrFail();
        $date = $this->resolveDate($request, $branch);

        $settlement = $this->settlements->forDate($branch, $date);

        return Inertia::render('Pos/CashSettlement', [
            'businessDate' => $date,
            // แปลงให้พร้อมแสดงตั้งแต่ตรงนี้ หน้าจอจะได้ไม่ต้องรู้จัก enum หรือรูปแบบวันที่ของ Eloquent
            'settlement' => [
                'id' => $settlement->id,
                'business_date' => $settlement->business_date->toDateString(),
                'expected_amount' => (float) $settlement->expected_amount,
                'held_cash_amount' => (float) $settlement->held_cash_amount,
                'due_amount' => $settlement->due(),
                'counted_amount' => (float) $settlement->counted_amount,
                'transferred_amount' => (float) $settlement->transferred_amount,
                'diff_amount' => (float) $settlement->diff_amount,
                'status' => $settlement->status->value,
                'status_label' => $settlement->status->label(),
                'reference' => $settlement->reference,
                'slip_path' => $settlement->slip_path,
                'note' => $settlement->note,
            ],
            'hasOpenShift' => $this->settlements->hasOpenShift($branch, $date),

            /*
            | เงินค้างที่ยังไม่มีใครตัดสิน — เตือน ไม่ใช่บล็อก
            |
            | ยอดถูกนับรวมในยอดที่ต้องนำส่งแล้ว เพราะเงินอยู่ในลิ้นชักจริง
            | แต่ถ้าปล่อยให้กลืนหายไปในตัวเลขเดียว พนักงานจะไม่รู้ว่ามีก้อนที่ยังไม่มีบิลรองรับ
            | และผู้จัดการจะไม่มีใครมาเตือนให้ไปตัดสิน
            |
            | ไม่บล็อกการนำส่งเพราะการตัดสินเป็นงานของผู้จัดการ
            | ถ้าบล็อก พนักงานที่กำลังจะปิดร้านจะทำอะไรไม่ได้เลยโดยไม่ใช่ความผิดของเขา
            */
            'unresolvedHeld' => $this->settlements->unresolvedHeldCashFor($branch, $date),
            'outstanding' => collect($this->settlements->outstanding($branch))
                ->map(fn (array $row) => [
                    'business_date' => $row['business_date'],
                    'expected' => $row['expected'],
                    'status' => $row['settlement']?->status->value,
                    'status_label' => $row['settlement']?->status->label() ?? 'ยังไม่ได้นำส่ง',
                ])
                ->values(),
        ]);
    }

    public function store(Request $request, ImageService $images): RedirectResponse
    {
        $branch = CurrentBranch::getOrFail();
        $date = $this->resolveDate($request, $branch);

        $data = $request->validate([
            'transferred_amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'slip' => ImageService::rules('cover'),
        ], [
            'transferred_amount.required' => 'กรุณากรอกยอดที่โอนจริง',
        ]);

        $settlement = $this->settlements->forDate($branch, $date);

        $slipPath = $request->hasFile('slip')
            ? $images->store($request->file('slip'), 'cover', $settlement->slip_path)
            : null;

        try {
            $this->settlements->submit(
                $settlement,
                (float) $data['transferred_amount'],
                $data['reference'] ?? null,
                $slipPath,
                $request->user(),
                $data['note'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'บันทึกการนำส่งเงินแล้ว รอผู้จัดการตรวจกับบัญชีธนาคาร');
    }

    /**
     * วันขายที่กำลังทำ — เลือกย้อนหลังได้ เพราะร้านมักลืมนำส่งของเมื่อวาน
     * แต่เลือกวันในอนาคตไม่ได้ เงินที่ยังไม่ได้รับจะนำส่งไม่ได้อยู่แล้ว
     */
    protected function resolveDate(Request $request, $branch): string
    {
        $today = $branch->businessDateFor();
        $asked = $request->query('date');

        if (! is_string($asked) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked)) {
            return $today->toDateString();
        }

        return $asked > $today->toDateString() ? $today->toDateString() : $asked;
    }
}
