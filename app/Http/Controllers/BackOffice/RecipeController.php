<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
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

        // สูตรแยกรายสาขา — ลากมาเฉพาะของสาขานี้ ไม่งั้นเมนูกลางจะโชว์สูตรของทุกสาขาปนกัน
        $products = Product::with([
            'category:id,name,color',
            'recipeItems' => fn ($q) => $q->where('branch_id', $branchId)->with('ingredient:id,name,unit,cost_per_unit'),
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
                    'ingredient_id' => $r->ingredient_id,
                    'name' => $r->ingredient?->name,
                    // ส่งเป็นชื่อไทยของหน่วยฐาน ไม่ใช่ค่าดิบอย่าง "g"
                    'unit' => $r->ingredient?->unitLabel(),
                    'qty' => (float) $r->qty,
                    'cost_per_unit' => (float) ($r->ingredient?->cost_per_unit ?? 0),
                    'line_cost' => round((float) $r->qty * (float) ($r->ingredient?->cost_per_unit ?? 0), 2),
                ]),
            ]);

        return Inertia::render('BackOffice/Recipes/Index', [
            'products' => $products,
            'ingredients' => Ingredient::where('branch_id', $branchId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'unit', 'cost_per_unit'])
                ->map(fn (Ingredient $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'unit' => $i->unitLabel(),
                    'cost_per_unit' => (float) $i->cost_per_unit,
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
            'items.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'track_stock' => ['boolean'],
            'sync_cost' => ['boolean'],
        ]);

        $branchId = CurrentBranch::id();

        DB::transaction(function () use ($product, $data, $branchId) {
            // ลบเฉพาะสูตรของสาขานี้ สาขาอื่นเขียนสูตรของตัวเองไว้ ห้ามล้างทิ้ง
            $product->recipeItems()->where('branch_id', $branchId)->delete();

            $allowed = Ingredient::where('branch_id', $branchId)
                ->whereIn('id', array_column($data['items'] ?? [], 'ingredient_id'))
                ->pluck('id')
                ->all();

            foreach ($data['items'] ?? [] as $item) {
                // กันผูกวัตถุดิบข้ามสาขา
                if (! in_array($item['ingredient_id'], $allowed, true)) {
                    continue;
                }

                $product->recipeItems()->create([
                    'ingredient_id' => $item['ingredient_id'],
                    'qty' => $item['qty'],
                ]);
            }

            $product->track_stock = (bool) ($data['track_stock'] ?? false);

            // อัปเดตต้นทุนจากสูตรก็ต่อเมื่อผู้ใช้สั่ง
            // เพราะบางร้านอยากกรอกต้นทุนเองเพื่อกันเผื่อของเสีย
            if ($data['sync_cost'] ?? false) {
                $product->load(['recipeItems' => fn ($q) => $q->where('branch_id', $branchId)->with('ingredient')]);
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

        $products = Product::with(['recipeItems' => fn ($q) => $q->where('branch_id', $branchId)->with('ingredient')])
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
