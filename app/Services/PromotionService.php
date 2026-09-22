<?php

namespace App\Services;

use App\Enums\PromotionReward;
use App\Enums\PromotionTrigger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * เอนจินคิดโปรโมชั่น
 *
 * ── นโยบายที่ตกลงกันไว้ ─────────────────────────────────────────
 * บิลหนึ่งใบใช้โปรได้ "ใบเดียว" คือใบที่ลูกค้าได้ลดมากที่สุด
 * เหตุผล: โปรซ้อนกันเองเป็นที่มาของบิลที่คิดเงินผิดแล้วอธิบายลูกค้าไม่ได้
 * และร้านตั้งโปรโดยไม่ได้ตั้งใจให้มันบวกกัน
 *
 * ── ทำไมคำนวณอยู่ที่นี่ ไม่ใช่ในโมเดล ──────────────────────────
 * ต้องดูทั้งบิลพร้อมกัน: รายการไหนเข้าเงื่อนไข ครบกี่รอบ ฐานคิดคืออะไร
 * ไม่ใช่แค่เอายอดก้อนเดียวมาคูณเปอร์เซ็นต์
 *
 * ── ของแถม ───────────────────────────────────────────────────
 * ร้านกำหนดตัวเลือกไว้ ลูกค้าเลือกเอง 1 อย่าง ตอนเทียบว่าโปรไหนคุ้มสุด
 * จึงตีมูลค่าด้วย "ตัวเลือกที่แพงที่สุด" เพราะนั่นคือสิ่งที่ลูกค้าเลือกได้จริง
 * ถ้าตีด้วยตัวถูกสุดจะกลายเป็นประเมินโปรแถมต่ำเกินจริงแล้วไปเลือกโปรอื่นแทน
 */
class PromotionService
{
    /**
     * โปรที่คุ้มที่สุดของบิลนี้ — ไม่เข้าเงื่อนไขสักใบคืน null
     *
     * @return array{promotion: Promotion, discount: float, rounds: int, matched_qty: float, matched_amount: float, free_qty: int, free_choices: Collection}|null
     */
    public function best(Order $order): ?array
    {
        return $this->candidates($order)[0] ?? null;
    }

    /**
     * โปรที่ระบบใส่ให้บิลได้เองโดยไม่ต้องถามใคร
     *
     * ของแถมไม่เข้าข่าย เพราะร้านตั้งตัวเลือกไว้หลายอย่างแล้วลูกค้าเลือกเอง 1 อย่าง
     * ระบบเลือกแทนไม่ได้ — จะกลายเป็นยัดของที่ลูกค้าไม่อยากได้ลงบิล
     * โปรแถมจึงต้องรอหน้าจอถามก่อน (ดู freeItemOffers)
     *
     * ไล่จากใบที่คุ้มที่สุดลงมา ใบแรกที่ใส่ได้เองคือคำตอบ
     *
     * @return array{promotion: Promotion, discount: float, rounds: int, matched_qty: float, matched_amount: float, free_qty: int, free_choices: Collection}|null
     */
    public function bestAutoApplicable(Order $order): ?array
    {
        foreach ($this->candidates($order) as $outcome) {
            if (! $outcome['promotion']->reward_type->needsFreeProducts()) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * โปรแถมที่บิลนี้เข้าเงื่อนไขแล้ว — รอลูกค้าเลือกเมนู
     *
     * ยังไม่ถูกใส่ลงบิล หน้าจอต้องเอาไปถามก่อน
     *
     * @return array<int, array{promotion: Promotion, discount: float, rounds: int, matched_qty: float, matched_amount: float, free_qty: int, free_choices: Collection}>
     */
    public function freeItemOffers(Order $order): array
    {
        return array_values(array_filter(
            $this->candidates($order),
            fn (array $outcome) => $outcome['promotion']->reward_type->needsFreeProducts(),
        ));
    }

    /**
     * โปรทุกใบที่บิลนี้เข้าเงื่อนไข เรียงจากคุ้มสุดลงมา
     *
     * หน้า POS เอาไปโชว์ให้พนักงานเห็นว่ามีโปรอะไรใช้ได้บ้าง
     *
     * @return array<int, array{promotion: Promotion, discount: float, rounds: int, matched_qty: float, matched_amount: float, free_qty: int, free_choices: Collection}>
     */
    public function candidates(Order $order): array
    {
        $order->loadMissing('activeItems.product:id,category_id');

        $promotions = Promotion::where('branch_id', $order->branch_id)
            ->activeNow()
            ->with('items')
            ->orderBy('sort_order')
            ->get();

        $results = [];

        foreach ($promotions as $promotion) {
            if ($outcome = $this->evaluate($promotion, $order)) {
                $results[] = $outcome;
            }
        }

        // คุ้มสุดมาก่อน เท่ากันให้เรียงตาม sort_order ที่ร้านจัดไว้
        usort($results, function (array $a, array $b) {
            return $b['discount'] <=> $a['discount']
                ?: $a['promotion']->sort_order <=> $b['promotion']->sort_order;
        });

        return $results;
    }

    /**
     * คิดว่าโปรใบนี้ให้อะไรกับบิลนี้ — ไม่เข้าเงื่อนไขหรือตั้งค่าไม่ครบคืน null
     *
     * @return array{promotion: Promotion, discount: float, rounds: int, matched_qty: float, matched_amount: float, free_qty: int, free_choices: Collection}|null
     */
    public function evaluate(Promotion $promotion, Order $order): ?array
    {
        $matched = $this->matchingItems($promotion, $order->activeItems);

        if ($matched->isEmpty()) {
            return null;
        }

        $matchedQty = (float) $matched->sum(fn (OrderItem $i) => (float) $i->qty);
        $matchedAmount = Money::round($matched->sum(fn (OrderItem $i) => $this->lineAmount($i)));

        $rounds = self::roundsFor(
            $promotion->trigger_type->value,
            (float) $promotion->trigger_value,
            $matchedQty,
            $matchedAmount,
            (int) $promotion->max_rounds,
        );

        if ($rounds < 1) {
            return null;
        }

        $freeChoices = $promotion->reward_type->needsFreeProducts()
            ? $this->freeChoices($promotion)
            : collect();

        // ตั้งโปรแถมไว้แต่ไม่ได้เลือกเมนูของแถม — ถือว่ายังใช้ไม่ได้ ดีกว่าแถมมั่ว
        if ($promotion->reward_type->needsFreeProducts() && $freeChoices->isEmpty()) {
            return null;
        }

        $discount = self::rewardAmount(
            reward: $promotion->reward_type->value,
            rewardValue: (float) $promotion->reward_value,
            freeQtyPerRound: (int) $promotion->free_qty,
            rounds: $rounds,
            matchedQty: $matchedQty,
            matchedAmount: $matchedAmount,
            billBase: $this->billBase($order),
            topFreePrice: (float) ($freeChoices->max('price') ?? 0),
            maxDiscount: $promotion->max_discount !== null ? (float) $promotion->max_discount : null,
        );

        if ($discount <= 0) {
            return null;
        }

        return [
            'promotion' => $promotion,
            'discount' => $discount,
            'rounds' => $rounds,
            'matched_qty' => $matchedQty,
            'matched_amount' => $matchedAmount,
            'free_qty' => $promotion->reward_type->needsFreeProducts() ? (int) $promotion->free_qty * $rounds : 0,
            'free_choices' => $freeChoices,
        ];
    }

    /* ---------- คณิตศาสตร์ล้วน แยกออกมาให้ทดสอบได้โดยไม่ต้องมีฐานข้อมูล ---------- */

    /**
     * เข้าเงื่อนไขกี่รอบ
     *
     * ตั้ง trigger_value เป็น 0 ทั้งที่เลือกเงื่อนไขไว้ = ตั้งค่าไม่ครบ คืน 0
     * ไม่ใช่คืนรอบเดียว เพราะนั่นเท่ากับแจกส่วนลดฟรีให้ทุกบิล
     */
    public static function roundsFor(
        string $trigger,
        float $triggerValue,
        float $matchedQty,
        float $matchedAmount,
        int $maxRounds,
    ): int {
        if ($matchedQty <= 0) {
            return 0;
        }

        $maxRounds = max(1, $maxRounds);

        if ($trigger === PromotionTrigger::None->value) {
            return 1;
        }

        if ($triggerValue <= 0) {
            return 0;
        }

        $have = $trigger === PromotionTrigger::Qty->value ? $matchedQty : $matchedAmount;

        return (int) min($maxRounds, floor($have / $triggerValue));
    }

    /**
     * ส่วนลดเป็นบาท (ของแถมตีเป็นมูลค่าเมนูที่แพงที่สุดที่เลือกได้)
     *
     * เพดานสองชั้น: max_discount ที่ร้านตั้ง แล้วค่อยชนเพดานฐานที่คิด
     * item_* ลดได้ไม่เกินยอดของเมนูที่ร่วมรายการ — ไม่งั้นลดทะลุไปกินยอดเมนูอื่น
     */
    public static function rewardAmount(
        string $reward,
        float $rewardValue,
        int $freeQtyPerRound,
        int $rounds,
        float $matchedQty,
        float $matchedAmount,
        float $billBase,
        float $topFreePrice,
        ?float $maxDiscount = null,
    ): float {
        if ($rounds < 1 || $rewardValue < 0) {
            return 0.0;
        }

        $raw = match ($reward) {
            PromotionReward::ItemPercent->value => $matchedAmount * $rewardValue / 100,
            PromotionReward::ItemAmount->value => $rewardValue * $rounds,
            // ทุกชิ้นเหลือชิ้นละ rewardValue — ส่วนต่างคือส่วนลด ตั้งราคาสูงกว่าเดิมก็ไม่ติดลบ
            PromotionReward::ItemFixedPrice->value => max(0.0, $matchedAmount - ($rewardValue * $matchedQty)),
            PromotionReward::FreeItem->value => $topFreePrice * max(1, $freeQtyPerRound) * $rounds,
            PromotionReward::BillPercent->value => $billBase * $rewardValue / 100,
            PromotionReward::BillAmount->value => $rewardValue * $rounds,
            default => 0.0,
        };

        if ($maxDiscount !== null && $maxDiscount > 0) {
            $raw = min($raw, $maxDiscount);
        }

        // ของแถมไม่ได้หักจากยอดเมนูที่ร่วมรายการ จึงใช้เพดานทั้งบิลเหมือน bill_*
        $ceiling = match ($reward) {
            PromotionReward::ItemPercent->value,
            PromotionReward::ItemAmount->value,
            PromotionReward::ItemFixedPrice->value => $matchedAmount,
            default => $billBase,
        };

        return Money::round(max(0.0, min($raw, $ceiling)));
    }

    /* ---------- ภายใน ---------- */

    /**
     * รายการในบิลที่เข้าโปรใบนี้
     *
     * ผูกหมวดไว้ = ทุกเมนูในหมวดนั้น รวมเมนูที่ออกใหม่ทีหลัง
     * ไม่ผูกอะไรเลย = ทั้งบิลเข้าโปร
     *
     * @param  Collection<int, OrderItem>  $items
     * @return Collection<int, OrderItem>
     */
    protected function matchingItems(Promotion $promotion, Collection $items): Collection
    {
        $targets = $promotion->items->where('role', 'trigger');

        $productIds = $targets->pluck('product_id')->filter()->map('intval')->all();
        $categoryIds = $targets->pluck('category_id')->filter()->map('intval')->all();

        if (! $productIds && ! $categoryIds) {
            return $items->values();
        }

        return $items
            ->filter(function (OrderItem $item) use ($productIds, $categoryIds) {
                if ($item->product_id !== null && in_array((int) $item->product_id, $productIds, true)) {
                    return true;
                }

                $categoryId = $item->product?->category_id;

                return $categoryId !== null && in_array((int) $categoryId, $categoryIds, true);
            })
            ->values();
    }

    /** ยอดของหนึ่งรายการก่อนหักส่วนลด — ฐานเดียวกับ subtotal ใน OrderService::recalculate */
    protected function lineAmount(OrderItem $item): float
    {
        return ((float) $item->unit_price * (float) $item->qty) + (float) $item->modifier_total;
    }

    /** ฐานคิดส่วนลดท้ายบิล — ฐานเดียวกับ OrderService::applyBillDiscount */
    protected function billBase(Order $order): float
    {
        return max(0.0, (float) $order->subtotal - (float) $order->item_discount);
    }

    /**
     * เมนูของแถมที่ลูกค้าเลือกได้จริงตอนนี้
     *
     * กรองเมนูที่ปิดขาย/ของหมดชั่วคราวออก ไม่งั้นลูกค้าเลือกแล้วครัวทำไม่ได้
     * และตัดเมนูราคาเปิด (ให้พนักงานกรอกราคาเอง) เพราะตีมูลค่าของแถมไม่ได้
     *
     * @return Collection<int, Product>
     */
    protected function freeChoices(Promotion $promotion): Collection
    {
        $targets = $promotion->items->where('role', 'reward');

        $productIds = $targets->pluck('product_id')->filter()->map('intval')->all();
        $categoryIds = $targets->pluck('category_id')->filter()->map('intval')->all();

        if (! $productIds && ! $categoryIds) {
            return collect();
        }

        return Product::sellableAt($promotion->branch_id)
            ->where('is_open_price', false)
            ->where(function ($q) use ($productIds, $categoryIds) {
                if ($productIds) {
                    $q->orWhereIn('id', $productIds);
                }

                if ($categoryIds) {
                    $q->orWhereIn('category_id', $categoryIds);
                }
            })
            ->orderByDesc('price')
            ->get(['id', 'name', 'price', 'image_path']);
    }
}
