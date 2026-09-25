<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\BranchStockItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Support\CurrentBranch;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * ตัดสต๊อก รับของ และคิดต้นทุนตามสูตร
 *
 * ── ของเป็นกลาง แต่สต๊อกแยกสาขา ────────────────────────────
 * `stock_items` เป็นแม่แบบกลาง (ชื่อ หน่วย หน่วยซื้อ)
 * `branch_stock_items` เก็บยอดคงเหลือ ต้นทุนเฉลี่ย และจุดสั่งซื้อรายสาขา
 *
 * ทุกเมธอดที่แตะตัวเลขจึงต้อง **ระบุสาขามาเสมอ** เมื่อก่อนอ่านจาก
 * `$ingredient->branch_id` ได้เพราะวัตถุดิบผูกสาขาอยู่แล้ว ตอนนี้อ่านไม่ได้แล้ว
 * และการเดาเอาจาก CurrentBranch ในชั้นนี้อันตราย เพราะงานที่รันจาก
 * ตัวตั้งเวลาไม่มี CurrentBranch
 */
class StockService
{
    /**
     * ตัดสต๊อกตามสูตรอาหารเมื่อปิดบิล
     *
     * รวมยอดทั้งบิลก่อนแล้วค่อยบันทึกทีเดียวต่อของหนึ่งชิ้น
     * ไม่งั้นบิลเดียวจะได้ stock_movements เป็นสิบแถว อ่านรายงานไม่รู้เรื่อง
     */
    public function deductForOrder(Order $order): void
    {
        /*
        | ต้อง load() ไม่ใช่ loadMissing() — บั๊กตัวเดียวกับที่เคยแก้ไปแล้วใน StaffBenefitService
        |
        | `PaymentService::pay()` เรียก `OrderService::recalculate()` ก่อนหน้านี้ในทรานแซกชันเดียวกัน
        | ซึ่งโหลด `activeItems.product:id,category_id` = ดึงมาแค่สองคอลัมน์เพื่อความเร็ว
        |
        | loadMissing เห็นว่า relation "โหลดแล้ว" จึงไม่ทำอะไรเลย เราจึงได้ Product
        | ที่ไม่มีคอลัมน์ track_stock ติดมา แล้ว `usageFor()` อ่านได้ null ซึ่งเป็น false
        | -> คืนอาร์เรย์ว่าง -> **ไม่ตัดสต๊อกเลยสักบิลตั้งแต่แรก** และเงียบสนิท
        | เพราะ Eloquent คืน null ให้แอตทริบิวต์ที่ไม่ได้โหลด ไม่ได้โยน error
        |
        | ตอนแก้ที่ StaffBenefitService ไม่ได้กวาดทั้งโปรเจกต์ ตัวนี้จึงค้างมาจนถึงวันนี้
        | เจอตอนเขียนเทสต์ offline เฟส 3 — คุมไว้แล้วที่
        | OfflineCashTest::test_paying_a_bill_deducts_stock_at_all
        */
        $order->load([
            'activeItems.product.recipeItems',
            'activeItems.modifiers.modifier.recipeItems',
        ]);

        /** @var array<int, float> $totals  stock_item_id => จำนวนที่ใช้ */
        $totals = [];

        $branchId = (int) $order->branch_id;

        foreach ($order->activeItems as $item) {
            foreach ($this->usageForItem($item, $branchId) as $stockItemId => $qty) {
                $totals[$stockItemId] = ($totals[$stockItemId] ?? 0) + $qty;
            }
        }

        if (! $totals) {
            return;
        }

        $items = StockItem::whereIn('id', array_keys($totals))->get()->keyBy('id');

        foreach ($totals as $stockItemId => $qty) {
            if (! $stockItem = $items->get($stockItemId)) {
                continue;
            }

            $this->move(
                $stockItem,
                $branchId,
                StockMovementType::Usage,
                -1 * round($qty, 4),
                reference: $order,
                note: 'ขายตามบิล '.$order->order_no,
            );
        }
    }

    /**
     * ของที่บรรทัดหนึ่งในบิลใช้ไป
     *
     * @return array<int, float>  stock_item_id => จำนวน
     */
    public function usageForItem(OrderItem $item, ?int $branchId = null): array
    {
        $product = $item->product;
        $branchId ??= (int) $item->order?->branch_id;

        if (! $product) {
            return [];
        }

        $modifiers = $item->modifiers
            ->map(fn ($chosen) => $chosen->modifier)
            // ตัวเลือกถูกลบไปแล้ว — บิลเก่ายังอ่านชื่อจาก snapshot ได้ แต่ไล่สูตรต่อไม่ได้
            ->filter();

        return $this->usageFor($product, $modifiers, (int) $branchId, (float) $item->qty);
    }

    /**
     * ของที่ใช้ไป เมื่อขายเมนูหนึ่งพร้อมตัวเลือกชุดหนึ่ง
     *
     * ── ทำไมแยกออกมาเป็นเมธอดกลาง ─────────────────────────
     * เดิมการตัดสต๊อกกับการคิดต้นทุนเขียนสูตรเดียวกันไว้คนละที่ พร้อมคอมเมนต์เตือนว่า
     * "ต้องคิดเหมือนกันเป๊ะ ๆ" ซึ่งแปลว่าวันหนึ่งจะไม่เหมือนกัน
     * ตอนนี้ทั้งสองเรียกเมธอดนี้ตัวเดียว หลุดจากกันไม่ได้แล้ว
     *
     * ── วิธีคิด ───────────────────────────────────────────
     *   ตัวคูณขนาด = ผลคูณของ portion_multiplier ของตัวเลือกที่เลือก
     *                (ธรรมดา 1.00 / พิเศษ 1.50 / จัมโบ้ 2.00)
     *
     *   จากสูตรฐาน  : qty × (ถ้า scales_with_portion ให้คูณตัวคูณขนาด) × จำนวนที่สั่ง
     *   จากตัวเลือก : qty × (ถ้า scales_with_portion ให้คูณตัวคูณขนาด) × จำนวนที่สั่ง
     *
     * ของที่ไม่โตตามขนาดจาน (ถุงพลาสติก หลอด) ให้ตั้ง scales_with_portion = false
     *
     * สูตรแยกรายสาขา จึงกรอง branch_id ในหน่วยความจำ เพราะ relation
     * ถูก eager load มาทั้งก้อนแล้วตอน deductForOrder
     *
     * @param  iterable<Modifier>  $modifiers
     * @return array<int, float>  stock_item_id => จำนวน
     */
    public function usageFor(Product $product, iterable $modifiers, int $branchId, float $qtyOrdered = 1.0): array
    {
        if (! $product->track_stock) {
            return [];
        }

        $product->loadMissing('recipeItems');

        $multiplier = 1.0;

        foreach ($modifiers as $modifier) {
            $multiplier *= (float) $modifier->portion_multiplier;
        }

        $multiplier = $multiplier > 0 ? $multiplier : 1.0;
        $usage = [];

        // สูตรฐาน
        foreach ($product->recipeItems->where('branch_id', $branchId) as $recipe) {
            if (! $recipe->stock_item_id) {
                continue;
            }

            $factor = $recipe->scales_with_portion ? $multiplier : 1.0;
            $usage[$recipe->stock_item_id] = ($usage[$recipe->stock_item_id] ?? 0)
                + ((float) $recipe->qty * $factor * $qtyOrdered);
        }

        // ส่วนที่ตัวเลือกเพิ่ม/ลด
        foreach ($modifiers as $modifier) {
            $modifier->loadMissing('recipeItems');
            $factor = $modifier->scales_with_portion ? $multiplier : 1.0;

            foreach ($modifier->recipeItems->where('branch_id', $branchId) as $extra) {
                if (! $extra->stock_item_id) {
                    continue;
                }

                $usage[$extra->stock_item_id] = ($usage[$extra->stock_item_id] ?? 0)
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
     * ต้นทุนของต่อ 1 จาน ตามตัวเลือกที่ลูกค้าเลือก
     *
     * ใช้ตอน snapshot ต้นทุนลงบิล เพื่อให้จัมโบ้เนื้อวัวมีต้นทุนสูงกว่าธรรมดาหมูจริง
     * ต้นทุนเป็นของรายสาขา จึงอ่านจาก branch_stock_items ของสาขานั้น —
     * ของชิ้นเดียวกันคนละสาขาซื้อมาคนละราคาเป็นเรื่องปกติ
     *
     * @param  iterable<Modifier>  $modifiers  ตัวเลือกที่เลือก
     */
    public function unitCostForSelection(Product $product, iterable $modifiers, ?int $branchId = null): float
    {
        $branchId = (int) ($branchId ?? CurrentBranch::id());

        $usage = $this->usageFor($product, $modifiers, $branchId);

        if (! $usage) {
            return 0.0;
        }

        // คิวรีเดียวจบ ไม่ไล่ถามต้นทุนทีละชิ้น
        $costs = BranchStockItem::where('branch_id', $branchId)
            ->whereIn('stock_item_id', array_keys($usage))
            ->pluck('cost_per_unit', 'stock_item_id');

        $total = 0.0;

        foreach ($usage as $stockItemId => $qty) {
            $total += $qty * (float) ($costs[$stockItemId] ?? 0);
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
        StockItem $item,
        int $branchId,
        float $purchaseQty,
        ?float $costPerPurchaseUnit = null,
        ?string $note = null,
    ): StockMovement {
        $factor = $item->purchaseFactor();

        return $this->move(
            $item,
            $branchId,
            StockMovementType::Purchase,
            $item->toBaseQty($purchaseQty),
            $costPerPurchaseUnit !== null ? round($costPerPurchaseUnit / $factor, 4) : null,
            note: $note,
        );
    }

    /**
     * บันทึกความเคลื่อนไหวสต๊อก 1 รายการ แล้วอัปเดตยอดคงเหลือของสาขานั้น
     *
     * ล็อกแถวของสาขาก่อนแก้เสมอ ไม่งั้นสองบิลที่ปิดพร้อมกันจะอ่านยอดเดิมทั้งคู่
     * แล้วเขียนทับกัน — ของหายไปหนึ่งก้อนโดยไม่มีใครรู้
     */
    public function move(
        StockItem $item,
        int $branchId,
        StockMovementType $type,
        float $qty,
        ?float $unitCost = null,
        $reference = null,
        ?string $note = null,
    ): StockMovement {
        return DB::transaction(function () use ($item, $branchId, $type, $qty, $unitCost, $reference, $note) {
            $stock = $this->lockStock($item, $branchId);

            $unitCost ??= (float) $stock->cost_per_unit;
            // ปัดตามความละเอียดของคอลัมน์ ไม่ใช่ทศนิยม 2 ตำแหน่งแบบเงิน
            // ของที่นับเป็นกรัมจะเสียเศษทันทีถ้าปัดแค่ 2 ตำแหน่ง
            $balance = round((float) $stock->stock_qty + $qty, 3);

            /*
            | ของเข้า -> ปรับต้นทุนเฉลี่ยถ่วงน้ำหนักของสาขานี้
            |
            | นับทั้งการซื้อเข้าและการรับโอนจากสถานีอื่น เพราะของที่โอนมาพกต้นทุน
            | ของต้นทางมาด้วย ซึ่งมักไม่เท่าต้นทุนที่ปลายทางมีอยู่
            | ถ้าไม่คิดถ่วงน้ำหนักตรงนี้ ต้นทุนปลายทางจะค้างที่ค่าเดิมแบบเงียบ ๆ
            | แล้วรายงานกำไรของปลายทางจะผิดไปทุกจานที่ใช้ของชิ้นนั้น
            |
            | ส่วน adjust / waste ไม่แตะต้นทุน — เป็นการแก้จำนวน ไม่ใช่การซื้อของเข้ามาใหม่
            */
            if ($qty > 0 && in_array($type, [StockMovementType::Purchase, StockMovementType::TransferIn], true)) {
                $oldValue = (float) $stock->stock_qty * (float) $stock->cost_per_unit;
                $newValue = $qty * $unitCost;
                $stock->cost_per_unit = $balance > 0
                    ? round(($oldValue + $newValue) / $balance, 4)
                    : $unitCost;
            }

            $stock->stock_qty = $balance;
            $stock->save();

            return StockMovement::create([
                'branch_id' => $branchId,
                'stock_item_id' => $item->id,
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

    /**
     * แถวสต๊อกของสาขา พร้อมล็อกไว้แก้
     *
     * สาขาที่แตะของชิ้นนี้ครั้งแรกยังไม่มีแถว จึงสร้างให้ก่อน
     * firstOrCreate ของ Laravel จับ unique ที่ชนกันแล้วอ่านใหม่ให้เอง
     * จึงปลอดภัยแม้สองคนกดพร้อมกัน
     */
    protected function lockStock(StockItem $item, int $branchId): BranchStockItem
    {
        BranchStockItem::firstOrCreate([
            'branch_id' => $branchId,
            'stock_item_id' => $item->id,
        ]);

        return BranchStockItem::where('branch_id', $branchId)
            ->where('stock_item_id', $item->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
