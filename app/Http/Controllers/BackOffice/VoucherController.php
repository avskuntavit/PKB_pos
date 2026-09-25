<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\VoucherBase;
use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VoucherController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('BackOffice/Vouchers/Index', [
            'vouchers' => Voucher::where('branch_id', CurrentBranch::id())
                ->withCount('redemptions')
                ->latest('id')
                ->paginate(20),

            'bases' => VoucherBase::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $branchId = CurrentBranch::id();

        $data = $request->validate([
            /*
            | รหัสห้ามซ้ำในสาขาเดียวกัน — ตารางมี unique index อยู่แล้ว
            | แต่ถ้าไม่ตรวจที่นี่ ผู้ใช้จะเจอหน้า error 500 แทนข้อความว่ารหัสนี้มีแล้ว
            */
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('vouchers', 'code')->where('branch_id', $branchId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:amount,percent'],
            'value' => ['required', 'numeric', 'min:0'],
            'min_spend' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['required', 'integer', 'min:1'],
            'base_mode' => ['required', Rule::enum(VoucherBase::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ], [], [
            'code' => 'รหัส',
            'base_mode' => 'ฐานที่ใช้คิด',
        ]);

        Voucher::create($data + ['branch_id' => $branchId, 'is_active' => true]);

        return back()->with('success', 'สร้างรหัสส่วนลดแล้ว');
    }
}
