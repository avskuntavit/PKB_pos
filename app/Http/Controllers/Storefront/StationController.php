<?php

namespace App\Http\Controllers\Storefront;

use App\Models\Branch;
use App\Support\StorefrontSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * เลือกร้าน (สถานี) ฝั่งลูกค้า
 *
 * ต่างจากฝั่งพนักงานตรงที่ไม่ต้องตรวจสิทธิ์ — ลูกค้าเข้าได้ทุกร้านที่เปิดอยู่
 * เลือกครั้งเดียวแล้วจำไว้ 90 วัน แต่ยังเปลี่ยนได้ตลอดจากปุ่มบนหัวหน้าเมนู
 */
class StationController extends StorefrontController
{
    public function index(Request $request, StorefrontSession $storefront): Response
    {
        return Inertia::render('Storefront/Stations', [
            'stations' => $storefront->options(),
            'currentCode' => $storefront->resolveStation($request)?->code,

            // หน้าที่ส่งมาที่นี่ (เช่น /order/login) ฝากปลายทางไว้ให้พากลับไปหลังเลือกเสร็จ
            // ไม่งั้นลูกค้าจะถูกโยนไปหน้าเมนูแล้วต้องเดินกลับมากดล็อกอินเอง
            'redirectTo' => $this->safePath(
                $request->query('redirect'),
                route('storefront.menu', absolute: false),
            ),
        ]);
    }

    public function select(Request $request, string $branchCode, StorefrontSession $storefront): RedirectResponse
    {
        $branch = Branch::where('code', $branchCode)->where('is_active', true)->first();

        abort_if(! $branch, 404, 'ไม่พบร้านนี้');

        $storefront->rememberStation($branch);

        // เปลี่ยนร้าน = ไม่ได้นั่งโต๊ะเดิมแล้ว ไม่งั้นบิลจะวิ่งเข้าโต๊ะของอีกร้าน
        $storefront->forgetTable($request);

        // ปลายทางมาจากฝั่งหน้าเว็บ จึงต้องกรองก่อนทุกครั้ง ไม่เชื่อค่าดิบ
        return redirect($this->safePath(
            $request->input('redirect'),
            route('storefront.menu', absolute: false),
        ));
    }
}
