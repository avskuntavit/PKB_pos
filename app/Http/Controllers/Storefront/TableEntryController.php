<?php

namespace App\Http\Controllers\Storefront;

use App\Services\TableSessionService;
use App\Support\StorefrontSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ลูกค้าสแกน QR ที่ติดอยู่บนโต๊ะ
 *
 * QR ฝังลิงก์ /t/{qr_token} ซึ่งพิมพ์ติดโต๊ะไว้แล้ว จึงห้ามเปลี่ยนรูปแบบ URL
 * หน้าที่ของ controller นี้คือแปลง token เป็น "ร้าน + โต๊ะ" เก็บลง session
 * แล้วส่งต่อไปหน้าสั่งอาหารหน้าเดียวกับที่ลูกค้าทั่วไปใช้
 *
 * เลขโต๊ะอยู่ฝั่งเซิร์ฟเวอร์ตลอด ไม่เคยให้ client เป็นคนบอกว่านั่งโต๊ะไหน
 *
 * สแกนได้ไม่ได้แปลว่าสั่งได้ — โต๊ะที่ยังไม่ถูกเปิดจะได้แค่ดูเมนู
 * นี่คือด่านกันคนถ่ายรูป QR กลับไปสแกนที่บ้านเพื่อสั่งเล่น
 */
class TableEntryController extends StorefrontController
{
    public function __invoke(
        Request $request,
        string $qrToken,
        TableSessionService $sessions,
        StorefrontSession $storefront,
    ): RedirectResponse {
        $table = $sessions->findTableByQrToken($qrToken);

        abort_if(! $table, 404, 'ไม่พบโต๊ะนี้ กรุณาติดต่อพนักงาน');
        abort_if(! $table->branch?->is_active, 404, 'ร้านนี้ปิดให้บริการอยู่');

        $storefront->rememberStation($table->branch);

        /*
        | โต๊ะยังไม่ถูกเปิด — จำไว้เฉย ๆ ยังไม่ผูก
        |
        | ไม่ 404 ทิ้ง เพราะลูกค้าตัวจริงที่เพิ่งนั่งลงก็เจอหน้านี้เหมือนกัน
        | แค่ยังไม่มีพนักงานมาเปิดโต๊ะให้ ระบบจึงพาไปดูเมนูก่อนพร้อมบอกให้เรียกพนักงาน
        | พอพนักงานเปิดให้แล้ว รีเฟรชครั้งเดียวก็ผูกโต๊ะเอง (ดู StorefrontSession::seating)
        */
        if (! $sessions->isOpenForGuests($table)) {
            $storefront->forgetTable($request);
            $storefront->rememberPendingTable($request, $table);

            return redirect()->route('storefront.menu');
        }

        $sessions->resolve($table, $request->ip());
        $storefront->rememberTable($request, $table);

        return redirect()->route('storefront.menu');
    }
}
