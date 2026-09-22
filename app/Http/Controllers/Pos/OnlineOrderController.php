<?php

namespace App\Http\Controllers\Pos;

use App\Enums\FulfilmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OnlineOrderService;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** คิวออเดอร์ล่วงหน้าฝั่งร้าน — ออกแบบให้ใช้บนมือถือขณะยืนหน้าเคาน์เตอร์ */
class OnlineOrderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Pos/OnlineOrders', [
            'orders' => $this->queue(),
            'statuses' => collect(FulfilmentStatus::steps())
                ->map(fn (FulfilmentStatus $s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function feed(): JsonResponse
    {
        return response()->json([
            'orders' => $this->queue(),
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    public function accept(Order $order, OnlineOrderService $onlineOrders, Request $request): RedirectResponse
    {
        $this->authorizeOrder($order);
        $onlineOrders->accept($order, $request->user());

        return back()->with('success', "รับออเดอร์ {$order->order_no} แล้ว ส่งเข้าครัวเรียบร้อย");
    }

    public function reject(Request $request, Order $order, OnlineOrderService $onlineOrders): RedirectResponse
    {
        $this->authorizeOrder($order);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $onlineOrders->reject($order, $data['reason'], $request->user());

        return back()->with('success', 'ปฏิเสธออเดอร์แล้ว');
    }

    public function moveTo(Request $request, Order $order, OnlineOrderService $onlineOrders): RedirectResponse
    {
        $this->authorizeOrder($order);

        $data = $request->validate([
            'status' => ['required', 'in:accepted,preparing,ready,completed'],
        ]);

        $onlineOrders->moveTo($order, FulfilmentStatus::from($data['status']), $request->user());

        return back();
    }

    /* ---------- ภายใน ---------- */

    protected function queue(): array
    {
        return Order::with(['items' => fn ($q) => $q->where('status', '!=', 'void'), 'diningTable:id,name'])
            ->where('branch_id', CurrentBranch::id())
            ->awaitingFulfilment()
            // เฉพาะใบที่มีลิงก์ติดตาม = สั่งผ่านหน้าร้านออนไลน์จริง ๆ
            // บิลหน้าเคาน์เตอร์ที่เพิ่งได้เลขคิวไปจะไปโผล่ที่หน้าจัดการคิวแทน
            ->whereNotNull('track_token')
            ->withCount('pendingApprovalItems')
            ->orderByRaw("CASE WHEN fulfilment_status = 'placed' THEN 0 ELSE 1 END")
            ->orderBy('pickup_at')
            ->limit(50)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'order_no' => $o->order_no,
                'status' => $o->fulfilment_status->value,
                'status_label' => $o->fulfilment_status->label(),
                'type' => $o->type->value,
                'type_label' => $o->type->label(),
                'table' => $o->diningTable?->name,
                'contact_name' => $o->contact_name,
                'contact_phone' => $o->contact_phone,
                'pickup_at' => $o->pickup_at?->toIso8601String(),
                // ติดลบ = เลยเวลานัดรับแล้ว ใช้ไฮไลต์ใบที่ลูกค้ากำลังรอ
                'minutes_to_pickup' => $o->pickup_at ? (int) now()->diffInMinutes($o->pickup_at, false) : null,
                'payment_intent' => $o->payment_intent?->value,
                'payment_intent_label' => $o->payment_intent?->label(),
                'is_government_scheme' => (bool) $o->payment_intent?->isGovernmentScheme(),
                'grand_total' => (float) $o->grand_total,
                'note' => $o->note,
                'pending_count' => $o->pending_approval_items_count,
                'items' => $o->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->product_name,
                    'qty' => (float) $i->qty,
                    'note' => $i->note,
                ]),
            ])
            ->all();
    }

    protected function authorizeOrder(Order $order): void
    {
        abort_unless($order->branch_id === CurrentBranch::id(), 403);
    }
}
