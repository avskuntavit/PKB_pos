<?php

namespace App\Services;

use App\Models\Modifier;
use App\Models\Product;
use App\Models\TableCartItem;
use App\Models\TableSession;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ตะกร้าร่วมของโต๊ะ
 *
 * ── ปัญหาที่แก้ ──────────────────────────────────────────────────────────
 * เดิมตะกร้าอยู่ใน localStorage ของแต่ละเครื่อง คนที่นั่งโต๊ะเดียวกันจึงไม่เห็นของกัน
 * จนกว่าจะกดสั่งไปแล้ว ผลคือสั่งซ้ำกันเอง หรือรอกันไปมาว่าใครจะกด
 *
 * ── กติกาที่ตกลงกันไว้ ───────────────────────────────────────────────────
 * · ใครก็ได้ที่นั่งโต๊ะเดียวกัน แก้จำนวนหรือลบรายการของคนอื่นได้
 *   (คนที่นั่งด้วยกันคุยกันได้อยู่แล้ว การบังคับให้เจ้าของเท่านั้นที่แก้ได้
 *   จะพังทันทีที่คนหนึ่งเดินไปเข้าห้องน้ำแล้วโต๊ะอยากแก้ของที่เขาสั่งไว้)
 * · ใครก็ได้กดส่งครัว และส่งทั้งตะกร้าทีเดียว
 * · กดส่งแล้วนับถอยหลัง 5 วินาที ทุกเครื่องที่โต๊ะเห็นพร้อมกันและกดยกเลิกได้
 * · ใช้เฉพาะตอนสแกน QR ที่โต๊ะ — สั่งกลับบ้านยังใช้ตะกร้าส่วนตัวเหมือนเดิม
 *
 * ── สิ่งที่ตะกร้านี้ *ไม่* ทำ ─────────────────────────────────────────────
 * ราคาที่เก็บไว้ในตะกร้าใช้โชว์ยอดรวมเท่านั้น ไม่ใช่ราคาที่ตัดบิล
 * ตอนกดส่งครัว ชั้นที่เปิดบิลจริงอ่านราคาของสาขาใหม่ทั้งหมด
 * ถ้าเชื่อราคาที่ client ส่งมา ใครก็ตั้งราคาให้ตัวเองได้
 */
class TableCartService
{
    /** จำนวนบรรทัดสูงสุดในตะกร้า */
    public const MAX_LINES = 30;

    public const MAX_QTY_PER_LINE = 20;

    /** นับถอยหลังกี่วินาทีก่อนส่งจริง */
    public const SUBMIT_DELAY_SECONDS = 5;

    /**
     * นับถอยหลังที่ค้างเกินกี่วินาทีถือว่าเจ้าของเครื่องหายไปแล้ว
     *
     * คนกดส่งเป็นคนยิงคำสั่งส่งจริงเมื่อครบเวลา ถ้ามือถือเขาดับหรือปิดแท็บไปก่อน
     * จะไม่มีใครมาปิดงานนี้ แล้วทั้งโต๊ะจะเห็นนาฬิกาค้างอยู่ตลอดไปโดยที่ไม่มีอะไรเกิดขึ้น
     */
    public const SUBMIT_STALE_SECONDS = 60;

    /*
    |--------------------------------------------------------------------------
    | อ่าน
    |--------------------------------------------------------------------------
    */

    /** @return Collection<int, TableCartItem> */
    public function items(TableSession $session): Collection
    {
        return TableCartItem::with('product:id,name')
            ->where('table_session_id', $session->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * สรุปตะกร้าสำหรับส่งไปกับ /status ที่มือถือถามอยู่แล้ว
     *
     * รวมไว้กับ endpoint เดิมโดยตั้งใจ ไม่เปิดตัวถามใหม่อีกตัว
     * มือถือสี่ห้าเครื่องที่โต๊ะเดียวกันถามสองเส้นทางพร้อมกันคือการเพิ่มโหลดเป็นเท่าตัว
     * โดยไม่ได้อะไรกลับมา
     *
     * @return array<string, mixed>
     */
    public function summary(TableSession $session, ?string $guestKey = null): array
    {
        $items = $this->items($session);
        $pending = $this->pendingSubmit($session);
        $modifierNames = $this->modifierNames($items);

        return [
            'lines' => $items->map(fn (TableCartItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product?->name ?? '(เมนูถูกลบแล้ว)',
                'qty' => (float) $item->qty,
                'unit_price' => (float) $item->unit_price,
                'line_total' => $item->lineTotal(),
                'modifier_names' => $this->namesFor($item, $modifierNames),
                'note' => $item->note,
                'guest_name' => $item->guest_name,
                // ไม่ได้ใช้เป็นสิทธิ์ ใช้แค่ทำตัวหนาให้ของตัวเองบนหน้าจอ
                'mine' => $guestKey !== null && $item->guest_key === $guestKey,
            ])->values()->all(),
            'count' => (float) $items->sum('qty'),
            'total' => round($items->sum(fn (TableCartItem $i) => $i->lineTotal()), 2),
            'submit_at' => $pending?->toIso8601String(),
            'submit_by' => $pending ? $session->cart_submit_name : null,
            'submit_is_mine' => $pending !== null && $guestKey !== null && $session->cart_submit_by === $guestKey,
        ];
    }

    /** เวลาที่จะส่งจริง — null คือไม่มีอะไรค้างอยู่ */
    public function pendingSubmit(TableSession $session): ?Carbon
    {
        if (! $session->cart_submit_at) {
            return null;
        }

        $at = Carbon::parse($session->cart_submit_at);

        // ค้างนานเกินไป = คนกดหายไปแล้ว ล้างทิ้งไม่ให้ทั้งโต๊ะเห็นนาฬิกาค้าง
        if ($at->copy()->addSeconds(self::SUBMIT_STALE_SECONDS)->isPast()) {
            $this->clearSubmitFlags($session);

            return null;
        }

        return $at;
    }

    /*
    |--------------------------------------------------------------------------
    | แก้ตะกร้า
    |--------------------------------------------------------------------------
    */

    /** @param  array<int, int>  $modifierIds */
    public function add(
        TableSession $session,
        int $productId,
        float $qty,
        array $modifierIds,
        ?string $note,
        string $guestKey,
        ?string $guestName,
    ): TableCartItem {
        $this->assertOpen($session);

        if ($qty < 1 || $qty > self::MAX_QTY_PER_LINE) {
            throw new DomainException('สั่งได้ครั้งละ 1–'.self::MAX_QTY_PER_LINE.' ที่ต่อรายการ');
        }

        $product = Product::with(['activeModifierGroups' => fn ($q) => $q->with([
            'modifiers' => fn ($m) => $m->where('is_active', true),
        ])])
            ->sellableAt($session->branch_id)
            ->where('is_open_price', false)
            ->whereKey($productId)
            ->first();

        if (! $product) {
            throw new DomainException('เมนูนี้สั่งไม่ได้แล้ว อาจเพิ่งของหมดหรือถูกปิดขาย');
        }

        [$allowedIds, $modifierDelta] = $this->resolveModifiers($product, $modifierIds);

        $note = trim((string) $note) ?: null;
        $key = TableCartItem::keyFor($product->id, $allowedIds, $note);

        return DB::transaction(function () use ($session, $product, $qty, $allowedIds, $modifierDelta, $note, $key, $guestKey, $guestName) {
            $existing = TableCartItem::where('table_session_id', $session->id)
                ->where('line_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // กดของเดิมซ้ำ = บวกจำนวน ไม่ใช่เพิ่มบรรทัดใหม่
                $existing->qty = min(self::MAX_QTY_PER_LINE, (float) $existing->qty + $qty);
                $existing->save();

                return $existing;
            }

            if (TableCartItem::where('table_session_id', $session->id)->count() >= self::MAX_LINES) {
                throw new DomainException('ตะกร้าเต็มแล้ว ('.self::MAX_LINES.' รายการ) กดส่งครัวก่อนแล้วค่อยสั่งเพิ่ม');
            }

            return TableCartItem::create([
                'table_session_id' => $session->id,
                'product_id' => $product->id,
                'line_key' => $key,
                'qty' => $qty,
                'modifier_ids' => $allowedIds ?: null,
                'note' => $note,
                // ราคาของสาขานี้ + ส่วนต่างของตัวเลือก — ให้ยอดในตะกร้าตรงกับที่จะขึ้นบิลจริง
                'unit_price' => round($product->priceAt($session->branch_id) + $modifierDelta, 2),
                'guest_key' => $guestKey,
                'guest_name' => $guestName,
            ]);
        });
    }

    /** ตั้งจำนวนใหม่ — 0 หรือน้อยกว่า = ลบบรรทัดนั้นทิ้ง */
    public function setQty(TableSession $session, int $itemId, float $qty): ?TableCartItem
    {
        $this->assertOpen($session);

        $item = $this->findOwnedLine($session, $itemId);

        if ($qty <= 0) {
            $item->delete();

            return null;
        }

        $item->qty = min(self::MAX_QTY_PER_LINE, $qty);
        $item->save();

        return $item;
    }

    public function remove(TableSession $session, int $itemId): void
    {
        $this->assertOpen($session);

        $this->findOwnedLine($session, $itemId)->delete();
    }

    public function clear(TableSession $session): int
    {
        return TableCartItem::where('table_session_id', $session->id)->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | ส่งครัว
    |--------------------------------------------------------------------------
    */

    /** กดส่ง — ยังไม่ส่งจริง แค่ตั้งนาฬิกาให้ทั้งโต๊ะเห็น */
    public function requestSubmit(TableSession $session, string $guestKey, ?string $guestName): Carbon
    {
        $this->assertOpen($session);

        if (! TableCartItem::where('table_session_id', $session->id)->exists()) {
            throw new DomainException('ตะกร้ายังว่างอยู่');
        }

        // มีคนกดไปแล้ว = ไม่ต้องตั้งนาฬิกาใหม่ ไม่งั้นกดพร้อมกันสองคนจะยืดเวลาออกไปเรื่อย ๆ
        if ($existing = $this->pendingSubmit($session)) {
            return $existing;
        }

        $at = Carbon::now()->addSeconds(self::SUBMIT_DELAY_SECONDS);

        $session->forceFill([
            'cart_submit_at' => $at,
            'cart_submit_by' => $guestKey,
            'cart_submit_name' => $guestName,
        ])->save();

        return $at;
    }

    /** ใครก็ยกเลิกได้ ไม่ใช่เฉพาะคนที่กดส่ง — เพราะคนที่ยังเลือกไม่เสร็จคือคนที่ต้องหยุดมัน */
    public function cancelSubmit(TableSession $session): bool
    {
        if (! $session->cart_submit_at) {
            return false;
        }

        $this->clearSubmitFlags($session);

        return true;
    }

    /**
     * ส่งจริง — เครื่องของคนที่กดส่งเป็นคนยิงเข้ามาเมื่อนับครบ
     *
     * เช็คซ้ำทุกอย่างที่นี่ ไม่เชื่อว่าฝั่ง client นับถูก
     * เพราะระหว่างนับถอยหลังอาจมีคนกดยกเลิก หรือกดส่งซ้ำจากอีกเครื่อง
     *
     * ── ทำไมรับเป็น callback แทนที่จะเรียกเซอร์วิสสั่งอาหารเอง ──────────────
     * ตะกร้ารู้แค่ว่า "ของในตะกร้ามีอะไร" และ "ตอนนี้ส่งได้หรือยัง"
     * ส่วนการเปิดบิลจริงต้องใช้ข้อมูลของหน้าเช็คเอาต์ (ชื่อ เบอร์ วิธีชำระ)
     * ซึ่งเป็นเรื่องของ controller ไม่ใช่ของตะกร้า
     *
     * แยกแบบนี้แล้ววันหน้าเปลี่ยนเส้นทางการเปิดบิล ตะกร้าไม่ต้องรู้เรื่องด้วย
     *
     * @param  callable(array<int, array<string, mixed>>, TableSession): mixed  $place
     */
    public function submitNow(TableSession $session, callable $place): mixed
    {
        return DB::transaction(function () use ($session, $place) {
            $locked = TableSession::whereKey($session->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->cart_submit_at) {
                throw new DomainException('การส่งครัวถูกยกเลิกไปแล้ว');
            }

            if (Carbon::now()->lt(Carbon::parse($locked->cart_submit_at))) {
                throw new DomainException('ยังนับถอยหลังไม่ครบ');
            }

            $lines = $this->payload($locked);

            /*
            | ล้างธงก่อนส่ง ไม่ใช่หลังส่ง
            |
            | ถ้าล้างทีหลัง แล้วมีอีกเครื่องยิงเข้ามาพอดีระหว่างที่ยังส่งไม่เสร็จ
            | ตะกร้าจะถูกส่งสองรอบ ลูกค้าได้อาหารสองเท่า
            | ล้างก่อนแปลว่าเครื่องที่สองจะเจอ null แล้วหยุดเอง
            */
            $this->clearSubmitFlags($locked);

            if ($lines === []) {
                throw new DomainException('ตะกร้าว่างแล้ว ไม่มีอะไรให้ส่ง');
            }

            $result = $place($lines, $locked);

            $this->clear($locked);

            return $result;
        });
    }

    /**
     * แปลงตะกร้าเป็นรูปแบบเดียวกับ lines ที่หน้าเช็คเอาต์ส่ง
     *
     * @return array<int, array<string, mixed>>
     */
    public function payload(TableSession $session): array
    {
        return $this->items($session)
            ->map(fn (TableCartItem $item) => [
                'product_id' => (int) $item->product_id,
                'qty' => (float) $item->qty,
                'modifier_ids' => $item->modifier_ids ?? [],
                'note' => $item->note,
                // ชื่อคนสั่งติดไปกับรายการ เพื่อให้บิลโต๊ะแยกได้ว่าจานไหนของใคร
                'guest_name' => $item->guest_name,
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | ตัวช่วย
    |--------------------------------------------------------------------------
    */

    protected function assertOpen(TableSession $session): void
    {
        if (! $session->isActive()) {
            throw new DomainException('รอบการสั่งนี้ปิดแล้ว กรุณาสแกน QR ใหม่อีกครั้ง');
        }
    }

    /**
     * บรรทัดนี้อยู่ในตะกร้าของโต๊ะนี้จริงไหม
     *
     * ทุกคนที่โต๊ะแก้ของกันได้ แต่ต้องแก้ได้เฉพาะของโต๊ะตัวเอง
     * ถ้าไม่เช็ค ใครก็ยิง id มั่วไปลบตะกร้าโต๊ะอื่นได้
     */
    protected function findOwnedLine(TableSession $session, int $itemId): TableCartItem
    {
        $item = TableCartItem::where('table_session_id', $session->id)
            ->whereKey($itemId)
            ->first();

        if (! $item) {
            throw new DomainException('ไม่พบรายการนี้ในตะกร้า อาจมีคนที่โต๊ะลบไปแล้ว');
        }

        return $item;
    }

    /**
     * ชื่อตัวเลือกของทุกบรรทัดในตะกร้า — คิวรีเดียวจบ
     *
     * เก็บไว้เป็น id ในตาราง เพราะชื่อตัวเลือกแก้ได้ในหลังบ้าน ถ้าเก็บชื่อไว้ด้วย
     * ตะกร้าจะค้างชื่อเก่าจนกว่าจะลบทิ้ง แต่หน้าจอลูกค้าต้องเห็นชื่อ ไม่ใช่เลข
     * จึงต้องแปลงตอนอ่าน — ทำทีเดียวทั้งตะกร้า ไม่ใช่บรรทัดละคิวรี
     *
     * @param  Collection<int, TableCartItem>  $items
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function modifierNames(Collection $items): \Illuminate\Support\Collection
    {
        $ids = $items
            ->flatMap(fn (TableCartItem $item) => $item->modifier_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Modifier::whereIn('id', $ids)->pluck('name', 'id');
    }

    /**
     * ชื่อตัวเลือกของบรรทัดเดียว เรียงตามที่ลูกค้าเลือกไว้
     *
     * ตัวเลือกที่ถูกลบไปแล้วหายไปเฉย ๆ ไม่ขึ้นเป็นช่องว่างหรือเลข id
     *
     * @param  \Illuminate\Support\Collection<int, string>  $names
     * @return array<int, string>
     */
    protected function namesFor(TableCartItem $item, \Illuminate\Support\Collection $names): array
    {
        return collect($item->modifier_ids ?? [])
            ->map(fn ($id) => $names->get((int) $id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * กรองตัวเลือกให้เหลือเฉพาะที่ผูกกับเมนูนี้จริง แล้วคืนส่วนต่างราคา
     *
     * กรองตั้งแต่ตอนใส่ตะกร้า ไม่ใช่ตอนส่งครัว เพราะยอดที่โชว์ในตะกร้า
     * ต้องเป็นยอดเดียวกับที่จะขึ้นบิล ถ้ากรองทีหลังลูกค้าจะเห็นยอดหนึ่งแล้วจ่ายอีกยอด
     *
     * @param  array<int, int>  $modifierIds
     * @return array{0: array<int, int>, 1: float}
     */
    protected function resolveModifiers(Product $product, array $modifierIds): array
    {
        if ($modifierIds === []) {
            return [[], 0.0];
        }

        $allowed = $product->activeModifierGroups
            ->flatMap->modifiers
            ->keyBy('id');

        $ids = [];
        $delta = 0.0;

        foreach (array_unique(array_map('intval', $modifierIds)) as $id) {
            $modifier = $allowed->get($id);

            if (! $modifier) {
                continue;
            }

            $ids[] = $id;
            $delta += (float) $modifier->price_delta;
        }

        sort($ids);

        return [$ids, round($delta, 2)];
    }

    protected function clearSubmitFlags(TableSession $session): void
    {
        $session->forceFill([
            'cart_submit_at' => null,
            'cart_submit_by' => null,
            'cart_submit_name' => null,
        ])->save();
    }
}
