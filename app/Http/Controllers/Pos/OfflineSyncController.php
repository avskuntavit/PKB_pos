<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\OfflineSyncService;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ปลายทางของคิวที่ค้างอยู่ในแท็บเล็ตตอนเน็ตหลุด
 *
 * ตอบเป็น JSON ไม่ใช่ Inertia — ตัวเรียกคือโค้ดเบื้องหลัง ไม่ใช่การกดของคน
 * ถ้าตอบเป็น redirect หน้าจอที่พนักงานกำลังคีย์อยู่จะกระโดดเองระหว่างซิงก์
 */
class OfflineSyncController extends Controller
{
    public function store(Request $request, OfflineSyncService $sync): JsonResponse
    {
        $data = $request->validate([
            'entries' => ['required', 'array', 'max:'.OfflineSyncService::MAX_ENTRIES],
            'entries.*.uuid' => ['required', 'string', 'max:64'],
            'entries.*.kind' => ['required', 'string', 'max:30'],
            'entries.*.order_id' => ['required', 'integer'],
            'entries.*.at' => ['nullable', 'string', 'max:40'],
            'entries.*.payload' => ['nullable', 'array'],
        ], [
            'entries.max' => 'ส่งได้ครั้งละไม่เกิน '.OfflineSyncService::MAX_ENTRIES.' รายการ',
        ]);

        $branch = CurrentBranch::getOrFail();

        $results = $sync->sync($data['entries'], $request->user(), $branch);

        $count = fn (string $status) => count(array_filter($results, fn ($r) => $r['status'] === $status));

        return response()->json([
            'ok' => true,
            'results' => $results,
            // นับให้เลย หน้าจอจะได้ไม่ต้องไล่นับเองเพื่อขึ้นข้อความสรุป
            'applied' => $count('applied'),
            'failed' => $count('failed'),
            // เงินที่รับมาแล้วแต่ลงบิลไม่ได้ — ต้องแยกจาก failed เพราะพนักงานทำอะไรกับมันไม่ได้
            'held' => $count('held'),
        ]);
    }
}
