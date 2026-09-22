<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ActivityLogger;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * หมวดหมู่เมนู — ปุ่มจัดกลุ่มบนหน้า POS
 *
 * ปิดหมวด (is_active = false) ไม่ได้ลบเมนูในหมวดนั้น
 * แค่ไม่ให้เลือกหมวดนี้ตอนเพิ่มเมนูใหม่และไม่โผล่เป็นปุ่มกรองบนหน้าขาย
 */
class CategoryController extends Controller
{
    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $branchId = CurrentBranch::id();

        $category = Category::create($this->validated($request) + [
            'branch_id' => $branchId,
            'sort_order' => (int) Category::where('branch_id', $branchId)->max('sort_order') + 1,
        ]);

        $logger->log('category.update', $category, ['mode' => 'create']);

        return back()->with('success', 'เพิ่มหมวดหมู่แล้ว');
    }

    public function update(Request $request, Category $category, ActivityLogger $logger): RedirectResponse
    {
        $this->guard($category);

        $category->update($this->validated($request));
        $logger->log('category.update', $category, ['mode' => 'update']);

        return back()->with('success', 'บันทึกหมวดหมู่แล้ว');
    }

    public function destroy(Category $category, ActivityLogger $logger): RedirectResponse
    {
        $this->guard($category);

        // เมนูที่ค้างอยู่ในหมวดจะกลายเป็น "ไม่ระบุหมวด" แบบเงียบ ๆ
        // ให้ย้ายเมนูออกก่อน จะได้ไม่มีเมนูหลุดหายไปจากหน้า POS โดยไม่รู้ตัว
        $count = $category->products()->count();

        if ($count > 0) {
            return back()->with('error', "ลบไม่ได้ — ยังมีเมนูอยู่ในหมวดนี้ {$count} รายการ (ปิดหมวดแทนได้)");
        }

        $category->delete();
        $logger->log('category.update', $category, ['mode' => 'delete']);

        return back()->with('success', 'ลบหมวดหมู่แล้ว');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'color.regex' => 'สีต้องอยู่ในรูปแบบ #rrggbb',
        ]);
    }

    protected function guard(Category $category): void
    {
        // หมวดกลางแก้ได้เฉพาะเจ้าของ — ผู้จัดการแตะได้แค่หมวดของสาขาตัวเอง
        if ($category->branch_id === null) {
            abort_unless(auth()->user()?->isOwner(), 403, 'หมวดกลางแก้ได้เฉพาะเจ้าของ');

            return;
        }

        abort_unless($category->branch_id === CurrentBranch::id(), 403, 'หมวดหมู่นี้เป็นของสาขาอื่น');
    }
}
