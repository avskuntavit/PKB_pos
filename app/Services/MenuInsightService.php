<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Cache;

/**
 * ตัวเลขจากการขายจริง ที่หน้าเมนูเอาไปใช้ตัดสินใจแสดงผล
 *
 * ── ทำไมป้าย "ขายดี" ต้องมาจากยอดขาย ──────────────────────
 * ถ้าให้ร้านติดป้ายเอง สุดท้ายทุกเมนูจะได้ป้าย เพราะไม่มีใครอยากบอกว่า
 * เมนูตัวเองไม่ขายดี พอทุกอันมีป้าย ป้ายก็เลิกมีความหมาย
 * ระบบนี้จึงคิดให้เองจากบิลที่ปิดแล้ว ร้านแก้ไม่ได้
 *
 * (ร้านยังติดป้ายเองได้ผ่าน promo_label ซึ่งเป็นคนละเรื่อง — อันนั้นคือ
 *  "สิ่งที่ร้านอยากบอก" ส่วนอันนี้คือ "สิ่งที่ลูกค้าคนอื่นสั่งจริง")
 */
class MenuInsightService
{
    /** ย้อนหลังกี่วัน — สั้นพอให้สะท้อนของที่ขายดี "ตอนนี้" ไม่ใช่เมื่อไตรมาสก่อน */
    public const WINDOW_DAYS = 14;

    /** ขายได้อย่างน้อยกี่จานถึงเรียกว่าขายดี — กันร้านเปิดใหม่ติดป้ายให้เมนูที่ขายได้จานเดียว */
    public const MIN_SOLD = 5;

    /** ติดป้ายให้ไม่เกินกี่เมนู — ป้ายเยอะเกินก็เท่ากับไม่มีป้าย */
    public const MAX_ITEMS = 8;

    /**
     * id ของเมนูขายดีของสาขานี้
     *
     * @return array<int, int>
     */
    public function bestSellerIds(Branch $branch): array
    {
        // แคชไว้ เพราะหน้าเมนูถูกเปิดทุกครั้งที่ลูกค้าสแกน QR
        // 15 นาทีถือว่าสดพอ ป้ายขายดีไม่ได้เปลี่ยนรายนาที
        return Cache::remember(
            "menu:best-sellers:{$branch->id}",
            now()->addMinutes(15),
            fn () => $this->queryBestSellers($branch),
        );
    }

    /** @return array<int, int> */
    protected function queryBestSellers(Branch $branch): array
    {
        $since = $branch->businessDateFor()->copy()->subDays(self::WINDOW_DAYS)->toDateString();

        /*
        | ตั้งใจไม่ใส่ชื่อตารางนำหน้าใน selectRaw
        |
        | โปรเจกต์นี้ตั้ง DB_PREFIX ไว้ ซึ่ง Laravel เติมให้เฉพาะชื่อตารางที่ผ่าน
        | query builder ไม่ใช่ข้อความใน raw — เขียน "order_items.qty" ใน raw
        | จะได้ SQL ที่ชี้ไปตารางที่ไม่มีอยู่จริง
        | ที่ปล่อยไม่ใส่ได้เพราะ orders ไม่มีคอลัมน์ product_id และ qty จึงไม่กำกวม
        */
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.branch_id', $branch->id)
            ->where('orders.status', OrderStatus::Paid->value)
            ->where('orders.business_date', '>=', $since)
            ->where('order_items.status', '!=', 'void')
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->havingRaw('SUM(qty) >= ?', [self::MIN_SOLD])
            ->orderByRaw('SUM(qty) DESC')
            ->limit(self::MAX_ITEMS)
            ->pluck('order_items.product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** ลืมค่าที่แคชไว้ — เรียกตอนปิดบิล ถ้าอยากให้ป้ายขยับเร็วกว่ารอบแคช */
    public function forget(Branch $branch): void
    {
        Cache::forget("menu:best-sellers:{$branch->id}");
    }
}
