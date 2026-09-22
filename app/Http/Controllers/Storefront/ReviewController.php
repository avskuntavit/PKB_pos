<?php

namespace App\Http\Controllers\Storefront;

use App\Models\Order;
use App\Models\OrderReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ลูกค้าให้คะแนนบิล
 *
 * เข้าถึงด้วย track_token ที่อยู่ในลิงก์ของบิลนั้น ไม่ต้องล็อกอินก็รีวิวได้
 * (คนที่ถือลิงก์ = คนที่สั่งบิลนี้) และ 1 บิลรีวิวได้ครั้งเดียว
 */
class ReviewController extends StorefrontController
{
    public function store(Request $request, string $token): RedirectResponse
    {
        $order = Order::with('branch')->where('track_token', $token)->first();

        abort_if(! $order, 404, 'ไม่พบบิลนี้');

        if (! $order->status->countsAsSale()) {
            return back()->with('error', 'ให้คะแนนได้หลังชำระเงินเรียบร้อยแล้ว');
        }

        if ($order->review()->exists()) {
            return back()->with('error', 'บิลนี้ให้คะแนนไปแล้ว ขอบคุณครับ');
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'tags' => ['array', 'max:6'],
            'tags.*' => ['string', 'max:40'],
            'comment' => ['nullable', 'string', 'max:500'],
        ], [], ['rating' => 'คะแนน']);

        OrderReview::create([
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'rating' => $data['rating'],
            'tags' => $data['tags'] ?? null,
            'comment' => $data['comment'] ?? null,
            'business_date' => $order->business_date->toDateString(),
        ]);

        return back()->with('success', 'ขอบคุณสำหรับคะแนนครับ');
    }
}
