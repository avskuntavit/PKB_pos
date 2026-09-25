<?php

namespace App\Services;

use App\Enums\OfflineEntryKind;
use App\Models\Branch;
use App\Models\BranchStockItem;
use App\Models\OfflineSyncEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * รับคิวที่ค้างอยู่ในแท็บเล็ตตอนเน็ตหลุด แล้วเล่นย้อนทีละรายการ
 *
 * ── ทำไมเป็น endpoint เดียวรับทั้งก้อน ไม่ยิงทีละรายการเหมือนตอนออนไลน์ ──
 * 1) ลำดับสำคัญ — ต้องเพิ่มรายการให้ครบก่อนแล้วค่อยส่งครัว ถ้าปล่อยให้เบราว์เซอร์
 *    ยิงทีละใบแบบขนาน คำสั่งส่งครัวอาจถึงก่อนรายการสุดท้าย แล้วของจานนั้นค้างบิล
 * 2) endpoint เดิมทั้งหมดตอบเป็น Inertia redirect ซึ่งออกแบบมาให้ "หน้าเปลี่ยนตาม"
 *    การเรียกมันจากเบื้องหลังได้ HTML ทั้งหน้ากลับมาเปล่า ๆ ทุกครั้ง
 * 3) เทสต์ได้จริง — ยิงคิวทั้งก้อนเข้ามาใบเดียวแล้วดูผลลัพธ์รายรายการ
 *
 * ── ด่านกันอาหารเข้าครัวสองรอบ ────────────────────────────────────────────
 * เครื่องที่คีย์เป็นคนสุ่ม uuid ให้แต่ละรายการตั้งแต่ตอนกด ที่นี่ insert แถวนั้นก่อน
 * ทำงานจริง ถ้า insert ชนคีย์ซ้ำแปลว่ารายการนี้เคยส่งสำเร็จไปแล้ว — ข้ามไปเลย
 *
 * ด่านอยู่ที่ unique index ของฐานข้อมูล **ไม่ใช่** การ select เช็คก่อน insert
 * เพราะสองคำขอที่มาถึงพร้อมกันจะ select เห็นว่า "ยังไม่มี" ทั้งคู่ แล้วทำงานทั้งคู่
 *
 * ── รายการหนึ่งล้ม ไม่ลากรายการอื่นล้มตาม ────────────────────────────────
 * ตั้งใจให้เป็นแบบนี้ ของที่คีย์ตอนหลุดคือของที่ลูกค้ากินไปแล้วจริง ๆ
 * ถ้ารายการที่สามพลาดเพราะเมนูถูกปิดขายระหว่างนั้น อีกเก้ารายการต้องขึ้นบิลให้ได้
 * แล้วรายงานกลับไปให้พนักงานเห็นว่าใบไหนตกและเพราะอะไร
 */
class OfflineSyncService
{
    /** กันเครื่องที่ค้างมานานส่งมาทีเดียวจนคำขอเดียวทำงานนานเกินไป */
    public const MAX_ENTRIES = 100;

    public function __construct(
        protected OrderService $orders,
        protected PaymentService $payments,
        protected StockService $stock,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>  ผลลัพธ์รายรายการ เรียงตามที่รับมา
     */
    public function sync(array $entries, User $user, Branch $branch): array
    {
        $results = [];

        foreach ($entries as $entry) {
            $results[] = $this->one($entry, $user, $branch);
        }

        return $results;
    }

    /**
     * ทำรายการเดียว แล้วคืนผลในรูปที่หน้าจอเอาไปตัดสินใจต่อได้
     *
     * @param  array<string, mixed>  $entry
     * @return array{uuid: string, status: string, message: ?string}
     */
    protected function one(array $entry, User $user, Branch $branch): array
    {
        $uuid = (string) ($entry['uuid'] ?? '');
        $kind = OfflineEntryKind::tryFrom((string) ($entry['kind'] ?? ''));

        if ($kind === null) {
            return $this->result($uuid, OfflineSyncEntry::STATUS_FAILED, 'ชนิดรายการนี้ระบบไม่รู้จัก');
        }

        $row = $this->claim($entry, $uuid, $kind, $user, $branch);

        // จองไม่ได้ = มีแถวนี้อยู่แล้ว = เคยส่งสำเร็จไปแล้ว
        if ($row === null) {
            return $this->result($uuid, OfflineSyncEntry::STATUS_DUPLICATE, 'รายการนี้ส่งขึ้นระบบไปแล้ว');
        }

        try {
            $order = $this->orderFor($entry, $branch);

            $message = match ($kind) {
                OfflineEntryKind::AddItem => $this->addItem($order, (array) ($entry['payload'] ?? [])),
                OfflineEntryKind::SendKitchen => $this->sendKitchen($order),
                OfflineEntryKind::PayCash => $this->payCash($order, $row),
            };
        } catch (ValidationException $e) {
            return $this->fail($row, collect($e->errors())->flatten()->first() ?? $e->getMessage());
        } catch (Throwable $e) {
            /*
            | จับกว้างโดยตั้งใจ — ของที่คีย์ตอนหลุดคือของที่ลูกค้ากินไปแล้ว
            | ปล่อย exception ทะลุขึ้นไปจะทำให้อีกเก้ารายการในคิวเดียวกันไม่ได้ลง
            | และพนักงานจะไม่มีทางรู้ว่าตกไปกี่รายการ เพราะหน้าจอได้แค่ error 500
            */
            return $this->fail($row, $e->getMessage() ?: 'บันทึกไม่สำเร็จ');
        }

        $row->update(['status' => OfflineSyncEntry::STATUS_APPLIED, 'message' => $message]);

        return $this->result($uuid, OfflineSyncEntry::STATUS_APPLIED, $message, $row->warning);
    }

    /**
     * จองสิทธิ์ทำรายการนี้ — null = มีคนทำไปแล้ว
     *
     * @param  array<string, mixed>  $entry
     */
    protected function claim(
        array $entry,
        string $uuid,
        OfflineEntryKind $kind,
        User $user,
        Branch $branch,
    ): ?OfflineSyncEntry {
        try {
            return OfflineSyncEntry::create([
                'uuid' => $uuid,
                'branch_id' => $branch->id,
                'user_id' => $user->getAuthIdentifier(),
                'kind' => $kind,
                'order_id' => (int) ($entry['order_id'] ?? 0),
                'payload' => $entry['payload'] ?? null,
                'status' => OfflineSyncEntry::STATUS_FAILED,
                // เก็บยอดเงินไว้ตั้งแต่ตอนจอง ไม่ใช่ตอนสำเร็จ — ถ้ารอจนสำเร็จ
                // ใบที่ล้มจะไม่มีตัวเลขติดมาเลย แล้วผู้จัดการจะไม่รู้ว่าเงินในลิ้นชักเท่าไหร่
                'amount' => $this->receivedAmount($kind, $entry['payload'] ?? null),
                'client_at' => $this->clientTime($entry['at'] ?? null),
            ]);
        } catch (QueryException) {
            // คีย์ซ้ำคือกรณีเดียวที่คาดไว้ตรงนี้ และเป็นผลลัพธ์ที่ถูกต้อง ไม่ใช่ error
            return null;
        }
    }

    /**
     * บิลที่รายการนี้จะไปลง
     *
     * เช็คสาขาที่นี่ ไม่ได้เชื่อ order_id ที่ส่งมา — แท็บเล็ตที่ถูกสลับสาขาระหว่างหลุด
     * (หรือคนที่ยิง request ตรง) จะยัดรายการข้ามสาขาได้ถ้าไม่มีด่านนี้
     *
     * @param  array<string, mixed>  $entry
     */
    protected function orderFor(array $entry, Branch $branch): Order
    {
        $order = Order::where('branch_id', $branch->id)
            ->whereKey((int) ($entry['order_id'] ?? 0))
            ->first();

        if (! $order) {
            throw new \DomainException('ไม่พบบิลนี้ในสาขาที่กำลังทำงานอยู่');
        }

        return $order;
    }

    /** @param  array<string, mixed>  $payload */
    protected function addItem(Order $order, array $payload): string
    {
        $product = Product::find((int) ($payload['product_id'] ?? 0));

        if (! $product) {
            throw new \DomainException('ไม่พบเมนูนี้แล้ว อาจถูกลบไประหว่างที่เครื่องหลุด');
        }

        $qty = (float) ($payload['qty'] ?? 0);

        if ($qty <= 0) {
            throw new \DomainException('จำนวนต้องมากกว่า 0');
        }

        $ids = array_values(array_map('intval', (array) ($payload['modifier_ids'] ?? [])));
        $note = $payload['note'] ?? null;
        $openPrice = isset($payload['open_price']) ? (float) $payload['open_price'] : null;

        /*
        | ราคาคิดใหม่ที่นี่ทั้งหมด ไม่ได้เอาราคาที่แท็บเล็ตจำไว้ตอนหลุดมาใช้
        | ถ้าร้านขึ้นราคาระหว่างที่เครื่องหลุด บิลต้องใช้ราคาปัจจุบัน
        | (ยกเว้นเมนูราคาเปิด ซึ่งพนักงานเป็นคนกรอกตัวเลขเอง จึงส่งค่านั้นต่อไป)
        */
        $item = $this->orders->addItem($order, $product, $qty, $ids, $note, $openPrice);

        return $item->product_name.' × '.rtrim(rtrim(number_format((float) $item->qty, 2, '.', ''), '0'), '.');
    }

    /**
     * ส่งครัว — ตอนหลุดส่งได้แค่ "ทุกอย่างที่ค้าง" เท่านั้น
     *
     * เลือกส่งเฉพาะบางคอร์สไม่ได้ เพราะรายการที่คีย์ตอนหลุดยังไม่มี id ของเซิร์ฟเวอร์
     * ให้เลือกไม่ได้ตั้งแต่บนหน้าจอ จะตรงไปตรงมากว่าการรับ id มาแล้วมาพบทีหลังว่าใช้ไม่ได้
     */
    protected function sendKitchen(Order $order): string
    {
        $count = $this->orders->sendToKitchen($order, null);

        if ($count === 0) {
            throw new \DomainException('ไม่มีรายการที่ส่งครัวได้ อาจมีคนส่งไปแล้วระหว่างที่เครื่องหลุด');
        }

        return "ส่งครัว {$count} รายการ";
    }

    /**
     * รับเงินสดที่พนักงานเก็บไปแล้วตอนเน็ตหลุด
     *
     * ── ยอดที่ลงบิลคือยอดของเซิร์ฟเวอร์ ไม่ใช่ยอดที่แท็บเล็ตจำไว้ ──────────
     * หน้าจอปล่อยให้กดรับเงินตอนหลุดได้เฉพาะบิลที่ไม่มีรายการค้างในเครื่อง
     * ยอดที่พนักงานเห็นจึงเป็นยอดที่เซิร์ฟเวอร์คิดมาแล้วทั้งก้อน (รวม VAT ค่าบริการ ส่วนลด)
     * ถ้าระหว่างนั้นมีคนแก้บิลจากอีกเครื่อง ยอดจะไม่ตรง — ตรวจตรงนี้แล้วปฏิเสธ
     * เพราะลงบิลด้วยยอดที่ลูกค้าไม่ได้จ่ายคือการทำให้ทั้งวันกระทบยอดไม่ได้
     *
     * ── เงินทอนคิดใหม่ที่นี่ ──────────────────────────────────────────────
     * PaymentService คิดเงินทอนเองจาก received − grand_total อยู่แล้ว
     * ตัวเลขที่แท็บเล็ตโชว์ให้ลูกค้าดูจึงเป็นแค่ของแสดงผล ถ้าสองค่านี้ไม่ตรงกัน
     * แปลว่ายอดบิลเปลี่ยน ซึ่งด่านข้างบนจับได้ก่อนแล้ว
     */
    protected function payCash(Order $order, OfflineSyncEntry $row): string
    {
        $payload = (array) ($row->payload ?? []);

        $received = round((float) ($payload['received'] ?? 0), 2);
        $expected = round((float) ($payload['expected_total'] ?? 0), 2);
        $actual = round((float) $order->grand_total, 2);

        if ($received <= 0) {
            throw new \DomainException('ไม่ได้ระบุจำนวนเงินที่รับมา');
        }

        /*
        | ยอดบิลเปลี่ยนไประหว่างที่เครื่องหลุด
        |
        | คลาดกันได้ไม่เกินหนึ่งสตางค์ (การปัดเศษของทั้งสองฝั่ง) เกินกว่านั้น
        | แปลว่ามีคนแก้บิลจริง ๆ — ต้องไม่ลงเงียบ ๆ ให้ผู้จัดการมาดูว่าเกิดอะไรขึ้น
        */
        if (abs($expected - $actual) > 0.01) {
            throw new \DomainException(
                'ยอดบิลเปลี่ยนไประหว่างที่เครื่องหลุด (ตอนเก็บเงิน '
                .number_format($expected, 2).' ตอนนี้ '.number_format($actual, 2).')'
            );
        }

        if ($received + 0.01 < $actual) {
            throw new \DomainException(
                'เงินที่รับมาน้อยกว่ายอดบิล ('.number_format($received, 2).' < '.number_format($actual, 2).')'
            );
        }

        // เก็บ id ของวัตถุดิบไว้ก่อนตัด เพื่อไปดูหลังตัดว่าตัวไหนติดลบ
        $stockItemIds = $this->stockItemsUsedBy($order);

        $this->payments->pay($order, [[
            'method' => 'cash',
            'amount' => $actual,
            'received' => $received,
        ]]);

        $order->refresh();

        if ($warning = $this->negativeStockWarning($order, $stockItemIds)) {
            $row->update(['warning' => mb_substr($warning, 0, 255)]);
        }

        return 'ปิดบิลแล้ว เลขใบเสร็จ '.$order->receipt_no;
    }

    /**
     * วัตถุดิบที่บิลนี้จะไปตัด
     *
     * ต้องเก็บรายชื่อไว้ **ก่อน** ปิดบิล เพราะคำถามที่ต้องตอบคือ
     * "ของที่บิลนี้ใช้ ตัวไหนติดลบ" ไม่ใช่ "ในสาขามีอะไรติดลบบ้าง"
     * (อย่างหลังจะรวมของที่ติดลบมาตั้งแต่เมื่อวานด้วย ซึ่งไม่เกี่ยวกับบิลนี้)
     *
     * @return array<int, int>
     */
    protected function stockItemsUsedBy(Order $order): array
    {
        // load() ไม่ใช่ loadMissing() ด้วยเหตุผลเดียวกับ StockService::deductForOrder()
        // ตัวนี้ทำงานก่อน recalculate() ก็จริง แต่ไม่ควรมีโค้ดที่ "ถูกโดยบังเอิญเพราะลำดับ"
        $order->load([
            'activeItems.product.recipeItems',
            'activeItems.modifiers.modifier.recipeItems',
        ]);

        $ids = [];

        foreach ($order->activeItems as $item) {
            foreach ($this->stock->usageForItem($item, (int) $order->branch_id) as $stockItemId => $qty) {
                $ids[(int) $stockItemId] = (int) $stockItemId;
            }
        }

        return array_values($ids);
    }

    /**
     * ตัดสต๊อกแล้วมีอะไรติดลบบ้าง
     *
     * ── ทำไมเตือน ไม่ใช่ห้าม ─────────────────────────────────────────────
     * ตอนออนไลน์ระบบก็ปล่อยให้ติดลบอยู่แล้ว (ของจริงในครัวกับตัวเลขในระบบ
     * ไม่เคยตรงกันเป๊ะ และการห้ามปิดบิลเพราะตัวเลขไม่ตรงคือการจับลูกค้าเป็นตัวประกัน)
     * ตอนหลุดจึงต้องทำเหมือนกัน ไม่งั้นพฤติกรรมของระบบจะขึ้นกับว่าเน็ตดีหรือเปล่า
     *
     * แต่ติดลบแปลว่า "ของหมดไปแล้วแต่ยังขายอยู่" ซึ่งผู้จัดการต้องรู้ จึงเตือนไว้
     *
     * @param  array<int, int>  $stockItemIds
     */
    protected function negativeStockWarning(Order $order, array $stockItemIds): ?string
    {
        if ($stockItemIds === []) {
            return null;
        }

        $negative = BranchStockItem::where('branch_id', $order->branch_id)
            ->whereIn('stock_item_id', $stockItemIds)
            ->where('stock_qty', '<', 0)
            ->with('stockItem:id,name')
            ->get();

        if ($negative->isEmpty()) {
            return null;
        }

        $names = $negative->take(3)
            ->map(fn ($row) => ($row->stockItem?->name ?? 'ของชิ้นที่ '.$row->stock_item_id)
                .' ('.rtrim(rtrim((string) $row->stock_qty, '0'), '.').')')
            ->implode(' · ');

        $more = $negative->count() > 3 ? ' และอีก '.($negative->count() - 3).' รายการ' : '';

        return 'ตัดสต๊อกแล้ววัตถุดิบติดลบ: '.$names.$more;
    }

    /**
     * ใบนี้ทำไม่สำเร็จ
     *
     * ── ใบที่แทนเงินไม่ได้จบแค่ "ล้ม" ───────────────────────────────────
     * เงินอยู่ในลิ้นชักไปแล้วจริง ๆ ลูกค้าเดินออกจากร้านไปแล้ว การบอกพนักงานว่า
     * "ไม่สำเร็จ คีย์ใหม่สิ" จึงไม่มีความหมาย เพราะไม่มีลูกค้าให้เก็บเงินอีกรอบ
     *
     * ใบพวกนี้จึงกลายเป็น held = งานที่ผู้จัดการต้องมาตัดสิน
     * และจะไม่ถูกส่งซ้ำจากเครื่องอีก เพราะเซิร์ฟเวอร์รับรู้เรื่องมันแล้ว
     *
     * @return array{uuid: string, status: string, message: ?string, warning: ?string}
     */
    protected function fail(OfflineSyncEntry $row, string $message): array
    {
        $message = mb_substr($message, 0, 255);

        $status = $row->kind->movesMoney()
            ? OfflineSyncEntry::STATUS_HELD
            : OfflineSyncEntry::STATUS_FAILED;

        $row->update(['status' => $status, 'message' => $message]);

        return $this->result($row->uuid, $status, $message);
    }

    /** @return array{uuid: string, status: string, message: ?string, warning: ?string} */
    protected function result(string $uuid, string $status, ?string $message, ?string $warning = null): array
    {
        return ['uuid' => $uuid, 'status' => $status, 'message' => $message, 'warning' => $warning];
    }

    /**
     * ยอดเงินที่พนักงานรับมาจากมือลูกค้า
     *
     * อ่านจาก received ไม่ใช่ amount — ที่พนักงานถืออยู่คือแบงก์ที่ลูกค้ายื่นให้
     * ส่วน amount คือยอดบิล ซึ่งเงินทอนยังไม่ได้หักออก
     */
    protected function receivedAmount(OfflineEntryKind $kind, mixed $payload): ?float
    {
        if (! $kind->movesMoney() || ! is_array($payload)) {
            return null;
        }

        return isset($payload['received']) ? round((float) $payload['received'], 2) : null;
    }

    /**
     * เวลาที่พนักงานกด ตามนาฬิกาของแท็บเล็ต
     *
     * เก็บไว้เฉย ๆ เพื่อดูย้อนหลัง **ไม่เอาไปใช้ตัดสินอะไรทั้งสิ้น**
     * นาฬิกาแท็บเล็ตตั้งผิดปีได้ และไม่มีใครมาคอยตรวจ ถ้าเอาไปคิด business_date
     * หรือเรียงลำดับ ยอดขายทั้งวันจะเพี้ยนโดยไม่มีใครรู้ว่าเพราะอะไร
     */
    protected function clientTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
