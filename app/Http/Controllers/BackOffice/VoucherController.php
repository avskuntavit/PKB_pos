<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $branchId = CurrentBranch::id();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:amount,percent'],
            'value' => ['required', 'numeric', 'min:0'],
            'min_spend' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['required', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        Voucher::create($data + ['branch_id' => $branchId, 'is_active' => true]);

        return back()->with('success', 'สร้างรหัสส่วนลดแล้ว');
    }
}
