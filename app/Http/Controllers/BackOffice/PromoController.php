<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ActivityLogger;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * กลุ่มโปรโมทบนหน้าสั่งอาหาร
 *
 *   สินค้าเด่น    รูปใหญ่ใบเดียวบนสุด — สาขาละ 1 เมนู
 *   ตะแกรงโปรโมท  2 คอลัมน์ x 4 แถว = 8 เมนู เรียงตาม promo_sort
 *
 * ทั้งสองอย่างเลือกเองในหลังบ้าน ไม่ได้ดึงจากยอดขาย
 * ร้านจึงดันเมนูใหม่ที่อยากขายขึ้นมาได้ ไม่ต้องรอให้มียอดก่อน
 */
class PromoController extends Controller
{
    public const MAX_SLOTS = 8;

    public function update(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $branch = CurrentBranch::getOrFail();

        $own = Product::forCatalog($branch->id)->pluck('id')->all();

        $data = $request->validate([
            'promo_title' => ['required', 'string', 'max:60'],
            'featured_id' => ['nullable', Rule::in($own)],
            'items' => ['present', 'array', 'max:'.self::MAX_SLOTS],
            'items.*.product_id' => ['required', Rule::in($own)],
            'items.*.promo_label' => ['nullable', 'string', 'max:20'],
        ], [
            'items.max' => 'ตะแกรงโปรโมทใส่ได้มากสุด '.self::MAX_SLOTS.' เมนู',
        ]);

        $ids = array_column($data['items'], 'product_id');

        if (count($ids) !== count(array_unique($ids))) {
            return back()->with('error', 'มีเมนูซ้ำกันในตะแกรงโปรโมท');
        }

        DB::transaction(function () use ($branch, $data, $ids) {
            $branch->update(['promo_title' => $data['promo_title']]);

            // ล้างของเดิมทั้งสาขาก่อน เมนูที่ถูกเอาออกจะได้ไม่ค้างอยู่
            Product::forCatalog($branch->id)
                ->update(['is_featured' => false, 'is_promoted' => false, 'promo_label' => null, 'promo_sort' => 0]);

            foreach (array_values($data['items']) as $i => $row) {
                Product::forCatalog($branch->id)
                    ->whereKey($row['product_id'])
                    ->update([
                        'is_promoted' => true,
                        'promo_label' => blank($row['promo_label'] ?? null) ? null : $row['promo_label'],
                        'promo_sort' => $i,
                    ]);
            }

            if (! empty($data['featured_id'])) {
                Product::forCatalog($branch->id)
                    ->whereKey($data['featured_id'])
                    ->update(['is_featured' => true]);
            }
        });

        $logger->log('promo.update', null, [
            'featured' => $data['featured_id'] ?? null,
            'count' => count($ids),
        ], $branch);

        return back()->with('success', 'บันทึกกลุ่มโปรโมทแล้ว');
    }
}
