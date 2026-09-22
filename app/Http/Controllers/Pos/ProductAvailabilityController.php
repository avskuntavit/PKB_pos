<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Services\ActivityLogger;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ปิดขายเมนูชั่วคราว ("ของหมดวันนี้")
 *
 * แยกจากการปิดเมนูถาวร (is_active) เพราะเป็นคนละเจตนา:
 * ของหมดคือเรื่องของวันนี้ พอถึงรอบวันถัดไปควรกลับมาขายเองโดยไม่ต้องมีใครจำ
 */
class ProductAvailabilityController extends Controller
{
    /** รายการเมนูพร้อมสถานะ สำหรับแผงเปิด-ปิดบน POS */
    public function index(): JsonResponse
    {
        $products = Product::with('category:id,name')
            ->forCatalog(CurrentBranch::id())
            ->withOverride(CurrentBranch::id())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category?->name,
                'is_available' => $p->isAvailable(),
                // โชว์ของสาขานี้ — เมนูกลางถูกปิดที่สาขาอื่นไม่เกี่ยวกับหน้านี้
                'unavailable_until' => ($p->overrideFor(CurrentBranch::id())?->unavailable_until
                    ?? $p->unavailable_until)?->toIso8601String(),
            ]);

        return response()->json(['products' => $products]);
    }

    public function toggle(Request $request, Product $product, ActivityLogger $logger): RedirectResponse
    {
        abort_unless($product->branch_id === null || $product->branch_id === CurrentBranch::id(), 403);

        $data = $request->validate([
            // ปิดจนถึงตอนไหน — ไม่ระบุ = จนกว่าจะขึ้นวันขายถัดไป
            'until' => ['nullable', 'date'],
            'available' => ['boolean'],
        ]);

        if ($data['available'] ?? false) {
            $this->setAvailability($product, null);

            $logger->log('menu.update', $product, ['mode' => 'back_in_stock']);

            return back()->with('success', $product->name.' กลับมาขายแล้ว');
        }

        $until = isset($data['until'])
            ? \Illuminate\Support\Carbon::parse($data['until'])
            : CurrentBranch::getOrFail()->businessDateFor()->addDay()->setTimeFromTimeString(
                (string) CurrentBranch::getOrFail()->business_day_start
            );

        $this->setAvailability($product, $until);

        $logger->log('menu.update', $product, ['mode' => 'out_of_stock', 'until' => $until->toIso8601String()]);

        return back()->with('success', 'ปิดขาย '.$product->name.' ชั่วคราวแล้ว');
    }

    /**
     * ปิด/เปิดขายเฉพาะสาขานี้
     *
     * เมนูกลางถูกหลายสาขาใช้ร่วมกัน ถ้าเขียน unavailable_until ลงตัวเมนูตรง ๆ
     * "ต้มยำหมด" ที่สาขาหนึ่งจะทำให้ทุกสาขาขายไม่ได้พร้อมกัน จึงเขียนลง branch_product แทน
     *
     * เมนูเฉพาะสาขาเขียนลงตัวเมนูได้เลย เพราะไม่มีสาขาอื่นใช้อยู่
     */
    protected function setAvailability(Product $product, ?\Illuminate\Support\Carbon $until): void
    {
        if ($product->branch_id !== null) {
            $product->update(['unavailable_until' => $until]);

            return;
        }

        BranchProduct::updateOrCreate(
            ['branch_id' => CurrentBranch::id(), 'product_id' => $product->id],
            ['unavailable_until' => $until],
        );
    }
}
