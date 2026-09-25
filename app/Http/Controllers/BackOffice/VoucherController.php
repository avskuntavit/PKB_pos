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
        $branch = CurrentBranch::getOrFail();

        return Inertia::render('BackOffice/Vouchers/Index', [
            'vouchers' => Voucher::where('branch_id', CurrentBranch::id())
                ->withCount('redemptions')
                ->latest('id')
                ->paginate(20),

            'bases' => VoucherBase::options(),

            /*
            | ค่าเริ่มต้นของสาขา — หน้าจอเอาไปเลือกไว้ล่วงหน้าให้
            | ส่งเป็นค่าเดี่ยว ไม่ใช่ปล่อยให้ฝั่งหน้าจอเดาจาก options[0]
            | เพราะลำดับใน options เป็นเรื่องการแสดงผล ไม่ใช่นโยบายของร้าน
            */
            'defaultBase' => $branch->default_voucher_base->value,
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
            /*
            | ไม่ส่งมาก็ได้ — ใช้ค่าเริ่มต้นของสาขาแทน (ดู CurrentBranch ด้านล่าง)
            |
            | แต่แถวที่บันทึกลงตารางมีค่าจริงเสมอ ไม่เคยเป็น null
            | เพราะคูปองต้องอธิบายตัวเองได้ในอีกหกเดือนข้างหน้าโดยไม่ต้องไปดูว่า
            | ตอนนั้นสาขาตั้งค่าอะไรไว้ — ถ้าอ่านสดจากสาขา การเปลี่ยนค่าตั้งค่า
            | จะไปแก้เงื่อนไขของคูปองที่แจกออกไปแล้วทุกใบ
            */
            'base_mode' => ['nullable', Rule::enum(VoucherBase::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ], [], [
            'code' => 'รหัส',
            'base_mode' => 'ฐานที่ใช้คิด',
        ]);

        $data['base_mode'] ??= CurrentBranch::getOrFail()->default_voucher_base;

        Voucher::create($data + ['branch_id' => $branchId, 'is_active' => true]);

        return back()->with('success', 'สร้างรหัสส่วนลดแล้ว');
    }
}
