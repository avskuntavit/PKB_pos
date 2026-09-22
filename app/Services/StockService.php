<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\CurrentBranch;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * ตัดสต๊อกวัตถุดิบตามสูตรอาหารเมื่อปิดบิล
     *
     * วิธีคิดปริมาณของแต่ละบรรทัดในบิล:
     *   ตัวคูณขนาด = ผลคูณของ portion_multiplier ของตัวเลือกที่ลูกค้าเลือก
     *                (ธรรมดา 1.00 / พิเศษ 1.50 / จัมโบ้ 2.00)
     *
     *   จากสูตรฐาน  : qty × (ถ้า scales_with_portion ให้คูณตัวคูณขนาด) × จำนวนที่สั่ง
     *   จากตัวเลือก : qty × (ถ้า scales_with_portion ให้คูณตัวคูณขนาด) × จำนวนที่สั่ง
     *
     * ของที่ไม่โตตามขนาดจาน (ถุงพลาสติก หลอด) ให้ตั้ง scales_with_portion = false
     *
     * รวมยอดทั้งบิลก่อนแล้วค่อยบันทึกทีเดียวต่อวัตถุดิบหนึ่งตัว
     * ไม่งั้นบิลเดียวจะได้ stock_movements เป็นสิบแถว อ่านรายงานไม่รู้เรื่อง
     */
    public function deductForOrder(Order $order): void
    {
        $order->loadMissing([
            'activeItems.product.recipeItems.ingredient',
            'activeItems.modifiers.modifier.recipeItems.ingredient',
        ]);

        /** @var array<int, float> $totals  ingredient_id => จำนวนที่ใช้ */
        $totals = [];

        $branchId = (int) $order->branch_id;

        foreach ($order->activeItems as $item) {
            foreach ($this->usageForItem($item, $branchId) as $ingredientId => $qty) {
                $totals[$ingredientId] = ($totals[$ingredientId] ?? 0) + $qty;
            }
        }

        if (! $totals) {
            return;
        }

        $ingredients = Ingredient::whereIn('id', array_keys($totals))->get()->keyBy('id');

        foreach ($totals as $ingredientId => $qty) {
            if (! $ingredient = $ingredients->get($ingredientId)) {
                continue;
            }

            $this->move(
                $ingredient,
                StockMovementType::Usage,
                -1 * round($qty, 4),
                reference: $order,
                note: 'ขายตามบิล '.$order->order_no,
            );
        }
    }

    /**
     * วัตถุดิบที่บรรทัดหนึ่งในบิลใช้ไป
     *
     * สูตรแยกรายสาขา จึงต้องบอกด้วยว่าคิดของสาขาไหน — กรองในหน่วยความจำ
     * เพราะ relation ถูก eager load มาทั้งก้อนแล้วตอน deductForOrder
     *
     * @return array<int, float>  ingredient_id => จำนวน
     */
    public function usageForItem(OrderItem $item, ?int $branchId = null): array
    {
        $product = $item->product;
        $branchId ??= (int) $item->order?->branch_id;

        if (! $product || ! $product->track_stock) {
            return [];
        }

        $qtyOrdered = (float) $item->qty;
        $multiplier = $this->portionMultiplier($item);
        $usage = [];

        // สูตรฐาน
        foreach ($product->recipeItems->where('branch_id', $branchId) as $recipe) {
            if (! $recipe->ingredient_id) {
                continue;
            }

            $factor = $recipe->scales_with_portion ? $multiplier : 1.0;
            $usage[$recipe->ingredient_id] = ($usage[$recipe->ingredient_id] ?? 0)
                + ((float) $recipe->qty * $factor * $qtyOrdered);
        }

        // ส่วนที่ตัวเลือกเพิ่ม/ลด
        foreach ($item->modifiers as $chosen) {
            $modifier = $chosen->modifier;

            if (! $modifier) {
                continue;   // ตัวเลือกถูกลบไปแล้ว — บิลเก่ายังอ่านชื่อจาก snapshot ได้
            }

            $factor = $modifier->scales_with_portion ? $multiplier : 1.0;

            foreach ($modifier->recipeItems->where('branch_id', $branchId) as $extra) {
                if (! $extra->ingredient_id) {
                    continue;
                }

                $usage[$extra->ingredient_id] = ($usage[$extra->ingredient_id] ?? 0)
                    + ((float) $extra->qty * $factor * $qtyOrdered);
            }
        }

        return array_filter($usage, fn (float $q) => abs($q) > 0.00001);
    }

    /** ตัวคูณขนาดจานจากตัวเลือกที่ลูกค้าเลือก */
    public function portionMultiplier(OrderItem $item): float
    {
        $multiplier = 1.0;

        foreach ($item->modifiers as $chosen) {
            if ($modifier = $chosen->modifier) {
                $multiplier *= (float) $modifier->portion_multiplier;
            }
        }

        return $multiplier > 0 ? $multiplier : 1.0;
    }

    /**
     * ต้นทุนวัตถุดิบต่อ 1 จาน ตามตัวเลือกที่ลูกค้าเลือก
     *
     * คิดแบบเดียวกับ usageForItem() เป๊ะ ๆ จะได้ไม่มีวันหลุดจากกัน
     * ใช้ตอน snapshot ต้นทุนลงบิล เพื่อให้จัมโบ้เนื้อวัวมีต้นทุนสูงกว่าธรรมดาหมูจริง
     *
     * @param  iterable<Modifier>  $modifiers  ตัวเลือกที่เลือก
     */
    public function unitCostForSelection(Product $product, iterable $modifiers, ?int $branchId = null): float
    {
        $branchId ??= CurrentBranch::id();
        $product->loadMissing('recipeItems.ingredient');

        $multiplier = 1.0;

        foreach ($modifiers as $modifier) {
            $multiplier *= (float) $modifier->portion_multiplier;
        }

        $multiplier = $multiplier > 0 ? $multiplier : 1.0;
        $total = 0.0;

        foreach ($product->recipeItems->where('branch_id', $branchId) as $recipe) {
            $factor = $recipe->scales_with_portion ? $multiplier : 1.0;
            $total += (float) $recipe->qty * $factor * (float) ($recipe->ingredient?->cost_per_unit ?? 0);
        }

        foreach ($modifiers as $modifier) {
            $modifier->loadMissing('recipeItems.ingredient');
            $factor = $modifier->scales_with_portion ? $multiplier : 1.0;

            foreach ($modifier->recipeItems->where('branch_id', $branchId) as $extra) {
                $total += (float) $extra->qty * $factor * (float) ($extra->ingredient?->cost_per_unit ?? 0);
            }
        }

        return round($total, 2);
    }

    /**
     * รับของเข้าโดยกรอกเป็น "หน่วยซื้อ" เช่น 2 กก. -> +2,000 กรัม
     *
     * costPerPurchaseUnit = ราคาต่อ 1 หน่วยซื้อ (ต่อ กก.) ไม่ใช่ต่อกรัม
     * ระบบหารให้เองด้วย purchase_factor จะได้ไม่ต้องคิดเลขหน้างาน
     */
    public function receive(
        Ingredient $ingredient,
        float $purchaseQty,
        ?float $costPerPurchaseUnit = null,
        ?string $note = null,
    ): StockMovement {
        $factor = $ingredient->purchaseFactor();

        return $this->move(
            $ingredient,
            StockMovementType::Purchase,
            $ingredient->toBaseQty($purchaseQty),
            $costPerPurchaseUnit !== null ? round($costPerPurchaseUnit / $factor, 4) : null,
            note: $note,
        );
    }

    /** บันทึกความเคลื่อนไหวสต๊อก 1 รายการ แล้วอัปเดตยอดคงเหลือ */
    public function move(
        Ingredient $ingredient,
        StockMovementType $type,
        float $qty,
        ?float $unitCost = null,
        $reference = null,
        ?string $note = null,
    ): StockMovement {
        return DB::transaction(function () use ($ingredient, $type, $qty, $unitCost, $reference, $note) {
            $ingredient->refresh();

            $unitCost ??= (float) $ingredient->cost_per_unit;
            // ปัดตามความละเอียดของคอลัมน์ ไม่ใช่ทศนิยม 2 ตำแหน่งแบบเงิน
            // ของที่นับเป็นกรัมจะเสียเศษทันทีถ้าปัดแค่ 2 ตำแหน่ง
            $balance = round((float) $ingredient->stock_qty + $qty, 3);

            // รับของเข้า -> ปรับต้นทุนเฉลี่ยถ่วงน้ำหนัก
            if ($qty > 0 && $type === StockMovementType::Purchase) {
                $oldValue = (float) $ingredient->stock_qty * (float) $ingredient->cost_per_unit;
                $newValue = $qty * $unitCost;
                $ingredient->cost_per_unit = $balance > 0
                    ? round(($oldValue + $newValue) / $balance, 4)
                    : $unitCost;
            }

            $ingredient->stock_qty = $balance;
            $ingredient->save();

            return StockMovement::create([
                'branch_id' => $ingredient->branch_id,
                'ingredient_id' => $ingredient->id,
                'type' => $type,
                'qty' => round($qty, 3),
                // cost เป็นเงิน จึงปัด 2 ตำแหน่งตามปกติ
                'cost' => Money::round(abs($qty) * $unitCost),
                'balance_after' => $balance,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'note' => $note,
                'created_by' => auth()->id(),
                'business_date' => now()->toDateString(),
                'occurred_at' => now(),
            ]);
        });
    }
}
