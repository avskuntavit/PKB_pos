<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Services\ActivityLogger;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * เซ็ตตัวเลือก — สร้างแยกไว้ครั้งเดียว แล้วเอาไปแปะเมนูไหนก็ได้
 *
 *   "ก๋วยเตี๋ยว - เพิ่มเติม"  หน้าบ้านเห็น "เพิ่มเติม"  ธรรมดา(×1) / พิเศษ(×1.5) / จัมโบ้(×2)
 *   "กระเพรา - เพิ่มเติม"    หน้าบ้านเห็น "เพิ่มเติม"  ธรรมดา / เพิ่มหมู
 *
 * ตัวเลือกหนึ่งอันมีผลกับสต๊อกได้ทางเดียว — ปรับขนาดจาน (ตัวคูณ) หรือ เพิ่มวัตถุดิบ
 * ใช้พร้อมกันจะคูณซ้อนแล้วตัดสต๊อกเกินจริง ระบบกันไว้ทั้งสองทาง
 *
 * ปิดใช้งานได้ 2 ระดับ
 *   modifier_groups.is_active         ปิดทั้งเซ็ต หายจากทุกเมนู
 *   modifier_group_product.is_active  ปิดเฉพาะเมนูนั้น เมนูอื่นยังใช้ได้
 */
class ModifierController extends Controller
{
    public function index(): Response
    {
        $branchId = CurrentBranch::id();

        // เซ็ตกลาง + เซ็ตของสาขานี้ / สูตรของตัวเลือกลากมาเฉพาะของสาขานี้
        $groups = ModifierGroup::query()
            ->forCatalog($branchId)
            ->with([
                'modifiers.recipeItems' => fn ($q) => $q->where('branch_id', $branchId)->with('stockItem:id,name,unit'),
                'products:id',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ModifierGroup $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'display_name' => $g->display_name,
                'shown_as' => $g->displayName(),
                'min_select' => $g->min_select,
                'max_select' => $g->max_select,
                'is_required' => $g->is_required,
                'is_active' => $g->is_active,
                'sort_order' => $g->sort_order,
                // เมนูที่ผูกไว้ พร้อมสถานะเปิด/ปิดรายเมนู
                'links' => $g->products->map(fn (Product $p) => [
                    'product_id' => $p->id,
                    'is_active' => (bool) $p->pivot->is_active,
                ])->values(),
                'active_links_count' => $g->products->filter(fn (Product $p) => (bool) $p->pivot->is_active)->count(),
                'modifiers' => $g->modifiers->map(fn (Modifier $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'price_delta' => (float) $m->price_delta,
                    'portion_multiplier' => (float) $m->portion_multiplier,
                    'scales_with_portion' => $m->scales_with_portion,
                    'is_default' => $m->is_default,
                    'is_active' => $m->is_active,
                    'marks_takeaway' => $m->marks_takeaway,
                    'recipe' => $m->recipeItems->map(fn ($r) => [
                        'stock_item_id' => $r->stock_item_id,
                        'name' => $r->stockItem?->name,
                        'unit' => $r->stockItem?->unitLabel(),
                        'qty' => (float) $r->qty,
                    ])->values(),
                ])->values(),
            ]);

        return Inertia::render('BackOffice/Modifiers/Index', [
            'groups' => $groups,
            'products' => Product::forCatalog($branchId)
                ->with('category:id,name')
                ->orderBy('sort_order')
                ->get(['id', 'name', 'category_id'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'category_name' => $p->category?->name,
                ]),
            // ของที่สาขานี้ใช้ได้ = ของกลาง + ของเฉพาะสาขานี้
            'stockItems' => StockItem::forCatalog($branchId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'unit'])
                ->map(fn (StockItem $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'unit' => $i->unitLabel(),
                ]),
        ]);
    }

    /* ---------- กลุ่ม ---------- */

    public function storeGroup(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $group = ModifierGroup::create($this->validatedGroup($request) + [
            'branch_id' => CurrentBranch::id(),
        ]);

        $logger->log('modifier.group', $group, ['mode' => 'create']);

        return back()->with('success', 'สร้างเซ็ตตัวเลือกแล้ว');
    }

    public function updateGroup(Request $request, ModifierGroup $group, ActivityLogger $logger): RedirectResponse
    {
        $this->guardGroup($group);

        $group->update($this->validatedGroup($request));
        $logger->log('modifier.group', $group, ['mode' => 'update']);

        return back()->with('success', 'บันทึกเซ็ตตัวเลือกแล้ว');
    }

    public function destroyGroup(ModifierGroup $group, ActivityLogger $logger): RedirectResponse
    {
        $this->guardGroup($group);

        if ($group->products()->exists()) {
            return back()->with('error', 'ลบไม่ได้ — ยังมีเมนูใช้เซ็ตนี้อยู่ ให้เอาออกจากเมนูก่อน (ปิดเซ็ตแทนได้)');
        }

        $group->delete();
        $logger->log('modifier.group', $group, ['mode' => 'delete']);

        return back()->with('success', 'ลบเซ็ตตัวเลือกแล้ว');
    }

    /**
     * เลือกว่าเซ็ตนี้ใช้กับเมนูไหนบ้าง — ฝั่งเซ็ตมองออกไปหาเมนู
     *
     * ส่ง items มาเป็นรายการที่ "ผูกไว้" ทั้งหมด เมนูที่ไม่อยู่ในนี้ถือว่าถอดออก
     * is_active ในแต่ละแถวคือปิดเฉพาะเมนูนั้น (ยังผูกอยู่ แต่ไม่โผล่หน้าขาย)
     */
    public function syncProducts(Request $request, ModifierGroup $group, ActivityLogger $logger): RedirectResponse
    {
        $this->guardGroup($group);

        $own = Product::forCatalog(CurrentBranch::id())->pluck('id')->all();

        $data = $request->validate([
            'items' => ['present', 'array', 'max:500'],
            'items.*.product_id' => ['required', Rule::in($own)],
            'items.*.is_active' => ['boolean'],
        ]);

        $sync = [];

        foreach ($data['items'] as $row) {
            // ซ้ำกันก็ทับ key เดิม ไม่ทำให้ sync พัง
            $sync[(int) $row['product_id']] = ['is_active' => (bool) ($row['is_active'] ?? true)];
        }

        $group->products()->sync($sync);
        $logger->log('modifier.group', $group, ['mode' => 'products', 'count' => count($sync)]);

        return back()->with('success', 'ผูกเซ็ตตัวเลือกกับเมนูแล้ว');
    }

    /* ---------- ตัวเลือก ---------- */

    public function storeModifier(Request $request, ModifierGroup $group, ActivityLogger $logger): RedirectResponse
    {
        $this->guardGroup($group);

        $modifier = $group->modifiers()->create($this->validatedModifier($request) + [
            'sort_order' => (int) $group->modifiers()->max('sort_order') + 1,
        ]);

        $logger->log('modifier.item', $modifier, ['mode' => 'create']);

        return back()->with('success', 'เพิ่มตัวเลือกแล้ว');
    }

    public function updateModifier(Request $request, Modifier $modifier, ActivityLogger $logger): RedirectResponse
    {
        $this->guardGroup($modifier->group);

        $data = $this->validatedModifier($request);

        // ตัวเลือกที่ผูกวัตถุดิบไว้แล้ว ตั้งตัวคูณไม่ได้ ไม่งั้นคูณซ้อน
        if ((float) $data['portion_multiplier'] != 1.0
            && $modifier->recipeItems()->where('branch_id', CurrentBranch::id())->exists()) {
            return back()->with('error', 'ตัวเลือกนี้ผูกวัตถุดิบไว้แล้ว ตั้งตัวคูณขนาดพร้อมกันไม่ได้');
        }

        $modifier->update($data);
        $logger->log('modifier.item', $modifier, ['mode' => 'update']);

        return back()->with('success', 'บันทึกตัวเลือกแล้ว');
    }

    public function destroyModifier(Modifier $modifier, ActivityLogger $logger): RedirectResponse
    {
        $this->guardGroup($modifier->group);

        $modifier->delete();
        $logger->log('modifier.item', $modifier, ['mode' => 'delete']);

        return back()->with('success', 'ลบตัวเลือกแล้ว');
    }

    /** ของในคลังที่ตัวเลือกนี้เพิ่มเข้าไป */
    public function saveRecipe(Request $request, Modifier $modifier, ActivityLogger $logger): RedirectResponse
    {
        $this->guardGroup($modifier->group, recipeOnly: true);

        // ของกลางใช้ได้ทุกสาขา ส่วนของเฉพาะสาขาอื่นห้ามผูก
        $usable = StockItem::forCatalog(CurrentBranch::id())->pluck('id')->all();

        $data = $request->validate([
            'items' => ['present', 'array', 'max:20'],
            'items.*.stock_item_id' => ['required', Rule::in($usable)],
            'items.*.qty' => ['required', 'numeric', 'not_in:0'],
        ]);

        if ($data['items'] && $modifier->changesPortion()) {
            return back()->with('error', 'ตัวเลือกนี้ตั้งตัวคูณขนาดไว้แล้ว ใส่ของเพิ่มไม่ได้ (จะคูณซ้อน)');
        }

        $ids = array_column($data['items'], 'stock_item_id');

        if (count($ids) !== count(array_unique($ids))) {
            return back()->with('error', 'มีของซ้ำกัน — รวมเป็นบรรทัดเดียว');
        }

        DB::transaction(function () use ($modifier, $data) {
            // ลบเฉพาะสูตรของสาขานี้ — สาขาอื่นใส่ปริมาณของตัวเองไว้
            $modifier->recipeItems()->where('branch_id', CurrentBranch::id())->delete();

            foreach ($data['items'] as $row) {
                $modifier->recipeItems()->create([
                    'stock_item_id' => $row['stock_item_id'],
                    'qty' => $row['qty'],
                ]);
            }
        });

        $logger->log('modifier.item', $modifier, ['mode' => 'recipe', 'lines' => count($data['items'])]);

        return back()->with('success', 'บันทึกของที่ตัวเลือกนี้ใช้แล้ว');
    }

    /* ---------- ตัวช่วย ---------- */

    protected function validatedGroup(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:100'],
            // lte กัน "เลือกอย่างน้อย 3 แต่ได้มากสุด 1" ที่ทำให้สั่งไม่ได้เลย
            'min_select' => ['required', 'integer', 'min:0', 'max:20', 'lte:max_select'],
            'max_select' => ['required', 'integer', 'min:1', 'max:20'],
            'is_required' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'min_select.lte' => 'จำนวนขั้นต่ำต้องไม่มากกว่าจำนวนสูงสุด',
        ]);
    }

    protected function validatedModifier(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'price_delta' => ['required', 'numeric'],
            'portion_multiplier' => ['required', 'numeric', 'gt:0', 'max:20'],
            'scales_with_portion' => ['boolean'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'marks_takeaway' => ['boolean'],
        ]);
    }

    /** กันแตะกลุ่มของสาขาอื่น */
    /**
     * เซ็ตกลางแก้ได้เฉพาะเจ้าของ เพราะกระทบทุกสาขาพร้อมกัน
     *
     * ยกเว้นสูตรวัตถุดิบของตัวเลือก (saveRecipe) ซึ่งแยกรายสาขาอยู่แล้ว
     * ผู้จัดการจึงเขียนสูตรของสาขาตัวเองบนเซ็ตกลางได้
     */
    protected function guardGroup(?ModifierGroup $group, bool $recipeOnly = false): void
    {
        abort_if($group === null, 404);

        if ($group->branch_id === null) {
            abort_unless($recipeOnly || auth()->user()?->isOwner(), 403, 'เซ็ตตัวเลือกกลางแก้ได้เฉพาะเจ้าของ');

            return;
        }

        abort_unless($group->branch_id === CurrentBranch::id(), 403, 'เซ็ตตัวเลือกนี้เป็นของสาขาอื่น');
    }
}
