<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\EmployeeStatus;
use App\Models\Order;
use App\Services\StaffBenefitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** หน้าบัญชีลูกค้า — ประวัติการสั่ง แต้ม และสิทธิ์พนักงานองค์กร */
class AccountController extends StorefrontController
{
    public function show(Request $request, StaffBenefitService $benefits): Response
    {
        $customer = $this->currentCustomer($request);
        $branch = $this->resolveBranch($customer->branch?->code);

        $orders = Order::with(['review:id,order_id,rating', 'branch:id,name'])
            ->where('customer_id', $customer->id)
            ->latest('opened_at')
            ->limit(30)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'order_no' => $o->order_no,
                'branch' => $o->branch?->name,
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'type_label' => $o->type->label(),
                'grand_total' => (float) $o->grand_total,
                'staff_discount' => (float) $o->staff_discount,
                'opened_at' => $o->opened_at?->toIso8601String(),
                'track_token' => $o->track_token,
                'rating' => $o->review?->rating,
                'can_review' => $o->status->countsAsSale() && $o->review === null,
            ]);

        return Inertia::render('Storefront/Account', [
            'branch' => ['code' => $branch->code, 'name' => $branch->name],
            'customer' => [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'points' => (int) $customer->points,
                'tier' => $customer->tier,
                'visit_count' => (int) $customer->visit_count,
                'total_spent' => (float) $customer->total_spent,
                'employee_code' => $customer->employee_code,
                'employee_status' => $customer->employee_status?->value,
                'employee_status_label' => $customer->employee_status?->label(),
                'employee_note' => $customer->employee_note,
            ],
            'benefit' => $benefits->balanceFor($customer, $branch),
            'orders' => $orders,
        ]);
    }

    /** ขอสิทธิ์พนักงานองค์กร — รอ HR อนุมัติ */
    public function requestEmployeeBenefit(Request $request): RedirectResponse
    {
        $customer = $this->currentCustomer($request);

        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:40'],
            'employee_department' => ['nullable', 'string', 'max:100'],
        ], [], ['employee_code' => 'รหัสพนักงาน']);

        if ($customer->employee_status === EmployeeStatus::Approved) {
            return back()->with('error', 'คุณได้รับสิทธิ์พนักงานอยู่แล้ว');
        }

        if ($customer->employee_status === EmployeeStatus::Pending) {
            return back()->with('error', 'คำขอของคุณอยู่ระหว่างรอ HR อนุมัติ');
        }

        $customer->update([
            'employee_code' => $data['employee_code'],
            'employee_department' => $data['employee_department'] ?? null,
            'employee_status' => EmployeeStatus::Pending,
            'employee_requested_at' => now(),
            'employee_note' => null,
            'employee_reviewed_at' => null,
            'employee_reviewed_by' => null,
        ]);

        return back()->with('success', 'ส่งคำขอแล้ว รอ HR ตรวจสอบสักครู่');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $customer = $this->currentCustomer($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'birthdate' => ['nullable', 'date'],
        ]);

        $customer->update($data);

        return back()->with('success', 'บันทึกข้อมูลแล้ว');
    }
}
