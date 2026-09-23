<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\StockTransferStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStockItem;
use App\Models\StockItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\StockTransferService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * โอนของระหว่างสถานี
 *
 * ── ด่านกันข้ามสถานีของหน้านี้ ────────────────────────────
 * ใบโอนเป็นของสองสาขาพร้อมกัน จึงเช็คแยกตามสิ่งที่จะทำ ไม่ใช่เช็คครั้งเดียวรวม ๆ
 *   ส่ง / ยกเลิก -> ต้องมีสิทธิ์ที่ **ต้นทาง** (คนที่ของออกจากมือ)
 *   รับ          -> ต้องมีสิทธิ์ที่ **ปลายทาง** (คนที่ของเข้ามือ)
 *   ดูรายการ     -> เห็นได้ถ้าเป็นต้นทางหรือปลายทางฝั่งใดฝั่งหนึ่ง
 *
 * ผู้จัดการมี accessibleBranchIds() แค่สาขาตัวเอง จึงโอนออกได้เฉพาะของร้านตัวเอง
 * และรับได้เฉพาะของที่ส่งมาหาตัวเอง — ตรงตามที่ตั้งใจโดยไม่ต้องเขียนเงื่อนไขเพิ่ม
 */
class StockTransferController extends Controller
{
    public function index(Request $request, StockTransferService $transfers): Response
    {
        $branch = CurrentBranch::getOrFail();
        $allowed = $request->user()->accessibleBranchIds();

        // 'out' = ของที่สถานีนี้ส่งออกไป · 'in' = ของที่คนอื่นส่งมาให้สถานีนี้
        $direction = $request->input('direction') === 'in' ? 'in' : 'out';
        $status = StockTransferStatus::tryFrom((string) $request->input('status'));

        $list = StockTransfer::with([
            'items.stockItem:id,name,unit',
            'fromBranch:id,name,code',
            'toBranch:id,name,code',
            'sender:id,name',
            'receiver:id,name',
        ])
            // กันไว้อีกชั้นแม้จะกรองด้วยสาขาปัจจุบันอยู่แล้ว — เผื่อวันหน้ามีคนเพิ่มตัวกรองแล้วลืมข้อนี้
            ->visibleTo($allowed)
            ->when(
                $direction === 'out',
                fn ($q) => $q->sentFrom($branch->id),
                fn ($q) => $q->sentTo($branch->id),
            )
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockTransfer $transfer) => $this->payload($transfer, $allowed));

        return Inertia::render('BackOffice/StockTransfers/Index', [
            'transfers' => $list,
            'direction' => $direction,
            'statuses' => StockTransferStatus::options(),
            'filters' => $request->only('direction', 'status'),
            // ปลายทางที่เลือกได้ — สถานีที่เปิดใช้งานอยู่ ยกเว้นตัวเอง
            'destinations' => Branch::where('is_active', true)
                ->where('id', '!=', $branch->id)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'stockItems' => $this->sendableStock($transfers, $branch),
            // ตัวเลขบนแท็บ "ขาเข้า" ให้เห็นว่ามีของรออยู่กี่ใบโดยไม่ต้องกดเข้าไปดู
            'pendingIn' => StockTransfer::sentTo($branch->id)
                ->where('status', StockTransferStatus::InTransit->value)
                ->count(),
        ]);
    }

    /** ขั้นที่ 1 — ส่งของออกจากสถานีที่กำลังเปิดดูอยู่ */
    public function store(Request $request, StockTransferService $transfers): RedirectResponse
    {
        $from = CurrentBranch::getOrFail();

        $this->guardBranch($request, $from->id);

        $data = $request->validate([
            'to_branch_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.stock_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        $to = Branch::findOrFail($data['to_branch_id']);

        $transfer = $transfers->send($from, $to, $data['items'], $data['note'] ?? null);

        return back()->with('success', "ส่งของไป{$to->name}แล้ว เลขที่ {$transfer->ref_no} — ของจะเข้าสต๊อกปลายทางเมื่อปลายทางกดรับ");
    }

    /** ขั้นที่ 2 — ปลายทางกดรับ */
    public function receive(Request $request, StockTransfer $transfer, StockTransferService $transfers): RedirectResponse
    {
        $this->guardBranch($request, (int) $transfer->to_branch_id);

        $data = $request->validate([
            'received' => ['array'],
            'received.*' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $transfers->receive($transfer, $data['received'] ?? [], $data['note'] ?? null);

        $transfer->refresh()->load('items');

        return back()->with('success', $transfer->hasShortfall()
            ? 'รับของเข้าสต๊อกแล้ว — มีของรับได้ไม่ครบ ดูส่วนต่างบนใบโอน'
            : 'รับของเข้าสต๊อกแล้ว');
    }

    /** ยกเลิกใบที่ยังอยู่ระหว่างทาง — ของกลับเข้าต้นทาง */
    public function cancel(Request $request, StockTransfer $transfer, StockTransferService $transfers): RedirectResponse
    {
        $this->guardBranch($request, (int) $transfer->from_branch_id);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $transfers->cancel($transfer, $data['reason'] ?? null);

        return back()->with('success', 'ยกเลิกใบโอนแล้ว ของกลับเข้าสต๊อกต้นทางเต็มจำนวน');
    }

    /** คนนี้มีสิทธิ์ทำงานแทนสาขานี้ไหม */
    protected function guardBranch(Request $request, int $branchId): void
    {
        abort_unless(
            in_array($branchId, $request->user()->accessibleBranchIds(), true),
            403,
            'ไม่มีสิทธิ์จัดการของของสถานีนี้',
        );
    }

    /**
     * ของที่ส่งออกได้จากสถานีนี้ — ของกลางที่สถานีนี้มียอดคงเหลืออยู่จริง
     *
     * ของที่เหลือ 0 ไม่ต้องส่งมาให้เลือก เพราะกดยังไงก็โดนปฏิเสธ
     * ให้เห็นแล้วกดไม่ได้ น่าหงุดหงิดกว่าไม่เห็นเลย
     */
    protected function sendableStock(StockTransferService $transfers, Branch $branch): array
    {
        $levels = BranchStockItem::where('branch_id', $branch->id)
            ->where('stock_qty', '>', 0)
            ->pluck('stock_qty', 'stock_item_id');

        return $transfers->transferableItems()
            ->filter(fn (StockItem $item) => $levels->has($item->id))
            ->map(fn (StockItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unitLabel(),
                'on_hand' => (float) $levels[$item->id],
            ])
            ->values()
            ->all();
    }

    /** @param  array<int, int>  $allowed */
    protected function payload(StockTransfer $transfer, array $allowed): array
    {
        $isSource = in_array((int) $transfer->from_branch_id, $allowed, true);
        $isDestination = in_array((int) $transfer->to_branch_id, $allowed, true);

        return [
            'id' => $transfer->id,
            'ref_no' => $transfer->ref_no,
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
            'status_badge' => $transfer->status->badge(),
            'from' => $transfer->fromBranch?->name,
            'to' => $transfer->toBranch?->name,
            'note' => $transfer->note,
            'cancel_reason' => $transfer->cancel_reason,
            'sent_by' => $transfer->sender?->name,
            'sent_at' => $transfer->sent_at?->toDateTimeString(),
            'received_by' => $transfer->receiver?->name,
            'received_at' => $transfer->received_at?->toDateTimeString(),
            'business_date' => $transfer->business_date?->toDateString(),
            'value' => $transfer->sentValue(),
            'has_shortfall' => $transfer->hasShortfall(),
            // ปุ่มโผล่เฉพาะฝั่งที่ทำได้จริง ด่านจริงอยู่ที่ guardBranch() ไม่ใช่ที่นี่
            'can_receive' => $transfer->status->isOpen() && $isDestination,
            'can_cancel' => $transfer->status->isOpen() && $isSource,
            'items' => $transfer->items->map(fn (StockTransferItem $item) => [
                'id' => $item->id,
                'name' => $item->stockItem?->name,
                'unit' => $item->stockItem?->unitLabel(),
                'qty_sent' => (float) $item->qty_sent,
                'qty_received' => $item->qty_received === null ? null : (float) $item->qty_received,
                'shortfall' => $item->shortfall(),
                'unit_cost' => (float) $item->unit_cost,
            ])->values(),
        ];
    }
}
