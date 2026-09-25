<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\OrderType;
use App\Enums\PaymentIntent;
use App\Models\DiningTable;
use App\Models\TableSession;
use App\Services\OnlineOrderService;
use App\Services\TableCartService;
use App\Services\TableSessionService;
use App\Support\StorefrontSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ตะกร้าร่วมของโต๊ะ — ฝั่งลูกค้าที่นั่งอยู่ในร้าน
 *
 * ── ใช้เฉพาะคนที่นั่งโต๊ะ ─────────────────────────────────────────────────
 * คนสั่งกลับบ้านยังใช้ตะกร้าในเบราว์เซอร์ตัวเองเหมือนเดิม เพราะไม่มีโต๊ะให้แชร์
 * ทุก endpoint ที่นี่จึงตอบ 409 ถ้าคนเรียกไม่ได้นั่งโต๊ะอยู่
 *
 * ── โต๊ะมาจาก session เท่านั้น ───────────────────────────────────────────
 * ไม่มีพารามิเตอร์ให้ระบุเลขโต๊ะเลยสักตัว ถ้ารับจาก request ใครก็ยัดของ
 * ลงตะกร้าโต๊ะคนอื่นได้ด้วยการแก้ตัวเลข — กติกาเดียวกับ TableBillController
 *
 * ── ทำไมตอบเป็น JSON ไม่ใช่ Inertia ──────────────────────────────────────
 * กดบวกจำนวนทีหนึ่งแล้วเรนเดอร์ทั้งหน้าใหม่ บนมือถือรู้สึกได้ชัด
 * ทุก endpoint จึงคืน "สรุปตะกร้าล่าสุด" กลับไปเลย เครื่องที่กดเห็นผลทันที
 * ส่วนเครื่องอื่นที่โต๊ะเห็นจากรอบ poll ของ /order/bill ตามปกติ
 */
class TableCartController extends StorefrontController
{
    public function __construct(
        protected TableCartService $cart,
        protected StorefrontSession $storefront,
        protected TableSessionService $sessions,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'qty' => ['required', 'numeric', 'min:1', 'max:'.TableCartService::MAX_QTY_PER_LINE],
            'modifier_ids' => ['array', 'max:10'],
            'modifier_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->respond($request, fn (TableSession $session) => $this->cart->add(
            $session,
            (int) $data['product_id'],
            (float) $data['qty'],
            $data['modifier_ids'] ?? [],
            $data['note'] ?? null,
            $this->storefront->guestKey($request),
            $this->storefront->guestName($request),
        ));
    }

    public function update(Request $request, int $item): JsonResponse
    {
        // 0 ได้ = ลบบรรทัดนั้น ปุ่มลบกับปุ่มลดจำนวนจึงใช้เส้นทางเดียวกัน
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'min:0', 'max:'.TableCartService::MAX_QTY_PER_LINE],
        ]);

        return $this->respond($request, fn (TableSession $s) => $this->cart->setQty($s, $item, (float) $data['qty']));
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        return $this->respond($request, fn (TableSession $s) => $this->cart->remove($s, $item));
    }

    public function clear(Request $request): JsonResponse
    {
        return $this->respond($request, fn (TableSession $s) => $this->cart->clear($s));
    }

    /** กดส่งครัว — ตั้งนาฬิกานับถอยหลังให้ทั้งโต๊ะเห็น ยังไม่ส่งจริง */
    public function requestSubmit(Request $request): JsonResponse
    {
        return $this->respond($request, fn (TableSession $s) => $this->cart->requestSubmit(
            $s,
            $this->storefront->guestKey($request),
            $this->storefront->guestName($request),
        ));
    }

    /** ใครก็ยกเลิกได้ — คนที่ยังเลือกไม่เสร็จคือคนที่ต้องหยุดมันได้ */
    public function cancelSubmit(Request $request): JsonResponse
    {
        return $this->respond($request, fn (TableSession $s) => $this->cart->cancelSubmit($s));
    }

    /**
     * ส่งจริงเมื่อนับครบ — เครื่องของคนที่กดส่งเป็นคนยิงเข้ามา
     *
     * ข้อมูลติดต่อมาจากฟอร์มของคนที่กด (เหมือนหน้าเช็คเอาต์ปกติ)
     * ส่วน "ส่งอะไรบ้าง" มาจากตะกร้าฝั่งเซิร์ฟเวอร์ ไม่ใช่จาก payload
     * เพื่อให้สิ่งที่ส่งตรงกับสิ่งที่ทั้งโต๊ะเห็นอยู่จริง ณ วินาทีนั้น
     */
    public function confirmSubmit(Request $request, OnlineOrderService $onlineOrders): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9\-\s+]{9,20}$/'],
            'payment_intent' => ['required', Rule::enum(PaymentIntent::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'phone.regex' => 'เบอร์โทรไม่ถูกต้อง',
        ]);

        [$branch, $table, $session] = $this->seating($request);

        if (! $session) {
            return $this->notSeated();
        }

        try {
            $order = $this->cart->submitNow(
                $session,
                fn (array $lines) => $onlineOrders->place(
                    branch: $branch,
                    lines: $lines,
                    contact: [
                        'name' => $data['name'],
                        'phone' => $data['phone'],
                        'note' => $data['note'] ?? null,
                    ],
                    // นั่งโต๊ะอยู่ = ทานที่ร้านแน่นอน และครัวต้องทำเลย ไม่มีการนัดเวลา
                    type: OrderType::DineIn,
                    pickupAt: $branch->earliestPickupAt(),
                    intent: PaymentIntent::from($data['payment_intent']),
                    staff: $this->staffUser($request),
                    table: $table,
                    member: $this->customer($request),
                    // ชื่อเล่นอ่านจาก session ไม่ใช่จาก payload (กติกาเดียวกับหน้าเช็คเอาต์)
                    guestName: $this->storefront->guestName($request),
                ),
            );
        } catch (\DomainException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'cart' => $this->cart->summary($session->fresh(), $this->storefront->guestKey($request)),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'ส่งรายการให้ร้านแล้ว ดูสถานะได้ที่แถบบิลด้านล่าง',
            'order_no' => $order->order_no,
            'cart' => $this->cart->summary($session->fresh(), $this->storefront->guestKey($request)),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ตัวช่วย
    |--------------------------------------------------------------------------
    */

    /**
     * ร้าน + โต๊ะ + รอบการนั่ง ของคนที่เรียกมา
     *
     * @return array{0: ?\App\Models\Branch, 1: ?DiningTable, 2: ?TableSession}
     */
    protected function seating(Request $request): array
    {
        $branch = $this->storefront->resolveStation($request);
        $table = $branch ? $this->storefront->table($request, $branch) : null;

        if (! $table) {
            return [$branch, null, null];
        }

        /*
        | resolve() คืน session ที่เปิดอยู่ของโต๊ะนี้ ไม่ได้เปิดใหม่ทุกครั้ง
        | มือถือทุกเครื่องที่โต๊ะเดียวกันจึงได้แถวเดียวกัน ซึ่งคือสิ่งที่ทำให้ตะกร้า "ร่วม" กันได้
        */
        return [$branch, $table, $this->sessions->resolve($table, $request->ip())];
    }

    /**
     * ทำงานหนึ่งอย่าง แล้วคืนสรุปตะกร้าล่าสุดเสมอ
     *
     * คืนตะกร้าไปด้วยแม้ตอนล้มเหลว เพราะสาเหตุที่ล้มบ่อยที่สุดคือ
     * "มีคนที่โต๊ะลบรายการนั้นไปแล้ว" — เครื่องที่กดควรเห็นของจริงทันที
     * ไม่ใช่เห็นแต่ข้อความ error บนตะกร้าที่ล้าสมัยไปแล้ว
     */
    protected function respond(Request $request, callable $action): JsonResponse
    {
        [, , $session] = $this->seating($request);

        if (! $session) {
            return $this->notSeated();
        }

        $guestKey = $this->storefront->guestKey($request);

        try {
            $action($session);
        } catch (\DomainException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'cart' => $this->cart->summary($session->fresh(), $guestKey),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'cart' => $this->cart->summary($session->fresh(), $guestKey),
        ]);
    }

    /**
     * ไม่ได้นั่งโต๊ะ = ไม่มีตะกร้าร่วมให้ใช้
     *
     * 409 ไม่ใช่ 403 เพราะไม่ใช่เรื่องสิทธิ์ แต่เป็นสถานะที่ใช้ฟีเจอร์นี้ไม่ได้
     * เกิดได้จริงตอนพนักงานเพิ่งปิดบิลของโต๊ะพอดีระหว่างที่ลูกค้ายังเปิดหน้าค้างอยู่
     */
    protected function notSeated(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'seated' => false,
            'message' => 'รอบการนั่งโต๊ะนี้จบแล้ว กรุณาสแกน QR ที่โต๊ะใหม่อีกครั้ง',
        ], 409);
    }
}
