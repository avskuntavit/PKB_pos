<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentChargeService;
use App\Services\PaymentService;
use App\Services\StaffBenefitService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function store(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        abort_unless($order->branch_id === CurrentBranch::id(), 403);

        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'voucher_code' => ['nullable', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1'],
            // ผูกกับ enum ตรง ๆ กันลืมแก้ตอนเพิ่มช่องทางใหม่
            'lines.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.received' => ['nullable', 'numeric', 'min:0'],
            'lines.*.reference' => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($data['customer_id'])) {
            $order->update(['customer_id' => $data['customer_id']]);

            // ผูกสมาชิกทีหลังก็ต้องได้สิทธิ์สวัสดิการ ไม่ใช่เฉพาะตอนสั่งออนไลน์
            $customer = Customer::find($data['customer_id']);

            if ($customer?->isVerifiedEmployee()) {
                app(StaffBenefitService::class)->apply($order->fresh(), app(OrderService::class), $customer);
                $order->refresh();
            }
        }

        try {
            $payments->pay($order, $data['lines'], $data['voucher_code'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        /*
        | ปิดเรื่อง QR ของบิลนี้ให้เรียบร้อย — ต้องอยู่ **หลัง** pay() สำเร็จเท่านั้น
        |
        | เงินโอนเข้ามาก่อนแล้ว  → ผูกเข้ากับแถว payments ที่เพิ่งสร้าง
        |                          ไม่งั้นจะมีสองบันทึกที่ไม่มีใครรู้ว่าเป็นเงินก้อนเดียวกัน
        | ยังไม่เข้า            → ยกเลิก QR ที่ค้างอยู่ ไม่งั้นลูกค้าสแกนใบเดิมได้อีก
        |                          แล้วเงินจะเข้ามาโดยไม่มีบิลรองรับ ต้องตามคืนทีหลัง
        |
        | อยู่นอก try ของ pay() โดยตั้งใจ — ถ้าตรงนี้พลาด บิลก็ปิดไปแล้วจริง
        | การเด้ง error กลับไปจะทำให้พนักงานเข้าใจว่าปิดไม่สำเร็จแล้วกดซ้ำ
        */
        app(PaymentChargeService::class)->settleAfterPayment($order->fresh());

        return redirect()->route('pos.receipt', $order)->with('success', 'ชำระเงินเรียบร้อย');
    }

    /** ใบเสร็จ — พิมพ์จากเบราว์เซอร์ได้เลย */
    public function receipt(Order $order): Response
    {
        abort_unless($order->branch_id === CurrentBranch::id(), 403);

        $order->load(['items.modifiers', 'payments', 'branch', 'diningTable:id,name', 'closedBy:id,name']);

        return Inertia::render('Pos/Receipt', ['order' => $order]);
    }
}
