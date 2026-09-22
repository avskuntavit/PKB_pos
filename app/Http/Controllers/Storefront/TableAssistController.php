<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\ServiceCallType;
use App\Models\ServiceCall;
use App\Services\TableSessionService;
use App\Support\StorefrontSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ลูกค้าที่สแกน QR มาแล้วแต่โต๊ะยังไม่ถูกเปิด ขอให้พนักงานมาเปิดให้
 *
 * ── ทำไมต้องมี ─────────────────────────────────────────────
 * ก่อนหน้านี้หน้าจอบอกแค่ "กรุณาแจ้งพนักงาน" ซึ่งแปลว่าลูกค้าต้องโบกมือเรียก
 * ร้านที่คนน้อยหรือโต๊ะอยู่มุมลึก ลูกค้าจะนั่งงงอยู่นานโดยที่ไม่มีใครรู้ว่ามีคนมานั่งแล้ว
 *
 * ── เรื่องความปลอดภัย ──────────────────────────────────────
 * โต๊ะมาจาก session ฝั่งเซิร์ฟเวอร์เท่านั้น ไม่มีพารามิเตอร์ให้ระบุเลขโต๊ะ
 * ถ้ารับเลขโต๊ะจาก request ใครก็ยิงคำขอเปิดโต๊ะถล่มทุกโต๊ะในร้านได้
 */
class TableAssistController extends StorefrontController
{
    /**
     * ลูกค้าพิมพ์ชื่อเล่นของตัวเอง
     *
     * ไม่บังคับ — ข้ามได้ แล้วจานจะไม่มีชื่อกำกับเฉย ๆ
     * ที่ไม่บังคับเพราะการกั้นไม่ให้ดูเมนูจนกว่าจะพิมพ์ชื่อ ทำให้คนปิดหน้าไปเลย
     */
    public function setGuestName(Request $request, StorefrontSession $storefront): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:30'],
        ]);

        $storefront->rememberGuestName($request, $data['name'] ?? null);

        return back();
    }

    public function requestOpen(
        Request $request,
        StorefrontSession $storefront,
        TableSessionService $sessions,
    ): RedirectResponse {
        $branch = $storefront->resolveStation($request);
        $table = $branch ? $storefront->pendingTable($request, $branch) : null;

        if (! $table) {
            return back()->with('error', 'ไม่พบโต๊ะที่สแกนมา กรุณาสแกน QR ที่โต๊ะอีกครั้ง');
        }

        // พนักงานเพิ่งเปิดให้พอดีระหว่างที่ลูกค้ากำลังกด — ไม่ต้องเรียกแล้ว
        if ($sessions->isOpenForGuests($table)) {
            return back()->with('success', 'โต๊ะเปิดให้แล้ว รีเฟรชหน้านี้เพื่อเริ่มสั่งได้เลย');
        }

        // กดซ้ำไม่ทำให้เกิดคำขอซ้อน พนักงานจะได้ไม่เห็นโต๊ะเดียวกันสิบแถว
        if (ServiceCall::query()->openRequestFor($table->id)->exists()) {
            return back()->with('success', 'แจ้งพนักงานไปแล้ว กำลังไปเปิดโต๊ะให้');
        }

        ServiceCall::create([
            'branch_id' => $table->branch_id,
            'dining_table_id' => $table->id,
            'type' => ServiceCallType::OpenTable,
            'status' => 'open',
            'business_date' => $branch->businessDateFor()->toDateString(),
        ]);

        return back()->with('success', 'แจ้งพนักงานแล้ว กำลังไปเปิดโต๊ะให้');
    }
}
