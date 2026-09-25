<?php

namespace App\Services;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ServiceCall;
use App\Models\Voucher;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * รับชำระเงิน — รองรับจ่ายหลายช่องทางในบิลเดียว (split payment)
 */
class PaymentService
{
    public function __construct(
        protected ActivityLogger $logger,
        protected StockService $stock,
        protected OrderService $orders,
        protected TableSessionService $sessions,
        protected StaffBenefitService $benefits,
        protected BranchSettingService $branchSettings,
        protected PrintService $printing,
    ) {}

    /**
     * @param  array<int, array{method: string, amount: float, received?: float, reference?: string}>  $lines
     */
    public function pay(Order $order, array $lines, ?string $voucherCode = null): Order
    {
        if (! $order->isOpen()) {
            throw new \DomainException('บิลนี้ถูกปิดไปแล้ว');
        }

        // บิลเปิดค้างจากงวดที่ปิดบัญชีไปแล้ว รับเงินไม่ได้ ต้องเปิดงวดกลับก่อน
        // (ปกติจะไม่มีบิลแบบนี้ เพราะปิดงวดทั้งที่ยังมีบิลเปิดค้างไม่ได้ตั้งแต่แรก)
        app(PeriodLockService::class)->assertEditable($order);

        if ($order->activeItems()->count() === 0) {
            throw new \DomainException('บิลว่าง ไม่มีรายการให้ชำระเงิน');
        }

        // ลูกค้าอาจเพิ่งกดสั่งตอนพนักงานกำลังเก็บเงิน — ต้องเคลียร์ก่อน ไม่งั้นยอดไม่ตรง
        $pending = $order->pendingApprovalItems()->count();

        if ($pending > 0) {
            throw new \DomainException("ยังมี {$pending} รายการที่ลูกค้าสั่งรอยืนยัน กรุณายืนยันหรือปฏิเสธก่อนปิดบิล");
        }

        return DB::transaction(function () use ($order, $lines, $voucherCode) {
            if ($voucherCode) {
                $this->applyVoucher($order, $voucherCode);
            }

            $this->orders->recalculate($order);

            $paid = 0.0;
            $change = 0.0;

            foreach ($lines as $line) {
                $method = PaymentMethod::from($line['method']);

                // ร้านที่ปิดรับเงินสดต้องปิดได้จริง ไม่ใช่แค่ซ่อนปุ่ม
                if (! $this->branchSettings->isMethodAllowed($order->branch, $method)) {
                    throw new \DomainException('สาขานี้ไม่รับชำระด้วย'.$method->label());
                }
                $amount = Money::round($line['amount']);
                $received = Money::round($line['received'] ?? $amount);

                // เงินทอนเกิดกับเงินสดเท่านั้น
                $lineChange = $method->isCash() ? max(0, Money::round($received - $amount)) : 0.0;
                $change += $lineChange;
                $paid += $amount;

                $order->payments()->create([
                    'shift_id' => $order->shift_id,
                    'method' => $method,
                    'amount' => $amount,
                    'received' => $received,
                    'change' => $lineChange,
                    'reference' => $line['reference'] ?? null,
                    'paid_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            }

            if (Money::round($paid) + 0.001 < (float) $order->grand_total) {
                throw new \DomainException('ยอดชำระไม่ครบ ยังขาดอีก '
                    .number_format((float) $order->grand_total - $paid, 2).' บาท');
            }

            $order->update([
                'status' => OrderStatus::Paid,
                'receipt_no' => $this->nextReceiptNo($order),
                'paid_amount' => Money::round($paid),
                'change_amount' => Money::round($change),
                'closed_by' => auth()->id(),
                'closed_at' => now(),
            ]);

            $order->diningTable?->update(['status' => TableStatus::Available]);

            // ตัดสต๊อกวัตถุดิบตามสูตร + สะสมแต้มลูกค้า
            $this->stock->deductForOrder($order);
            $this->awardPoints($order);

            // บันทึกมูลค่าสวัสดิการที่บริษัทออกให้ เพื่อให้บัญชีตั้งเบิกได้
            $this->benefits->record($order);

            // ปิดรอบ QR ของโต๊ะ — ลูกค้าสั่งเพิ่มหลังปิดบิลไม่ได้ และโต๊ะถัดไปสแกนได้ทันที
            $this->sessions->closeForOrder($order, 'paid');
            $this->closeServiceCalls($order);
            $this->completeOnlineOrder($order);

            $this->logger->log('order.pay', $order, [
                'total' => (float) $order->grand_total,
                'methods' => array_column($lines, 'method'),
            ]);

            /*
            | ใบเสร็จ + เตะลิ้นชัก
            |
            | เตะเฉพาะตอนมีเงินสดอยู่ในบิล — จ่ายด้วยพร้อมเพย์หรือบัตรไม่ต้องเปิด
            | ลิ้นชัก เปิดทุกบิลคือช่องโหว่ของการนับเงินปลายกะ ใครก็หยิบได้
            | โดยไม่มีรายการอ้างอิง
            */
            $paidCash = $order->payments->contains(fn ($p) => $p->method->isCash());

            $this->printing->queueReceipt($order->fresh(['payments', 'items.modifiers', 'branch']), $paidCash);

            return $order->fresh(['payments', 'items']);
        });
    }

    public function refund(Order $order, float $amount, string $method = 'cash', ?string $reason = null): Refund
    {
        /*
        | คืนเงินบิลของงวดที่ปิดแล้ว = ยอดขายของเดือนที่ยื่นภาษีไปแล้วเปลี่ยน
        | ถ้าจำเป็นต้องคืนจริง ให้ออกรายการในงวดปัจจุบันแทน หรือให้เจ้าของเปิดงวดกลับ
        */
        app(PeriodLockService::class)->assertEditable($order);

        return DB::transaction(function () use ($order, $amount, $method, $reason) {
            $refund = Refund::create([
                'order_id' => $order->id,
                'shift_id' => $order->shift_id,
                'amount' => Money::round($amount),
                'method' => $method,
                'reason' => $reason,
                'created_by' => auth()->id(),
                'refunded_at' => now(),
            ]);

            $totalRefunded = (float) $order->refunds()->sum('amount');

            if ($totalRefunded >= (float) $order->grand_total) {
                $order->update(['status' => OrderStatus::Refunded]);

                // คืนเงินเต็มจำนวน = ไม่ได้ใช้สิทธิ์ คืนวงเงินสวัสดิการให้
                $this->benefits->revoke($order);
            }

            $this->logger->log('order.refund', $order, ['amount' => $amount, 'reason' => $reason]);

            return $refund;
        });
    }

    protected function applyVoucher(Order $order, string $code): void
    {
        $voucher = Voucher::where('branch_id', $order->branch_id)
            ->where('code', $code)
            ->first();

        if (! $voucher) {
            throw new \DomainException('ไม่พบรหัสส่วนลดนี้');
        }

        /*
        | ฐานที่คูปองใบนี้มองเห็น — ร้านตั้งได้ต่อคูปอง ดู App\Enums\VoucherBase
        |
        | ตรงนี้ถูกเรียกก่อน recalculate() ของ pay() หนึ่งจังหวะ
        | ค่า promotion_discount / staff_discount ที่อ่านได้จึงเป็นของรอบคำนวณล่าสุด
        | ซึ่งตรงอยู่แล้วเพราะรายการในบิลยังไม่ขยับระหว่างสองบรรทัดนี้
        */
        $base = $voucher->base_mode->baseFor($order);
        $discount = $voucher->discountFor($base);

        if ($discount <= 0) {
            throw new \DomainException('ใช้รหัสส่วนลดนี้ไม่ได้ (หมดอายุ ถูกใช้ครบ หรือยอดไม่ถึงขั้นต่ำ)');
        }

        $order->update(['voucher_discount' => $discount]);
        $voucher->increment('used_count');

        $voucher->redemptions()->create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'amount' => $discount,
            'redeemed_at' => now(),
        ]);
    }

    /** ออเดอร์ล่วงหน้าถือว่าจบงานเมื่อลูกค้ามารับของและจ่ายเงินแล้ว */
    protected function completeOnlineOrder(Order $order): void
    {
        if ($order->fulfilment_status === null || $order->fulfilment_status->isFinished()) {
            return;
        }

        // บิลหน้าเคาน์เตอร์จ่ายเงินก่อนแล้วค่อยยืนรอของ — "จ่ายแล้ว" จึงไม่ได้แปลว่า "รับของแล้ว"
        // ถ้าปิดตรงนี้ คิวจะหายจากจอทันทีที่รูดบัตรเสร็จ ให้พนักงานกด "ส่งของแล้ว" ที่หน้าคิวเป็นคนปิดแทน
        if ($order->track_token === null) {
            return;
        }

        $order->update([
            'fulfilment_status' => FulfilmentStatus::Completed,
            'completed_at' => now(),
        ]);

        $order->statusEvents()->create([
            'status' => FulfilmentStatus::Completed,
            'created_by' => auth()->id(),
        ]);
    }

    /** เคลียร์คำขอเรียกพนักงานที่ค้างอยู่ของบิลนี้ */
    protected function closeServiceCalls(Order $order): void
    {
        ServiceCall::where('order_id', $order->id)
            ->pending()
            ->update(['status' => 'done', 'done_at' => now(), 'handled_by' => auth()->id()]);
    }

    /** กี่บาทได้ 1 แต้ม — หน้าตะกร้าอ่านค่านี้ไปคำนวณโชว์ จะได้ตรงกับที่ให้จริง */
    public const BAHT_PER_POINT = 25;

    /**
     * สะสมแต้มให้เฉพาะบิลที่ผูกกับสมาชิก
     *
     * บิลที่ลูกค้าสแกนสั่งที่โต๊ะไม่มีการล็อกอินและไม่ได้กรอกเบอร์ จึงไม่มี customer_id และไม่ได้แต้ม
     * ส่วนออเดอร์ออนไลน์มีเบอร์ จึงผูกลูกค้าให้และได้แต้ม (ปิดได้ที่ตั้งค่าสาขา)
     */
    protected function awardPoints(Order $order): void
    {
        if (! $order->customer_id) {
            return;
        }

        // ออเดอร์ออนไลน์ยืนยันตัวตนด้วยเบอร์อย่างเดียว ไม่มี OTP
        // ร้านที่ไม่สบายใจเรื่องนี้ ปิด award_points_online ได้ที่การตั้งค่าสาขา
        if ($order->isOnline() && ! $order->branch->award_points_online) {
            return;
        }

        $customer = $order->customer;
        $points = (int) floor((float) $order->grand_total / self::BAHT_PER_POINT);

        $customer->increment('points', $points);
        $customer->increment('total_spent', (float) $order->grand_total);
        $customer->increment('visit_count');
        $customer->update(['last_visit_at' => now()]);

        if ($points > 0) {
            $customer->pointTransactions()->create([
                'order_id' => $order->id,
                'type' => 'earn',
                'points' => $points,
                'balance_after' => $customer->points,
                'note' => 'ซื้อสินค้าบิล '.$order->order_no,
            ]);
        }
    }

    /**
     * เลขที่ใบเสร็จถัดไปของสาขานี้
     *
     * ── ใช้วันขาย ไม่ใช่วันตามปฏิทิน ──────────────────────
     * ร้านที่ปิดตีสอง บิลตอน 00:30 ยังเป็นยอดขายของเมื่อวาน
     * ถ้าใช้ now() เลขจะขึ้นต้นด้วยวันใหม่ แล้วเวลาออกรายงานภาษีขาย
     * เลขใบกำกับกับวันที่ในรายงานจะไม่ตรงกัน ซึ่งเป็นสิ่งแรกที่ผู้ตรวจดู
     *
     * ── ต้องล็อกแถว ───────────────────────────────────────
     * ของเดิมอ่านเลขล่าสุดแล้วบวกหนึ่งโดยไม่ล็อก แคชเชียร์สองเครื่อง
     * กดรับเงินพร้อมกันจะได้เลขเดียวกัน — ใบกำกับภาษีเลขซ้ำแก้ย้อนหลังไม่ได้
     * lockForUpdate กันช่วงเลขไว้จนกว่าทรานแซกชันนี้จะจบ
     */
    protected function nextReceiptNo(Order $order): string
    {
        $prefix = 'R'.$order->business_date->format('ymd');

        $last = Order::where('branch_id', $order->branch_id)
            ->where('receipt_no', 'like', $prefix.'%')
            ->orderByDesc('receipt_no')
            ->lockForUpdate()
            ->value('receipt_no');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
