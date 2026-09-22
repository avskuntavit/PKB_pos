<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\PromotionReward;
use App\Enums\PromotionTrigger;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionItem;
use App\Services\ActivityLogger;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * โปรโมชั่นรายสาขา
 *
 * หน้าจอให้เลือกเป็น 5 แบบตามที่ร้านคิด แต่เก็บลงฐานข้อมูลเป็นสองแกน
 * (เงื่อนไข × รางวัล) — ดูเหตุผลในไฟล์ migration
 */
class PromotionController extends Controller
{
    public function index(): Response
    {
        $branchId = CurrentBranch::id();

        return Inertia::render('BackOffice/Promotions/Index', [
            'promotions' => Promotion::where('branch_id', $branchId)
                ->with('items')
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn (Promotion $p) => $this->payload($p)),

            // รายการให้เลือกในฟอร์ม — ส่งไปทั้งก้อนเพราะเมนูหลักร้อยรายการยังเบากว่ายิง ajax รายครั้ง
            'products' => Product::forCatalog($branchId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'category_id']),
            'categories' => Category::forCatalog($branchId)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name']),

            'triggerOptions' => PromotionTrigger::options(),
            'rewardOptions' => PromotionReward::options(),
        ]);
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $data = $this->validated($request);

        $promotion = DB::transaction(function () use ($data) {
            $promotion = Promotion::create($data['fields'] + ['branch_id' => CurrentBranch::id()]);
            $this->syncItems($promotion, $data);

            return $promotion;
        });

        $logger->log('promotion.update', $promotion, ['mode' => 'create']);

        return back()->with('success', 'สร้างโปรโมชั่นแล้ว');
    }

    public function update(Request $request, Promotion $promotion, ActivityLogger $logger): RedirectResponse
    {
        abort_unless($promotion->branch_id === CurrentBranch::id(), 403);

        $data = $this->validated($request);

        DB::transaction(function () use ($promotion, $data) {
            $promotion->update($data['fields']);
            $this->syncItems($promotion, $data);
        });

        $logger->log('promotion.update', $promotion, ['mode' => 'update']);

        return back()->with('success', 'บันทึกโปรโมชั่นแล้ว');
    }

    public function destroy(Promotion $promotion, ActivityLogger $logger): RedirectResponse
    {
        abort_unless($promotion->branch_id === CurrentBranch::id(), 403);

        // soft delete — บิลเก่าที่อ้างโปรใบนี้ต้องยังอ่านชื่อโปรได้ตอนดูรายงานย้อนหลัง
        $promotion->delete();

        $logger->log('promotion.update', $promotion, ['mode' => 'delete']);

        return back()->with('success', 'ลบโปรโมชั่นแล้ว');
    }

    /* ---------- ภายใน ---------- */

    /** รูปร่างที่หน้าจอใช้ — แยก items ออกเป็นสี่ก้อนให้ฟอร์มหยิบไปใช้ตรง ๆ */
    protected function payload(Promotion $promotion): array
    {
        $pick = fn (string $role, string $column) => $promotion->items
            ->where('role', $role)
            ->pluck($column)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'description' => $promotion->description,
            'trigger_type' => $promotion->trigger_type->value,
            'trigger_value' => (float) $promotion->trigger_value,
            'reward_type' => $promotion->reward_type->value,
            'reward_value' => (float) $promotion->reward_value,
            'free_qty' => (int) $promotion->free_qty,
            'max_discount' => $promotion->max_discount !== null ? (float) $promotion->max_discount : null,
            'max_rounds' => (int) $promotion->max_rounds,
            'starts_at' => $promotion->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $promotion->ends_at?->format('Y-m-d\TH:i'),
            'is_active' => (bool) $promotion->is_active,
            'sort_order' => (int) $promotion->sort_order,

            'trigger_products' => $pick(PromotionItem::ROLE_TRIGGER, 'product_id'),
            'trigger_categories' => $pick(PromotionItem::ROLE_TRIGGER, 'category_id'),
            'reward_products' => $pick(PromotionItem::ROLE_REWARD, 'product_id'),
            'reward_categories' => $pick(PromotionItem::ROLE_REWARD, 'category_id'),

            // ข้อความสรุปไว้โชว์ในตาราง คิดฝั่งเซิร์ฟเวอร์ที่เดียว หน้าจออื่นจะได้ไม่เขียนซ้ำ
            'condition_text' => $promotion->conditionText(),
            'reward_text' => $promotion->reward_type->label(),
        ];
    }

    /**
     * @return array{fields: array<string, mixed>, trigger_products: array<int>, trigger_categories: array<int>, reward_products: array<int>, reward_categories: array<int>}
     */
    protected function validated(Request $request): array
    {
        $branchId = CurrentBranch::id();

        // ผูก exists กับ branch_id ด้วย ไม่งั้นยิง id ของสาขาอื่นเข้ามาผูกโปรได้
        $central = fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId);

        $productRule = Rule::exists('products', 'id')->where($central);
        $categoryRule = Rule::exists('categories', 'id')->where($central);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],

            'trigger_type' => ['required', Rule::enum(PromotionTrigger::class)],
            'trigger_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],

            'reward_type' => ['required', Rule::enum(PromotionReward::class)],
            'reward_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'free_qty' => ['required', 'integer', 'between:1,20'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'max_rounds' => ['required', 'integer', 'between:1,50'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            'trigger_products' => ['array', 'max:200'],
            'trigger_products.*' => ['integer', $productRule],
            'trigger_categories' => ['array', 'max:50'],
            'trigger_categories.*' => ['integer', $categoryRule],
            'reward_products' => ['array', 'max:200'],
            'reward_products.*' => ['integer', $productRule],
            'reward_categories' => ['array', 'max:50'],
            'reward_categories.*' => ['integer', $categoryRule],
        ]);

        $trigger = PromotionTrigger::from($data['trigger_type']);
        $reward = PromotionReward::from($data['reward_type']);

        $this->assertCombinationMakesSense($request, $trigger, $reward, $data);

        return [
            'fields' => [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'trigger_type' => $trigger,
                'trigger_value' => $trigger === PromotionTrigger::None ? 0 : (float) ($data['trigger_value'] ?? 0),
                'reward_type' => $reward,
                'reward_value' => $reward->needsFreeProducts() ? 0 : (float) ($data['reward_value'] ?? 0),
                'free_qty' => (int) $data['free_qty'],
                'max_discount' => $data['max_discount'] ?? null,
                'max_rounds' => (int) $data['max_rounds'],
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ],
            'trigger_products' => $data['trigger_products'] ?? [],
            'trigger_categories' => $data['trigger_categories'] ?? [],
            'reward_products' => $data['reward_products'] ?? [],
            'reward_categories' => $data['reward_categories'] ?? [],
        ];
    }

    /**
     * กันการตั้งค่าที่ผ่าน validate ทีละช่องได้ แต่รวมกันแล้วใช้งานจริงไม่ได้
     *
     * ดักตรงนี้ดีกว่าปล่อยให้บันทึกผ่านแล้วไปเงียบ ๆ ตอนเอนจินคืน null
     * เพราะร้านจะนึกว่าโปรใช้ได้แล้วรอลูกค้ามาบ่น
     */
    protected function assertCombinationMakesSense(
        Request $request,
        PromotionTrigger $trigger,
        PromotionReward $reward,
        array $data,
    ): void {
        $errors = [];

        if ($trigger !== PromotionTrigger::None && (float) ($data['trigger_value'] ?? 0) <= 0) {
            $errors['trigger_value'] = 'ตั้งเงื่อนไขไว้แล้วต้องระบุจำนวนที่มากกว่า 0';
        }

        // ไม่มีเงื่อนไขแล้วแถมของหรือลดท้ายบิล = แจกให้ทุกบิลโดยไม่ต้องซื้ออะไรเพิ่ม
        if ($trigger === PromotionTrigger::None && ($reward->isBillWide() || $reward->needsFreeProducts())) {
            $errors['reward_type'] = 'ของแถมและส่วนลดท้ายบิลต้องมีเงื่อนไขการซื้อกำกับ';
        }

        if ($reward->needsFreeProducts()) {
            if (empty($data['reward_products']) && empty($data['reward_categories'])) {
                $errors['reward_products'] = 'เลือกเมนูที่จะให้ลูกค้าเลือกแถมอย่างน้อย 1 รายการ';
            }
        } elseif ((float) ($data['reward_value'] ?? 0) <= 0) {
            $errors['reward_value'] = 'ระบุมูลค่าส่วนลดที่มากกว่า 0';
        }

        if (in_array($reward, [PromotionReward::ItemPercent, PromotionReward::BillPercent], true)
            && (float) ($data['reward_value'] ?? 0) > 100) {
            $errors['reward_value'] = 'เปอร์เซ็นต์ต้องไม่เกิน 100';
        }

        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /** เขียนรายการเมนู/หมวดใหม่ทั้งชุด — ง่ายกว่าไล่ diff และไม่มีทางเหลือแถวกำพร้า */
    protected function syncItems(Promotion $promotion, array $data): void
    {
        $promotion->items()->delete();

        $rows = [];

        foreach ([PromotionItem::ROLE_TRIGGER => 'trigger', PromotionItem::ROLE_REWARD => 'reward'] as $role => $key) {
            foreach ($data[$key.'_products'] as $id) {
                $rows[] = ['promotion_id' => $promotion->id, 'role' => $role, 'product_id' => $id, 'category_id' => null];
            }

            foreach ($data[$key.'_categories'] as $id) {
                $rows[] = ['promotion_id' => $promotion->id, 'role' => $role, 'product_id' => null, 'category_id' => $id];
            }
        }

        if ($rows) {
            PromotionItem::insert($rows);
        }
    }
}
