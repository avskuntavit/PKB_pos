<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\OrderType;
use App\Enums\PaymentIntent;
use App\Models\DiningTable;
use App\Services\OnlineOrderService;
use App\Support\StorefrontSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CheckoutController extends StorefrontController
{
    public function store(
        Request $request,
        OnlineOrderService $onlineOrders,
        StorefrontSession $storefront,
    ): RedirectResponse {
        $data = $request->validate([
            'branch_code' => ['nullable', 'string', 'max:20'],
            // ลูกค้าเลือกแค่ "สั่งตอนนี้" หรือ "สั่งล่วงหน้า"
            // ทานที่ร้าน/ห่อกลับ เป็นตัวเลือกรายจาน ระบบเดาประเภทบิลเอง
            'timing' => ['required', 'in:now,scheduled'],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9\-\s+]{9,20}$/'],
            'pickup_at' => ['nullable', 'date', 'required_if:timing,scheduled'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:30'],
            'payment_intent' => ['required', Rule::enum(PaymentIntent::class)],
            'note' => ['nullable', 'string', 'max:255'],
            'dining_table_id' => ['nullable', 'integer', 'exists:dining_tables,id'],

            'lines' => ['required', 'array', 'min:1', 'max:'.OnlineOrderService::MAX_LINES],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:1', 'max:30'],
            'lines.*.modifier_ids' => ['array', 'max:10'],
            'lines.*.modifier_ids.*' => ['integer'],
            'lines.*.note' => ['nullable', 'string', 'max:120'],
        ], [
            'phone.regex' => 'เบอร์โทรไม่ถูกต้อง',
        ]);

        $branch = $this->resolveBranch($data['branch_code'] ?? null);
        $staff = $this->staffUser($request);

        /*
        | โต๊ะมาได้สองทาง
        |   1. ลูกค้าสแกน QR ที่โต๊ะ — เก็บไว้ใน session ตอนเข้า /t/{qrToken}
        |   2. พนักงานสั่งแทนแล้วเลือกโต๊ะจากแถบพนักงาน
        |
        | ทางแรกมาก่อนเสมอ เพราะเป็นโต๊ะที่ลูกค้านั่งอยู่จริง
        | และห้ามอ่านเลขโต๊ะของลูกค้าจาก payload เด็ดขาด ไม่งั้นแก้ตัวเลขใน request
        | แล้วยิงบิลเข้าโต๊ะคนอื่นได้ — dining_table_id จึงเชื่อได้เฉพาะเมื่อมีพนักงานล็อกอินอยู่
        */
        $seated = $storefront->table($request, $branch);

        $table = $seated;

        if (! $table && $staff && ! empty($data['dining_table_id'])) {
            $table = DiningTable::where('branch_id', $branch->id)->find($data['dining_table_id']);
        }

        /*
        | สั่งตอนนี้ = เร็วที่สุดเท่าที่ครัวทำทัน / สั่งล่วงหน้า = เวลาที่เลือก
        |
        | จำกัดไว้ในวันขายเดียวกันเท่านั้น เพราะคิวกับสต๊อกรันเป็นรายวัน
        | ถ้ารับจองข้ามวันได้ ออเดอร์จะไปโผล่ในคิวของวันนี้ทั้งที่ลูกค้ามาพรุ่งนี้
        |
        | คนที่นั่งอยู่ที่โต๊ะแล้วจะนัดเวลาล่วงหน้าไม่ได้ — นั่งรออยู่ตรงนั้น ครัวต้องทำเลย
        */
        $earliest = $branch->earliestPickupAt();

        if ($seated || $data['timing'] === 'now') {
            $pickupAt = $earliest;
        } else {
            $pickupAt = Carbon::parse($data['pickup_at']);
            $endOfDay = $branch->businessDateFor()->copy()->endOfDay();

            if ($pickupAt->lt($earliest)) {
                $pickupAt = $earliest;
            }

            if ($pickupAt->gt($endOfDay)) {
                return back()
                    ->withErrors(['pickup_at' => 'เลือกเวลาได้เฉพาะภายในวันนี้'])
                    ->with('error', 'เลือกเวลาได้เฉพาะภายในวันนี้');
            }
        }

        try {
            $order = $onlineOrders->place(
                branch: $branch,
                lines: $data['lines'],
                contact: [
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'note' => $data['note'] ?? null,
                    'guest_count' => $data['guest_count'] ?? 1,
                ],
                // มีโต๊ะ = ทานที่ร้านแน่นอน ไม่ต้องเดา
                // ไม่มีโต๊ะค่อยตั้งต้นเป็นซื้อกลับบ้าน แล้ว OnlineOrderService เดาใหม่จากรายการที่สั่ง
                type: $table ? OrderType::DineIn : OrderType::Takeaway,
                pickupAt: $pickupAt,
                intent: PaymentIntent::from($data['payment_intent']),
                staff: $staff,
                table: $table,
                member: $this->customer($request),
                // ชื่อเล่นอ่านจาก session ไม่ใช่จาก payload — ไม่งั้นใครก็ใส่ชื่อคนอื่นลงจานตัวเองได้
                guestName: $storefront->guestName($request),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        /*
        | คนที่นั่งโต๊ะอยู่ให้กลับมาที่หน้าเมนู ไม่ใช่เด้งไปหน้าติดตามออเดอร์
        |
        | หน้าติดตามเหมาะกับคนสั่งกลับบ้านที่รอรับของแล้วจบ
        | แต่คนที่นั่งอยู่มักสั่งเพิ่มอีกรอบ ถ้าเด้งออกไปต้องกดย้อนกลับมาเอง
        | และยอดสะสมกับสถานะรายจานดูได้จากแถบบิลบนหน้าเมนูอยู่แล้ว
        */
        if ($seated) {
            return redirect()
                ->route('storefront.menu')
                ->with('success', 'ส่งรายการให้ร้านแล้ว ดูสถานะได้ที่แถบบิลด้านล่าง');
        }

        return redirect()->route('storefront.track', $order->track_token);
    }
}
