<?php

namespace App\Http\Controllers\Storefront;

use App\Models\DiningTable;
use App\Services\TableBillService;
use App\Support\StorefrontSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * บิลของโต๊ะ สำหรับลูกค้าที่สแกน QR นั่งอยู่
 *
 * โต๊ะมาจาก session ฝั่งเซิร์ฟเวอร์เท่านั้น ไม่มีพารามิเตอร์ให้ระบุเลขโต๊ะ
 * ถ้ารับเลขโต๊ะจาก request ใครก็เปิดดูบิลโต๊ะอื่นได้ด้วยการแก้ตัวเลขใน URL
 */
class TableBillController extends StorefrontController
{
    public function __construct(protected TableBillService $bills) {}

    /** JSON สำหรับ poll — หน้าเมนูดึงซ้ำเพื่ออัปเดตสถานะรายจาน */
    public function feed(Request $request, StorefrontSession $storefront): JsonResponse
    {
        return response()->json([
            'bill' => $this->bills->forTable($this->seatedTable($request, $storefront)),
        ]);
    }

    /** ลูกค้ากดเรียกพนักงานมาเก็บเงิน */
    public function call(Request $request, StorefrontSession $storefront): RedirectResponse
    {
        $table = $this->seatedTable($request, $storefront);
        $order = $table?->openOrder()->first();

        if (! $order) {
            return back()->with('error', 'ยังไม่มีบิลของโต๊ะนี้');
        }

        $this->bills->callForBill($order);

        return back()->with('success', 'แจ้งพนักงานให้มาเก็บเงินแล้ว กรุณารอสักครู่');
    }

    protected function seatedTable(Request $request, StorefrontSession $storefront): ?DiningTable
    {
        $branch = $storefront->resolveStation($request);

        return $branch ? $storefront->table($request, $branch) : null;
    }
}
