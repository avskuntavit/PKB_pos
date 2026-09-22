<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\StockMovementType;
use App\Enums\StockUnit;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use App\Services\StockService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * คลัง/สต๊อก
 *
 * ยอดคงเหลือและสูตรอยู่ใน "หน่วยฐาน" เสมอ (กรัม / มล. / ชิ้น)
 * ส่วน "หน่วยซื้อ" ใช้เฉพาะตอนรับของ เพื่อให้กรอก 2 กก. แทน 2000 กรัมได้
 */
class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = CurrentBranch::id();
        [$from, $to] = $this->dateRange($request);

        $ingredients = Ingredient::where('branch_id', $branchId)
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereColumn('stock_qty', '<=', 'reorder_level'))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Ingredient $i) => [
                'id' => $i->id,
                'code' => $i->code,
                'name' => $i->name,
                'unit' => $i->unit->value,
                'unit_label' => $i->unitLabel(),
                'unit_decimals' => $i->unit->decimals(),
                'purchase_unit' => $i->purchase_unit,
                'purchase_factor' => (float) $i->purchase_factor,
                'purchase_unit_label' => $i->purchaseUnitLabel(),
                'has_purchase_unit' => $i->hasPurchaseUnit(),
                'stock_qty' => (float) $i->stock_qty,
                'cost_per_unit' => (float) $i->cost_per_unit,
                'reorder_level' => (float) $i->reorder_level,
                'stock_value' => $i->stockValue(),
                'is_low' => $i->isBelowReorderLevel(),
                'is_active' => $i->is_active,
                // ใช้ตัดสินว่าลบได้ไหม — ของที่มีสูตรใช้อยู่ ลบแล้วสูตรพัง
                'in_use' => $i->recipeItems()->count() + $i->modifierRecipeItems()->count(),
            ]);

        $movements = StockMovement::with('ingredient:id,name,unit')
            ->where('branch_id', $branchId)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'ingredient_id' => $m->ingredient_id,
                'ingredient' => $m->ingredient ? [
                    'id' => $m->ingredient->id,
                    'name' => $m->ingredient->name,
                    'unit' => $m->ingredient->unit->value,
                    'unit_label' => $m->ingredient->unitLabel(),
                    'unit_decimals' => $m->ingredient->unit->decimals(),
                ] : null,
                'type' => $m->type->value,
                'type_label' => $m->type->label(),
                'qty' => (float) $m->qty,
                'cost' => (float) $m->cost,
                'balance_after' => (float) $m->balance_after,
                'note' => $m->note,
                'business_date' => $m->business_date?->toDateString(),
                'occurred_at' => $m->occurred_at?->toISOString() ?? (string) $m->occurred_at,
            ]);

        return Inertia::render('BackOffice/Inventory/Index', [
            'ingredients' => $ingredients,
            'movements' => $movements,
            'filters' => ['from' => $from, 'to' => $to] + $request->only('search', 'low_stock'),
            'units' => StockUnit::options(),
            'movementTypes' => collect(StockMovementType::cases())
                ->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
        ]);
    }

    /* ---------- วัตถุดิบ ---------- */

    public function store(Request $request, StockService $stock, ActivityLogger $logger): RedirectResponse
    {
        $data = $this->validatedIngredient($request);
        $opening = (float) $request->input('opening_qty', 0);

        $ingredient = Ingredient::create($data + [
            'branch_id' => CurrentBranch::id(),
            'stock_qty' => 0,
        ]);

        // ยอดตั้งต้นบันทึกผ่าน StockService ด้วย ไม่ยัดลงคอลัมน์ตรง ๆ
        // เพื่อให้มีรอยในบัญชีความเคลื่อนไหวว่าของเข้ามาตอนไหน เท่าไร
        if ($opening != 0.0) {
            $stock->move(
                $ingredient,
                StockMovementType::Adjust,
                $opening,
                $data['cost_per_unit'] ?? null,
                note: 'ยอดตั้งต้นตอนเพิ่มวัตถุดิบ',
            );
        }

        $logger->log('inventory.item', $ingredient, ['mode' => 'create']);

        return back()->with('success', 'เพิ่มวัตถุดิบแล้ว');
    }

    public function update(Request $request, Ingredient $ingredient, ActivityLogger $logger): RedirectResponse
    {
        $this->guard($ingredient);

        $data = $this->validatedIngredient($request, $ingredient->id);

        // เปลี่ยนหน่วยฐานทีหลังไม่ได้ ถ้ามีสูตรผูกอยู่แล้ว
        // เพราะตัวเลขในสูตรถูกเขียนด้วยหน่วยเดิม เปลี่ยนแล้วปริมาณจะเพี้ยนทันที
        $hasRecipes = $ingredient->recipeItems()->exists() || $ingredient->modifierRecipeItems()->exists();

        if ($hasRecipes && $data['unit'] !== $ingredient->unit->value) {
            return back()->with('error', 'เปลี่ยนหน่วยไม่ได้ — มีสูตรใช้วัตถุดิบนี้อยู่ ตัวเลขในสูตรจะเพี้ยน');
        }

        // ยอดคงเหลือแก้ตรงนี้ไม่ได้ ต้องผ่านหน้าบันทึกสต๊อกเพื่อให้มีรอยเสมอ
        $ingredient->update($data);
        $logger->log('inventory.item', $ingredient, ['mode' => 'update']);

        return back()->with('success', 'บันทึกการแก้ไขแล้ว');
    }

    public function destroy(Ingredient $ingredient, ActivityLogger $logger): RedirectResponse
    {
        $this->guard($ingredient);

        $used = $ingredient->recipeItems()->count() + $ingredient->modifierRecipeItems()->count();

        if ($used > 0) {
            return back()->with('error', "ลบไม่ได้ — ยังมีสูตรหรือตัวเลือกใช้วัตถุดิบนี้อยู่ {$used} รายการ");
        }

        $ingredient->delete();
        $logger->log('inventory.item', $ingredient, ['mode' => 'delete']);

        return back()->with('success', 'ลบวัตถุดิบแล้ว');
    }

    /* ---------- ความเคลื่อนไหว ---------- */

    /** เติมของ / ตัดของเสีย / ปรับยอด */
    public function move(Request $request, StockService $stock): RedirectResponse
    {
        $data = $request->validate([
            'ingredient_id' => ['required', 'exists:ingredients,id'],
            'type' => ['required', 'in:purchase,waste,adjust'],
            'qty' => ['required', 'numeric', 'not_in:0'],
            // รับของกรอกเป็นหน่วยซื้อได้ เช่น 2 กก.
            'use_purchase_unit' => ['boolean'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $ingredient = Ingredient::findOrFail($data['ingredient_id']);
        $this->guard($ingredient);

        $type = StockMovementType::from($data['type']);

        // รับของเข้าด้วยหน่วยซื้อ — แปลงจำนวนและหารราคาให้เอง
        if ($type === StockMovementType::Purchase
            && $request->boolean('use_purchase_unit')
            && $ingredient->hasPurchaseUnit()) {
            $stock->receive(
                $ingredient,
                abs((float) $data['qty']),
                $data['unit_cost'] ?? null,
                $data['note'] ?? null,
            );

            return back()->with('success', 'รับของเข้าคลังแล้ว');
        }

        // ของเสียต้องเป็นค่าติดลบเสมอ กันกรอกผิดทิศ
        $qty = $type === StockMovementType::Waste ? -abs($data['qty']) : $data['qty'];

        $stock->move($ingredient, $type, (float) $qty, $data['unit_cost'] ?? null, note: $data['note'] ?? null);

        return back()->with('success', 'บันทึกความเคลื่อนไหวสต๊อกแล้ว');
    }

    /* ---------- ตัวช่วย ---------- */

    protected function validatedIngredient(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                'nullable', 'string', 'max:40',
                Rule::unique('ingredients', 'code')
                    ->where('branch_id', CurrentBranch::id())
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', Rule::enum(StockUnit::class)],
            'purchase_unit' => ['nullable', 'string', 'max:30'],
            'purchase_factor' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'cost_per_unit' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }

    protected function guard(Ingredient $ingredient): void
    {
        abort_unless($ingredient->branch_id === CurrentBranch::id(), 403);
    }

    protected function dateRange(Request $request): array
    {
        return [
            $request->date('from')?->toDateString() ?? now()->subDays(7)->toDateString(),
            $request->date('to')?->toDateString() ?? now()->toDateString(),
        ];
    }
}
