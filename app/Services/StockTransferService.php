<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Models\Branch;
use App\Models\BranchStockItem;
use App\Models\StockItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * โอนของระหว่างสถานี — สองขั้น ส่ง แล้วค่อยรับ
 *
 * ── ทำไมแยกออกมาจาก StockService ──────────────────────────
 * StockService ดูแล "ความเคลื่อนไหวหนึ่งรายการ" ซึ่งเกิดกับสาขาเดียวเสมอ
 * ส่วนการโอนเป็นเรื่องของสองสาขาและกินเวลาข้ามวันได้ มีสถานะของตัวเอง
 * ยัดรวมกันจะทำให้ StockService ต้องรู้เรื่องใบโอน ทั้งที่ทุกอย่างที่มันทำคือ +/- ยอด
 *
 * ── ลำดับการล็อกแถว ───────────────────────────────────────
 * ทุกเมธอดในคลาสนี้แตะสต๊อกของ **สาขาเดียว** ต่อการเรียกหนึ่งครั้ง
 *   ส่ง    -> ล็อกเฉพาะต้นทาง
 *   รับ    -> ล็อกเฉพาะปลายทาง
 *   ยกเลิก -> ล็อกเฉพาะต้นทาง (ของกลับเข้าที่เดิม)
 * จึงไม่มีทางที่สองสาขาจะล็อกไขว้กันจนค้าง ซึ่งเป็นข้อดีที่ได้มาฟรีจากการทำสองขั้น
 * ภายในสาขาเดียวกันยังเรียงล็อกตาม stock_item_id เสมอ กันสองใบที่มีของซ้ำกันค้างกันเอง
 */
class StockTransferService
{
    public function __construct(protected StockService $stock) {}

    /**
     * ขั้นที่ 1 — ต้นทางกดส่ง ตัดของออกจากสต๊อกต้นทางทันที
     *
     * @param  array<int, array{stock_item_id: int|string, qty: float|string}>  $lines
     *
     * @throws ValidationException
     */
    public function send(Branch $from, Branch $to, array $lines, ?string $note = null): StockTransfer
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_branch_id' => 'ต้นทางกับปลายทางเป็นสถานีเดียวกันไม่ได้']);
        }

        if (! $to->is_active) {
            throw ValidationException::withMessages(['to_branch_id' => 'สถานีปลายทางถูกปิดใช้งานอยู่']);
        }

        $wanted = $this->normalise($lines);

        if (! $wanted) {
            throw ValidationException::withMessages(['items' => 'ต้องเลือกของอย่างน้อยหนึ่งรายการ']);
        }

        return DB::transaction(function () use ($from, $to, $wanted, $note) {
            $items = $this->transferableItems(array_keys($wanted));

            foreach (array_keys($wanted) as $id) {
                if (! $items->has($id)) {
                    throw ValidationException::withMessages([
                        'items' => 'มีของที่โอนไม่ได้อยู่ในรายการ — โอนได้เฉพาะของกลางที่ทุกสถานีใช้ร่วมกัน',
                    ]);
                }
            }

            $businessDate = $from->businessDateFor()->toDateString();

            $transfer = StockTransfer::create([
                'ref_no' => $this->nextRefNo($from, $businessDate),
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'status' => StockTransferStatus::InTransit,
                'note' => $note,
                'sent_by' => auth()->id(),
                'sent_at' => now(),
                'business_date' => $businessDate,
            ]);

            // เรียงตาม id เสมอ — ลำดับการล็อกต้องเหมือนกันทุกใบ ไม่งั้นสองใบที่มีของซ้ำกันจะค้างกันเอง
            ksort($wanted);

            foreach ($wanted as $stockItemId => $qty) {
                $item = $items->get($stockItemId);

                $stock = BranchStockItem::where('branch_id', $from->id)
                    ->where('stock_item_id', $stockItemId)
                    ->lockForUpdate()
                    ->first();

                $available = (float) ($stock?->stock_qty ?? 0);

                /*
                | ห้ามโอนเกินที่มี — ของที่ไม่มีอยู่จริงยกขึ้นรถไม่ได้
                |
                | ต่างจากการตัดสต๊อกตอนขายที่ยอมให้ติดลบ เพราะตอนนั้นของถูกกินไปแล้วจริง ๆ
                | ระบบแค่ตามบันทึกไม่ทัน แต่การโอนเป็นการกระทำที่คนกดตอนนี้ กันไว้ก่อนได้
                */
                if ($qty > $available + 0.0001) {
                    throw ValidationException::withMessages([
                        'items' => sprintf(
                            '%s มีอยู่ %s %s แต่สั่งโอน %s %s',
                            $item->name,
                            rtrim(rtrim(number_format($available, 3), '0'), '.'),
                            $item->unitLabel(),
                            rtrim(rtrim(number_format($qty, 3), '0'), '.'),
                            $item->unitLabel(),
                        ),
                    ]);
                }

                // ต้นทุน ณ วินาทีนี้ ติดไปกับของ ไม่ใช่ไปอ่านใหม่ตอนปลายทางกดรับ
                $unitCost = (float) ($stock?->cost_per_unit ?? 0);

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'stock_item_id' => $stockItemId,
                    'qty_sent' => $qty,
                    'qty_received' => null,
                    'unit_cost' => $unitCost,
                ]);

                $this->stock->move(
                    $item,
                    $from->id,
                    StockMovementType::TransferOut,
                    -1 * $qty,
                    $unitCost,
                    reference: $transfer,
                    note: 'โอนไป'.$to->name.' ('.$transfer->ref_no.')',
                );
            }

            return $transfer->load('items.stockItem');
        });
    }

    /**
     * ขั้นที่ 2 — ปลายทางกดรับ ของเข้าสต๊อกปลายทางตอนนี้
     *
     * @param  array<int|string, float|string>  $received  stock_transfer_item id => จำนวนที่รับได้จริง
     *
     * @throws ValidationException
     */
    public function receive(StockTransfer $transfer, array $received = [], ?string $note = null): StockTransfer
    {
        $this->guardOpen($transfer);

        return DB::transaction(function () use ($transfer, $received, $note) {
            $transfer->load('items.stockItem');

            $lines = $transfer->items->sortBy('stock_item_id');
            $touched = [];

            foreach ($lines as $line) {
                $sent = (float) $line->qty_sent;

                /*
                | ไม่ได้กรอกมา = รับครบตามที่ส่ง (กรณีปกติ กดปุ่มเดียวจบ)
                | กรอกมา = ตัดให้อยู่ในช่วง 0 ถึงจำนวนที่ส่ง
                |
                | รับมากกว่าที่ส่งไม่ได้ เพราะของส่วนเกินไม่เคยถูกตัดออกจากต้นทาง
                | ถ้ายอมให้กรอกเกิน ของจะงอกขึ้นมาเองในระบบ
                */
                $qty = array_key_exists($line->id, $received)
                    ? max(0.0, min($sent, (float) $received[$line->id]))
                    : $sent;

                $line->qty_received = $qty;
                $line->save();

                if ($qty <= 0 || ! $line->stockItem) {
                    continue;
                }

                $this->stock->move(
                    $line->stockItem,
                    $transfer->to_branch_id,
                    StockMovementType::TransferIn,
                    $qty,
                    (float) $line->unit_cost,
                    reference: $transfer,
                    note: 'รับโอนจาก'.$transfer->fromBranch?->name.' ('.$transfer->ref_no.')',
                );

                $touched[] = $line->stock_item_id;
            }

            /*
            | สถานีที่เคยปิดใช้ของชิ้นนี้ไว้ ต้องกลับมาเปิดเมื่อมีของเข้าจริง
            | ไม่งั้นของจะอยู่ในสต๊อกแต่ไม่โผล่ในหน้าคลังของสถานีนั้น — หาไม่เจอทั้งที่มีอยู่
            */
            if ($touched) {
                BranchStockItem::where('branch_id', $transfer->to_branch_id)
                    ->whereIn('stock_item_id', $touched)
                    ->update(['is_active' => true]);
            }

            $transfer->update([
                'status' => StockTransferStatus::Received,
                'received_by' => auth()->id(),
                'received_at' => now(),
                'note' => $note ?: $transfer->note,
            ]);

            return $transfer->refresh()->load('items.stockItem');
        });
    }

    /**
     * ยกเลิกใบที่ยังอยู่ระหว่างทาง — ของกลับเข้าต้นทางเต็มจำนวน
     *
     * ใช้กับกรณีที่ของไม่ได้ออกไปจริง (ยกเลิกก่อนรถออก) หรือถูกตีกลับมาทั้งชุด
     * คืนด้วยต้นทุนเดิมที่ติดไปกับใบ ต้นทุนเฉลี่ยของต้นทางจึงกลับมาเท่าเดิม
     *
     * @throws ValidationException
     */
    public function cancel(StockTransfer $transfer, ?string $reason = null): StockTransfer
    {
        $this->guardOpen($transfer);

        return DB::transaction(function () use ($transfer, $reason) {
            $transfer->load('items.stockItem');

            foreach ($transfer->items->sortBy('stock_item_id') as $line) {
                if (! $line->stockItem || (float) $line->qty_sent <= 0) {
                    continue;
                }

                $this->stock->move(
                    $line->stockItem,
                    $transfer->from_branch_id,
                    StockMovementType::TransferIn,
                    (float) $line->qty_sent,
                    (float) $line->unit_cost,
                    reference: $transfer,
                    note: 'ยกเลิกใบโอน '.$transfer->ref_no,
                );
            }

            $transfer->update([
                'status' => StockTransferStatus::Cancelled,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $transfer->refresh()->load('items.stockItem');
        });
    }

    /**
     * ของที่โอนได้ = **ของกลางเท่านั้น**
     *
     * ของที่ผูกสถานี (stock_items.branch_id != null) โอนไม่ได้ตามนิยาม
     * เพราะปลายทางมองไม่เห็นของชิ้นนั้นตั้งแต่แรก ถ้าฝืนโอนไป ปลายทางจะมีสต๊อก
     * ที่เปิดหน้าคลังแล้วไม่เจอ และผูกเข้าสูตรไม่ได้
     * ถ้าอยากโอนจริง ต้องแก้ของชิ้นนั้นให้เป็นของกลางก่อน
     *
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, StockItem>
     */
    public function transferableItems(array $ids = []): \Illuminate\Support\Collection
    {
        return StockItem::whereNull('branch_id')
            ->where('is_active', true)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get()
            ->keyBy('id');
    }

    /**
     * เลขที่ใบโอน — TR-YYYYMMDD-{สถานีต้นทาง}-{ลำดับ}
     *
     * ใส่ id ของสถานีต้นทางไว้ในเลข ไม่ใช่รหัสสถานี เพราะรหัสสถานีเก่าบางตัวเป็นภาษาไทย
     * และเลขชุดนี้ถูกเอาไปตั้งชื่อไฟล์/ค้นหาอยู่เรื่อย ๆ
     *
     * ใช้ orderByDesc + lockForUpdate แทน max() ด้วยเหตุผลเดียวกับเลขใบเสร็จ —
     * max() ไม่ล็อกอะไรเลย สองคนกดพร้อมกันจะได้เลขเดียวกันแล้วชน unique
     */
    protected function nextRefNo(Branch $from, string $businessDate): string
    {
        $prefix = 'TR-'.str_replace('-', '', $businessDate).'-'.$from->id.'-';

        $last = StockTransfer::where('ref_no', 'like', $prefix.'%')
            ->orderByDesc('ref_no')
            ->lockForUpdate()
            ->value('ref_no');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * รวมบรรทัดที่ซ้ำกันและตัดบรรทัดที่ไม่มีจำนวนทิ้ง
     *
     * หน้าเว็บส่งของชิ้นเดียวกันมาสองบรรทัดได้ ถ้าไม่รวมก่อน จะชน
     * unique(stock_transfer_id, stock_item_id) แล้วทั้งใบล้ม
     *
     * @param  array<int, array{stock_item_id: int|string, qty: float|string}>  $lines
     * @return array<int, float>  stock_item_id => จำนวนรวม
     */
    protected function normalise(array $lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            $id = (int) ($line['stock_item_id'] ?? 0);
            $qty = round((float) ($line['qty'] ?? 0), 3);

            if ($id <= 0 || $qty <= 0) {
                continue;
            }

            $out[$id] = round(($out[$id] ?? 0) + $qty, 3);
        }

        return $out;
    }

    /** @throws ValidationException */
    protected function guardOpen(StockTransfer $transfer): void
    {
        if (! $transfer->status->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'ใบโอนนี้'.$transfer->status->label().'แล้ว แก้ไม่ได้อีก',
            ]);
        }
    }
}
