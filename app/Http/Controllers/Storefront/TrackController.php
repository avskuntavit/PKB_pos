<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\PaymentIntent;
use App\Models\Order;
use App\Services\OnlineOrderService;
use App\Services\PromptPayService;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TrackController extends StorefrontController
{
    /** หน้าติดตามออเดอร์ของลูกค้า — เข้าได้ด้วย track_token ที่อยู่ในลิงก์เท่านั้น */
    public function show(string $token, OnlineOrderService $onlineOrders, PromptPayService $promptPay): Response
    {
        $order = $this->findOrder($token);

        return Inertia::render('Storefront/Track', [
            'token' => $token,
            'branch' => [
                'name' => $order->branch->name,
                'phone' => $order->branch->phone,
                'address' => $order->branch->address,
                'code' => $order->branch->code,
            ],
            'order' => $onlineOrders->trackPayload($order),
            'promptPayPayload' => $order->payment_intent === PaymentIntent::PromptPay
                ? $promptPay->forBranch($order->branch, (float) $order->grand_total)
                : null,
        ]);
    }

    /** JSON สำหรับ polling — เบากว่าการโหลดหน้าใหม่ทั้งหน้า */
    public function feed(string $token, OnlineOrderService $onlineOrders): JsonResponse
    {
        $order = $this->findOrder($token);

        return response()->json($onlineOrders->trackPayload($order));
    }

    public function cancel(string $token, OnlineOrderService $onlineOrders): RedirectResponse
    {
        $order = $this->findOrder($token);

        try {
            $onlineOrders->cancelByCustomer($order);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'ยกเลิกออเดอร์แล้ว');
    }

    protected function findOrder(string $token): Order
    {
        $order = Order::with('branch')->where('track_token', $token)->first();

        abort_if(! $order, 404, 'ไม่พบออเดอร์นี้ ลิงก์อาจหมดอายุหรือพิมพ์ผิด');

        CurrentBranch::set($order->branch);

        return $order;
    }
}
