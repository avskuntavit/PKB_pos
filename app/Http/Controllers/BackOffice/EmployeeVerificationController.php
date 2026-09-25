<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR อนุมัติสิทธิ์พนักงานองค์กร
 *
 * ระบบไม่ได้ต่อกับฐานข้อมูล HR จึงยืนยันตัวตนอัตโนมัติไม่ได้
 * คนที่รู้ว่ารหัสพนักงานนี้เป็นของใครจริงคือ HR — หน้านี้จึงเป็นการให้คนตัดสิน
 */
class EmployeeVerificationController extends Controller
{
    public function index(Request $request): Response
    {
        $branchIds = $this->branchIds($request);

        /*
        | งวดของสาขาที่ HR กำลังทำงานอยู่ ไม่ใช่เดือนตามนาฬิกา
        | ไม่งั้นช่วงหลังเที่ยงคืนของวันที่ 1 คอลัมน์นี้จะขึ้น 0 ทั้งหน้า
        | ทั้งที่แคชเชียร์เห็นยอดสะสมของเมื่อคืนอยู่
        */
        $period = CurrentBranch::get()?->currentPeriod() ?? now()->format('Y-m');

        $rows = fn (?EmployeeStatus $status) => Customer::with('employeeReviewer:id,name')
            ->whereIn('branch_id', $branchIds)
            ->when($status, fn ($q) => $q->where('employee_status', $status->value))
            ->orderByDesc('employee_requested_at')
            ->limit(100)
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'employee_code' => $c->employee_code,
                'employee_department' => $c->employee_department,
                'status' => $c->employee_status?->value,
                'status_label' => $c->employee_status?->label(),
                'note' => $c->employee_note,
                'requested_at' => $c->employee_requested_at?->toIso8601String(),
                'reviewed_at' => $c->employee_reviewed_at?->toIso8601String(),
                'reviewer' => $c->employeeReviewer?->name,
                'used_this_month' => $c->benefitUsedIn($period),
            ])
            ->all();

        return Inertia::render('BackOffice/Employees/Index', [
            'pending' => $rows(EmployeeStatus::Pending),
            'approved' => $rows(EmployeeStatus::Approved),
            'rejected' => $rows(EmployeeStatus::Rejected),
        ]);
    }

    public function approve(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($customer);

        $customer->update([
            'employee_status' => EmployeeStatus::Approved,
            'employee_reviewed_at' => now(),
            'employee_reviewed_by' => $request->user()->id,
            'employee_note' => null,
            'tier' => 'staff',
        ]);

        return back()->with('success', "อนุมัติสิทธิ์พนักงานให้ {$customer->name} แล้ว");
    }

    public function reject(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($customer);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:255'],
        ], [], ['note' => 'เหตุผล']);

        $customer->update([
            'employee_status' => EmployeeStatus::Rejected,
            'employee_reviewed_at' => now(),
            'employee_reviewed_by' => $request->user()->id,
            'employee_note' => $data['note'],
            'tier' => 'regular',
        ]);

        return back()->with('success', 'บันทึกการปฏิเสธแล้ว ลูกค้าจะเห็นเหตุผลในหน้าบัญชีของตัวเอง');
    }

    /** ถอนสิทธิ์ เช่น พนักงานลาออก */
    public function revoke(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($customer);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $customer->update([
            'employee_status' => EmployeeStatus::Rejected,
            'employee_reviewed_at' => now(),
            'employee_reviewed_by' => $request->user()->id,
            'employee_note' => $data['note'] ?? 'ถอนสิทธิ์โดยผู้ดูแล',
            'tier' => 'regular',
        ]);

        return back()->with('success', 'ถอนสิทธิ์แล้ว — ประวัติการใช้สิทธิ์เดิมยังอยู่ครบ');
    }

    protected function authorizeCustomer(Customer $customer): void
    {
        abort_unless(
            in_array($customer->branch_id, request()->user()->accessibleBranchIds(), true),
            403
        );
    }
}
