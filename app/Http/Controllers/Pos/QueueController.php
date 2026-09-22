<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\QueueService;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้าจัดการคิวของพนักงาน
 *
 * แยกจากหน้าออเดอร์ล่วงหน้า (online-orders) โดยตั้งใจ:
 * หน้านั้นตอบคำถาม "รับออเดอร์นี้ไหม" ส่วนหน้านี้ตอบ "ใครมารับของได้แล้ว"
 * และรวมคิวจากทุกช่องทางไว้ที่เดียว เพราะลูกค้ายืนรออยู่หน้าเคาน์เตอร์เดียวกัน
 */
class QueueController extends Controller
{
    public function index(QueueService $queue): Response
    {
        $branch = CurrentBranch::getOrFail();

        return Inertia::render('Pos/Queue', [
            'branch' => ['code' => $branch->code, 'name' => $branch->name],
            'board' => $queue->staffBoard($branch),
            'recallAfterMinutes' => QueueService::RECALL_AFTER_MINUTES,
        ]);
    }

    public function feed(QueueService $queue): JsonResponse
    {
        return response()->json($queue->staffBoard(CurrentBranch::getOrFail()));
    }

    /** เรียกคิว / เรียกซ้ำ — ปุ่มเดียวกัน ระบบนับครั้งให้เอง */
    public function call(Order $order, QueueService $queue): RedirectResponse
    {
        $this->authorizeOrder($order);

        $queue->call($order);

        return back();
    }

    /** เรียกแล้วไม่มา — พักไว้ก่อน ยังกดเรียกซ้ำได้ตลอด */
    public function skip(Order $order, QueueService $queue): RedirectResponse
    {
        $this->authorizeOrder($order);

        $queue->skip($order);

        return back();
    }

    /** ส่งของให้ลูกค้าแล้ว */
    public function complete(Order $order, QueueService $queue): RedirectResponse
    {
        $this->authorizeOrder($order);

        $queue->complete($order);

        return back();
    }

    /** เรียกคิวถัดไปที่ยังไม่เคยเรียก — ปุ่มใหญ่ที่พนักงานกดรัว ๆ ได้ */
    public function next(QueueService $queue): RedirectResponse
    {
        $order = $queue->nextToCall(CurrentBranch::getOrFail());

        if (! $order) {
            return back()->with('error', 'ยังไม่มีคิวที่พร้อมให้เรียก');
        }

        $queue->call($order);

        return back()->with('success', "เรียกคิว {$order->queue_number} แล้ว");
    }

    protected function authorizeOrder(Order $order): void
    {
        abort_unless($order->branch_id === CurrentBranch::id(), 403);
        abort_unless($order->queue_number !== null, 404, 'บิลนี้ไม่มีเลขคิว');
    }
}
