<?php

namespace App\Http\Controllers\SelfOrder;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTableSession;
use App\Services\SelfOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    /**
     * ปลายทางของการ polling จากมือถือลูกค้า
     * ตอบเป็น JSON ล้วน ไม่ผ่าน Inertia เพื่อให้เบาที่สุด (เรียกทุก 8 วินาที)
     */
    public function show(Request $request, SelfOrderService $selfOrders): JsonResponse
    {
        $session = ResolveTableSession::session($request);

        return response()->json($selfOrders->statusFor($session));
    }
}
