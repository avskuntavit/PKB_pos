<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * จอแสดงคิวหน้าร้าน
 *
 * ตั้งใจให้เปิดค้างบนทีวีหรือแท็บเล็ตหน้าเคาน์เตอร์ ไม่ต้องล็อกอิน
 * เข้าถึงด้วยรหัสสาขาเท่านั้น และไม่แสดงข้อมูลอะไรนอกจากเลขคิวกับชื่อต้น
 */
class QueueBoardController extends Controller
{
    public function show(string $branchCode, QueueService $queue): Response
    {
        $branch = $this->branch($branchCode);

        return Inertia::render('Queue/Board', [
            'branch' => ['code' => $branch->code, 'name' => $branch->name],
            'board' => $queue->board($branch),
        ]);
    }

    public function feed(string $branchCode, QueueService $queue): JsonResponse
    {
        return response()->json($queue->board($this->branch($branchCode)));
    }

    protected function branch(string $code): Branch
    {
        $branch = Branch::where('code', $code)->where('is_active', true)->first();

        abort_if(! $branch, 404, 'ไม่พบสาขานี้');

        return $branch;
    }
}
