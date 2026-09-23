<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Services\PeriodLockService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ปิดงวดบัญชี
 *
 * งวดเป็นของ "สถานี" ไม่ใช่ของทั้งกิจการ — หน้านี้จึงทำงานกับสถานีที่กำลังเปิดดูอยู่
 * ตัวสลับสถานีบนหัวหน้าจอเป็นตัวเลือกว่ากำลังจัดการงวดของที่ไหน
 * และผู้จัดการมี accessibleBranchIds() แค่สาขาตัวเอง จึงปิดงวดข้ามสาขาไม่ได้อยู่แล้ว
 */
class PeriodController extends Controller
{
    /** ย้อนหลังกี่เดือนบนหน้าจอ — หนึ่งปีพอดีกับรอบที่คนทำบัญชีต้องย้อนดู */
    protected const MONTHS = 12;

    public function index(Request $request, PeriodLockService $periods): Response
    {
        $branch = CurrentBranch::getOrFail();

        return Inertia::render('BackOffice/Periods/Index', [
            'periods' => $periods->overview($branch, self::MONTHS),
            'branchName' => $branch->name,
            // ปุ่ม "เปิดงวดกลับ" โผล่เฉพาะเจ้าของ — ด่านจริงอยู่ใน PeriodLockService::reopen()
            'canReopen' => (bool) $request->user()?->isOwner(),
            'minReasonChars' => PeriodLockService::MIN_REASON_CHARS,
        ]);
    }

    public function close(Request $request, PeriodLockService $periods): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $branch = CurrentBranch::getOrFail();

        try {
            $record = $periods->close($branch, $data['period'], $request->user(), $data['note'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'ปิดงวด '.$periods->thaiLabel($record->period).' เรียบร้อย — บิลของเดือนนี้แก้ไม่ได้แล้ว');
    }

    public function reopen(Request $request, PeriodLockService $periods): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            // ความยาวขั้นต่ำเช็คซ้ำอีกชั้นในเซอร์วิส เผื่อมีคนเรียกจากที่อื่น
            'reason' => ['required', 'string', 'min:'.PeriodLockService::MIN_REASON_CHARS, 'max:255'],
        ], [
            'reason.required' => 'ต้องเขียนเหตุผลที่เปิดงวดกลับ',
            'reason.min' => 'เหตุผลสั้นเกินไป เขียนให้คนอ่านย้อนหลังเข้าใจว่าเปิดเพราะอะไร',
        ]);

        $branch = CurrentBranch::getOrFail();
        $user = $request->user();

        try {
            $record = $periods->reopen($branch, $data['period'], $user, $data['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            'เปิดงวด '.$periods->thaiLabel($record->period).' กลับมาแล้ว — อย่าลืมปิดใหม่เมื่อแก้เสร็จ'
        );
    }
}
