<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * สลับสถานีที่กำลังดูอยู่ในหลังบ้าน
 *
 * เก็บลง session แทนที่จะแปะ ?branch_id ไว้ใน URL เพราะทุกหน้าหลังบ้านอ่านจาก
 * ResolveCurrentBranch ตัวเดียวกันอยู่แล้ว ถ้าฝากไว้ใน URL พอกดลิงก์ไปหน้าอื่น
 * สถานีจะเด้งกลับเป็นของเดิมโดยที่คนใช้ไม่รู้ตัว
 */
class StationSwitchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $allowed = $request->user()->accessibleBranchIds();

        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
        ]);

        abort_unless(in_array((int) $data['branch_id'], $allowed, true), 403, 'ไม่มีสิทธิ์เข้าถึงสถานีนี้');

        $branch = Branch::findOrFail($data['branch_id']);

        $request->session()->put('branch_id', $branch->id);

        return back()->with('success', "เปลี่ยนมาที่{$branch->name}แล้ว");
    }
}
