<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ServiceCall;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServiceCallController extends Controller
{
    /**
     * ฟีดแจ้งเตือนรวมสำหรับหน้า POS — คำขอเรียกพนักงาน + รายการรออนุมัติ
     * หน้า POS เรียกทุก 10 วินาที
     */
    public function feed(): JsonResponse
    {
        $branchId = CurrentBranch::id();

        $calls = ServiceCall::with('diningTable:id,name')
            ->where('branch_id', $branchId)
            ->pending()
            ->orderBy('created_at')
            ->get()
            ->map(fn (ServiceCall $c) => [
                'id' => $c->id,
                'type' => $c->type->value,
                'label' => $c->type->label(),
                'status' => $c->status,
                'table' => $c->diningTable?->name,
                // ต้องส่ง id ไปด้วย ไม่ใช่แค่ชื่อ เพราะผังโต๊ะต้องจับคู่คำขอกับโต๊ะบนผัง
                'dining_table_id' => $c->dining_table_id,
                'order_id' => $c->order_id,
                'note' => $c->note,
                'created_at' => $c->created_at?->toIso8601String(),
                'waiting_minutes' => (int) $c->created_at?->diffInMinutes(now()),
            ]);

        // บิลที่มีรายการลูกค้าสั่งเองค้างรออนุมัติ
        $pendingOrders = Order::with('diningTable:id,name')
            ->where('branch_id', $branchId)
            ->open()
            ->whereHas('pendingApprovalItems')
            ->withCount('pendingApprovalItems')
            ->get()
            ->map(fn (Order $o) => [
                'order_id' => $o->id,
                'order_no' => $o->order_no,
                'table' => $o->diningTable?->name,
                'pending_count' => $o->pending_approval_items_count,
            ]);

        return response()->json([
            'calls' => $calls,
            'pending_orders' => $pendingOrders,
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    public function acknowledge(ServiceCall $call): RedirectResponse
    {
        $this->authorizeCall($call);

        $call->update([
            'status' => 'acknowledged',
            'handled_by' => auth()->id(),
            'acknowledged_at' => now(),
        ]);

        return back();
    }

    public function done(ServiceCall $call): RedirectResponse
    {
        $this->authorizeCall($call);

        $call->update([
            'status' => 'done',
            'handled_by' => auth()->id(),
            'done_at' => now(),
        ]);

        return back();
    }

    protected function authorizeCall(ServiceCall $call): void
    {
        abort_unless($call->branch_id === CurrentBranch::id(), 403);
    }
}
