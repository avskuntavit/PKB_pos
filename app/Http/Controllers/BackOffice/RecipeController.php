<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\BranchStockItem;
use App\Models\StockItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * สูตรและสัดส่วนส่วนผสม
 *
 * สูตรทำสองหน้าที่พร้อมกัน:
 *   1. ตัดสต๊อกวัตถุดิบอัตโนมัติเมื่อขาย
 *   2. คำนวณต้นทุนจริงของเมนู แทนการเดาแล้วกรอกมือ
 */
class RecipeController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = CurrentBranch::id();

        /*
        | ต้นทุนของทุกชิ้นในคลังของสาขานี้ อ่านรวดเดียว
        |
        | ของในคลังเป็นแม่แบบกลาง แต่ต้นทุนอยู่ที่ branch_stock_items รายสาขา
        | ถ้าไล่ถามทีละบรรทัดในสูตร หน้านี้จะยิง query เป็นร้อยครั้ง
        */
        $costs = BranchStockItem::where('branch_id', $branchId)
            ->pluck('cost_per_unit', 'stock_item_id');

        // สูตรแยกรายสาขา — ลากมาเฉพาะของสาขานี้ ไม่งั้นเมนูกลางจะโชว์สูตรของทุกสาขาปนกัน
        $products = Product::with([
            'category:id,name,color',
            'recipeItems' => fn ($q) => $q->where('branch_id', $branchId)->with('stockItem:id,name,unit'),
        ])
            ->forCatalog($branchId)
            ->search($request->input('search'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category?->name,
                'price' => (float) $p->price,
                'cost' => (float) $p->cost,
                'recipe_cost' => $p->recipeCost(),
                'track_stock' => $p->track_stock,
                'has_recipe' => $p->recipeItems->isNotEmpty(),
                // กำไรคิดจากต้นทุนที่บันทึกไว้ ซึ่งอาจต่างจากต้นทุนตามสูตรถ้ายังไม่กดซิงก์
                'margin' => (float) $p->price > 0
                    ? round(((float) $p->price - (float) $p->cost) / (float) $p->price * 100, 1)
                    : 0.0,
                'items' => $p->recipeItems->map(fn (RecipeItem $r) => [
                    'id' => $r->id,
                    'stock_item_id' => $r->stock_item_id,
                    'name' => $r->stockItem?->name,
                    // ส่งเป็นชื่อไทยของหน่วยฐาน ไม่ใช่ค่าดิบอย่าง "g"
                    'unit' => $r->stockItem?->unitLabel(),
                    'qty' => (float) $r->qty,
                    // ต้นทุนเป็นของรายสาขา จึงอ่านจากตารางของสาขา ไม่ใช่จากแม่แบบกลาง
                    'cost_per_unit' => (float) ($costs[$r->stock_item_id] ?? 0),
                    'line_cost' => round((float) $r->qty * (float) ($costs[$r->stock_item_id] ?? 0), 2),
                ]),
            ]);

        return Inertia::render('BackOffice/Recipes/Index', [
            'products' => $products,
            // ของที่สาขานี้ใช้ได้ = ของกลาง + ของเฉพาะสาขานี้
            'stockItems' => StockItem::forCatalog($branchId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'unit', 'branch_id'])
                ->map(fn (StockItem $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'unit' => $i->unitLabel(),
                    'is_central' => $i->branch_id === null,
                    'cost_per_unit' => (float) ($costs[$i->id] ?? 0),
                ]),
            'filters' => $request->only('search'),
        ]);
    }

    /** บันทึกสูตรทั้งชุดของเมนูหนึ่ง */
    public function update(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->branch_id === null || $product->branch_id === CurrentBranch::id(), 403);

        $data = $request->validate([
            'items' => ['array', 'max:40'],
            'items.*.stock_item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'track_stock' => ['boolean'],
            'sync_cost' => ['boolean'],
        ]);

        $branchId = CurrentBranch::id();

        DB::transaction(function () use ($product, $data, $branchId) {
            // ลบเฉพาะสูตรของสาขานี้ สาขาอื่นเขียนสูตรของตัวเองไว้ ห้ามล้างทิ้ง
            $product->recipeItems()->where('branch_id', $branchId)->delete();

            // ของกลางใช้ได้ทุกสาขา ส่วนของเฉพาะสาขาอื่นห้ามผูก
            $allowed = StockItem::forCatalog($branchId)
                ->whereIn('id', array_column($data['items'] ?? [], 'stock_item_id'))
                ->pluck('id')
                ->all();

            foreach ($data['items'] ?? [] as $item) {
                if (! in_array($item['stock_item_id'], $allowed, true)) {
                    continue;
                }

                $product->recipeItems()->create([
                    'stock_item_id' => $item['stock_item_id'],
                    'qty' => $item['qty'],
                ]);
            }

            $product->track_stock = (bool) ($data['track_stock'] ?? false);

            // อัปเดตต้นทุนจากสูตรก็ต่อเมื่อผู้ใช้สั่ง
            // เพราะบางร้านอยากกรอกต้นทุนเองเพื่อกันเผื่อของเสีย
            if ($data['sync_cost'] ?? false) {
                $product->load(['recipeItems' => fn ($q) => $q->where('branch_id', $branchId)]);
                $product->cost = $product->recipeCost();
            }

            $product->save();
        });

        return back()->with('success', 'บันทึกสูตรของ '.$product->name.' แล้ว');
    }

    /** ซิงก์ต้นทุนจากสูตรให้ทุกเมนูที่มีสูตรในคราวเดียว */
    public function syncCosts(): RedirectResponse
    {
        $branchId = CurrentBranch::id();

        $products = Product::with(['recipeItems' => fn ($q) => $q->where('branch_id', $branchId)])
            ->forCatalog($branchId)
            ->whereHas('recipeItems', fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        $updated = 0;

        foreach ($products as $product) {
            $cost = $product->recipeCost();

            if ((float) $product->cost !== $cost) {
                $product->update(['cost' => $cost]);
                $updated++;
            }
        }

        return back()->with('success', "อัปเดตต้นทุนจากสูตรแล้ว {$updated} เมนู");
    }
}
