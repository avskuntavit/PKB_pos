<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\SelfOrderService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** พนักงานยืนยัน/ปฏิเสธรายการที่ลูกค้าสแกนสั่งเอง */
class ApprovalController extends Controller
{
    public function approve(Request $request, Order $order, SelfOrderService $selfOrders): RedirectResponse
    {
        $this->authorizeOrder($order);

        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
        ]);

        $approved = $selfOrders->approve($order, $data['item_ids'], $request->user()->id);

        return back()->with('success', "ยืนยันแล้ว {$approved->count()} รายการ ส่งเข้าครัวเรียบร้อย");
    }

    public function reject(Request $request, Order $order, SelfOrderService $selfOrders): RedirectResponse
    {
        $this->authorizeOrder($order);

        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $count = $selfOrders->reject($order, $data['item_ids'], $data['reason'] ?? null);

        return back()->with('success', "ปฏิเสธแล้ว {$count} รายการ");
    }

    protected function authorizeOrder(Order $order): void
    {
        abort_unless($order->branch_id === CurrentBranch::id(), 403);
    }
}
