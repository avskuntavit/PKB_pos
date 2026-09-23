<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\StockMovementType;
use App\Enums\StockUnit;
use App\Http\Controllers\Controller;
use App\Models\StockItem;
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
 * คลัง / สต๊อก
 *
 * ── ของเป็นกลาง แต่ยอดคงเหลือแยกสาขา ───────────────────────
 * `stock_items` เป็นแม่แบบ (ชื่อ หน่วย หน่วยซื้อ) — `branch_id = NULL` คือของกลาง
 * `branch_stock_items` เก็บยอดคงเหลือ ต้นทุนเฉลี่ย และจุดสั่งซื้อของแต่ละสาขา
 *
 * หน้านี้จึงแสดง "ของที่สาขานี้ใช้ได้" = ของกลาง + ของเฉพาะสาขานี้
 * พร้อมตัวเลขของสาขาที่กำลังดูอยู่
 *
 * ── ใครแก้อะไรได้ ──────────────────────────────────────────
 * ของกลางแก้ได้เฉพาะเจ้าของระบบ (กติกาเดียวกับเมนูกลาง)
 * ผู้จัดการสาขาสร้างของของสาขาตัวเองได้ และปรับยอด/ต้นทุน/จุดสั่งซื้อของสาขาตัวเองได้เสมอ
 * แม้ของนั้นจะเป็นของกลาง — เพราะตัวเลขพวกนั้นเป็นของสาขาอยู่แล้ว
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

        $items = StockItem::forCatalog($branchId)
            ->with(['branchStock' => fn ($q) => $q->where('branch_id', $branchId)])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            /*
            | "ใกล้หมด" ต้องดูที่ยอดของสาขา ไม่ใช่ที่แม่แบบ
            | ของที่สาขานี้ยังไม่เคยแตะจะไม่มีแถวใน branch_stock_items เลย
            | จึงไม่นับว่าใกล้หมด — ยังไม่ได้เริ่มใช้ ไม่ใช่ของหมด
            */
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereHas(
                'branchStock',
                fn ($s) => $s->where('branch_id', $branchId)->low(),
            ))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (StockItem $i) => $this->itemPayload($i, $branchId));

        $movements = StockMovement::with('stockItem:id,name,unit')
            ->where('branch_id', $branchId)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'stock_item_id' => $m->stock_item_id,
                'stock_item' => $m->stockItem ? [
                    'id' => $m->stockItem->id,
                    'name' => $m->stockItem->name,
                    'unit' => $m->stockItem->unit->value,
                    'unit_label' => $m->stockItem->unitLabel(),
                    'unit_decimals' => $m->stockItem->unit->decimals(),
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
            'stockItems' => $items,
            'movements' => $movements,
            'filters' => ['from' => $from, 'to' => $to] + $request->only('search', 'low_stock'),
            'units' => StockUnit::options(),
            'canEditCentral' => (bool) $request->user()?->isOwner(),
            'movementTypes' => collect(StockMovementType::cases())
                ->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
        ]);
    }

    /* ---------- แม่แบบของในคลัง ---------- */

    public function store(Request $request, StockService $stock, ActivityLogger $logger): RedirectResponse
    {
        $central = $request->boolean('is_central');

        // ของกลางใช้ร่วมกันทุกสาขา จึงให้เจ้าของระบบเป็นคนสร้างเท่านั้น
        abort_if($central && ! $request->user()?->isOwner(), 403, 'ของกลางสร้างได้เฉพาะเจ้าของระบบ');

        $data = $this->validatedItem($request, central: $central);
        $branchId = CurrentBranch::id();

        $item = StockItem::create($data['item'] + ['branch_id' => $central ? null : $branchId]);

        // ยอดตั้งต้นและค่าตั้งต้นของสาขาบันทึกผ่าน StockService ด้วย ไม่ยัดลงคอลัมน์ตรง ๆ
        // เพื่อให้มีรอยในบัญชีความเคลื่อนไหวว่าของเข้ามาตอนไหน เท่าไร
        $this->saveBranchSettings($item, $branchId, $data['branch']);

        if (($opening = (float) $request->input('opening_qty', 0)) != 0.0) {
            $stock->move(
                $item,
                $branchId,
                StockMovementType::Adjust,
                $opening,
                $data['branch']['cost_per_unit'] ?? null,
                note: 'ยอดตั้งต้นตอนเพิ่มของเข้าคลัง',
            );
        }

        $logger->log('inventory.item', $item, ['mode' => 'create', 'central' => $central]);

        return back()->with('success', 'เพิ่มของเข้าคลังแล้ว');
    }

    public function update(Request $request, StockItem $item, ActivityLogger $logger): RedirectResponse
    {
        $this->guardUse($item);

        $branchId = CurrentBranch::id();
        $data = $this->validatedItem($request, central: $item->branch_id === null, ignoreId: $item->id);

        /*
        | แก้แม่แบบ (ชื่อ/หน่วย/หน่วยซื้อ) ได้เฉพาะคนที่มีสิทธิ์กับแม่แบบนั้น
        | แต่ค่าของสาขา (ต้นทุน จุดสั่งซื้อ เปิด/ปิด) ผู้จัดการสาขาแก้ได้เสมอ
        | เพราะเป็นตัวเลขของสาขาตัวเอง ไม่กระทบสาขาอื่น
        */
        if ($this->canEditMaster($item)) {
            // เปลี่ยนหน่วยฐานทีหลังไม่ได้ ถ้ามีสูตรผูกอยู่แล้ว
            // เพราะตัวเลขในสูตรถูกเขียนด้วยหน่วยเดิม เปลี่ยนแล้วปริมาณจะเพี้ยนทันที
            $hasRecipes = $item->recipeItems()->exists() || $item->modifierRecipeItems()->exists();

            if ($hasRecipes && $data['item']['unit'] !== $item->unit->value) {
                return back()->with('error', 'เปลี่ยนหน่วยไม่ได้ — มีสูตรใช้ของชิ้นนี้อยู่ ตัวเลขในสูตรจะเพี้ยน');
            }

            $item->update($data['item']);
        }

        // ยอดคงเหลือแก้ตรงนี้ไม่ได้ ต้องผ่านหน้าบันทึกสต๊อกเพื่อให้มีรอยเสมอ
        $this->saveBranchSettings($item, $branchId, $data['branch']);

        $logger->log('inventory.item', $item, ['mode' => 'update']);

        return back()->with('success', 'บันทึกการแก้ไขแล้ว');
    }

    public function destroy(StockItem $item, ActivityLogger $logger): RedirectResponse
    {
        $this->guardUse($item);

        abort_unless($this->canEditMaster($item), 403, 'ของกลางลบได้เฉพาะเจ้าของระบบ');

        $used = $item->recipeItems()->count() + $item->modifierRecipeItems()->count();

        if ($used > 0) {
            return back()->with('error', "ลบไม่ได้ — ยังมีสูตรหรือตัวเลือกใช้ของชิ้นนี้อยู่ {$used} รายการ");
        }

        $item->delete();
        $logger->log('inventory.item', $item, ['mode' => 'delete']);

        return back()->with('success', 'ลบของออกจากคลังแล้ว');
    }

    /* ---------- ความเคลื่อนไหว ---------- */

    /** เติมของ / ตัดของเสีย / ปรับยอด */
    public function move(Request $request, StockService $stock): RedirectResponse
    {
        $data = $request->validate([
            'stock_item_id' => ['required', 'exists:stock_items,id'],
            'type' => ['required', 'in:purchase,waste,adjust'],
            'qty' => ['required', 'numeric', 'not_in:0'],
            // รับของกรอกเป็นหน่วยซื้อได้ เช่น 2 กก.
            'use_purchase_unit' => ['boolean'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $item = StockItem::findOrFail($data['stock_item_id']);
        $this->guardUse($item);

        $branchId = CurrentBranch::id();
        $type = StockMovementType::from($data['type']);

        // รับของเข้าด้วยหน่วยซื้อ — แปลงจำนวนและหารราคาให้เอง
        if ($type === StockMovementType::Purchase
            && $request->boolean('use_purchase_unit')
            && $item->hasPurchaseUnit()) {
            $stock->receive(
                $item,
                $branchId,
                abs((float) $data['qty']),
                $data['unit_cost'] ?? null,
                $data['note'] ?? null,
            );

            return back()->with('success', 'รับของเข้าคลังแล้ว');
        }

        // ของเสียต้องเป็นค่าติดลบเสมอ กันกรอกผิดทิศ
        $qty = $type === StockMovementType::Waste ? -abs($data['qty']) : $data['qty'];

        $stock->move($item, $branchId, $type, (float) $qty, $data['unit_cost'] ?? null, note: $data['note'] ?? null);

        return back()->with('success', 'บันทึกความเคลื่อนไหวสต๊อกแล้ว');
    }

    /* ---------- ตัวช่วย ---------- */

    /** @return array<string, mixed> */
    protected function itemPayload(StockItem $item, ?int $branchId): array
    {
        $stock = $item->stockAt($branchId);

        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'is_central' => $item->branch_id === null,
            'unit' => $item->unit->value,
            'unit_label' => $item->unitLabel(),
            'unit_decimals' => $item->unit->decimals(),
            'purchase_unit' => $item->purchase_unit,
            'purchase_factor' => (float) $item->purchase_factor,
            'purchase_unit_label' => $item->purchaseUnitLabel(),
            'has_purchase_unit' => $item->hasPurchaseUnit(),
            // ตัวเลขทั้งสามบรรทัดนี้เป็นของสาขาที่กำลังดูอยู่ ไม่ใช่ของกลาง
            'stock_qty' => (float) $stock->stock_qty,
            'cost_per_unit' => (float) $stock->cost_per_unit,
            'reorder_level' => (float) $stock->reorder_level,
            'stock_value' => $stock->value(),
            'is_low' => $stock->isLow(),
            'is_active' => (bool) $item->is_active,
            'used_here' => (bool) $stock->is_active,
            'can_edit_master' => $this->canEditMaster($item),
            // ใช้ตัดสินว่าลบได้ไหม — ของที่มีสูตรใช้อยู่ ลบแล้วสูตรพัง
            'in_use' => $item->recipeItems()->count() + $item->modifierRecipeItems()->count(),
        ];
    }

    /** บันทึกค่าที่เป็นของสาขา — ไม่แตะยอดคงเหลือ */
    protected function saveBranchSettings(StockItem $item, ?int $branchId, array $values): void
    {
        $stock = $item->stockAt($branchId);

        $stock->fill([
            'branch_id' => $branchId,
            'stock_item_id' => $item->id,
            'reorder_level' => $values['reorder_level'],
            'is_active' => $values['used_here'] ?? true,
        ]);

        // ต้นทุนตั้งต้นใส่ได้เฉพาะตอนที่สาขายังไม่มีของชิ้นนี้เลย
        // หลังจากนั้นต้นทุนมาจากการรับของ (ถัวเฉลี่ยถ่วงน้ำหนัก) เท่านั้น
        if (! $stock->exists && isset($values['cost_per_unit'])) {
            $stock->cost_per_unit = $values['cost_per_unit'];
        }

        $stock->save();
    }

    /**
     * @return array{item: array<string, mixed>, branch: array<string, mixed>}
     */
    protected function validatedItem(Request $request, bool $central, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => [
                'nullable', 'string', 'max:40',
                // unique(branch_key, code) — ของกลางใช้ branch_key = 0
                Rule::unique('stock_items', 'code')
                    ->where('branch_key', $central ? 0 : (CurrentBranch::id() ?? 0))
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', Rule::enum(StockUnit::class)],
            'purchase_unit' => ['nullable', 'string', 'max:30'],
            'purchase_factor' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'cost_per_unit' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'used_here' => ['boolean'],
        ]);

        return [
            'item' => [
                'code' => $data['code'] ?? null,
                'name' => $data['name'],
                'unit' => $data['unit'],
                'purchase_unit' => $data['purchase_unit'] ?? null,
                'purchase_factor' => $data['purchase_factor'],
                'is_active' => $data['is_active'] ?? true,
            ],
            'branch' => [
                'cost_per_unit' => $data['cost_per_unit'],
                'reorder_level' => $data['reorder_level'],
                'used_here' => $data['used_here'] ?? true,
            ],
        ];
    }

    /** สาขานี้เห็นของชิ้นนี้ไหม — ของกลางทุกสาขาเห็น */
    protected function guardUse(StockItem $item): void
    {
        abort_unless(
            $item->branch_id === null || $item->branch_id === CurrentBranch::id(),
            403,
            'ของชิ้นนี้เป็นของสาขาอื่น',
        );
    }

    /** แก้ตัวแม่แบบได้ไหม — ของกลางแก้ได้เฉพาะเจ้าของระบบ */
    protected function canEditMaster(StockItem $item): bool
    {
        return $item->branch_id === null
            ? (bool) auth()->user()?->isOwner()
            : $item->branch_id === CurrentBranch::id();
    }

    protected function dateRange(Request $request): array
    {
        return [
            $request->date('from')?->toDateString() ?? now()->subDays(7)->toDateString(),
            $request->date('to')?->toDateString() ?? now()->toDateString(),
        ];
    }
}
