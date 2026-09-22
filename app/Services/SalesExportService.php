<?php

namespace App\Services;

use App\Enums\ExportStatus;
use App\Enums\OrderStatus;
use App\Exports\FileExportDriver;
use App\Exports\SalesExportDriver;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesExport;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ส่งข้อมูลการขายออกไปให้ระบบบัญชี (SAM)
 *
 * ── ทำไมแยกเป็นสองชั้น ────────────────────────────────────
 * ยังไม่รู้ว่า SAM รับข้อมูลทางไหน (API / ไฟล์ / เขียนฐานข้อมูลตรง)
 * คลาสนี้จึงรับผิดชอบแค่ "ประกอบข้อมูลให้ครบและถูก" ส่วนการส่งเป็นหน้าที่ของ driver
 * พอได้คำตอบจริงค่อยเขียน driver ตัวใหม่ ส่วนที่ประกอบข้อมูลไม่ต้องแตะ
 *
 * ── ส่งเฉพาะบิลที่จบแล้ว ──────────────────────────────────
 * บิลที่ยังเปิดอยู่ไม่ถูกส่ง เพราะยอดยังเปลี่ยนได้ทุกวินาที
 * ส่งไปแล้วต้องตามแก้ทุกครั้งที่ลูกค้าสั่งเพิ่ม ซึ่งไม่มีระบบบัญชีไหนอยากได้
 *
 * ── บิลที่ยกเลิก/คืนเงินถูกส่งไปด้วย ────────────────────────
 * ติดธง counts_as_sale ไว้ให้ปลายทางตัดสินเอง ไม่ใช่ซ่อนไป
 * เลขที่ใบกำกับที่ออกไปแล้วต้องอธิบายได้ทุกเลข หลักเดียวกับรายงานภาษีขาย
 *
 * ── เบอร์โทรลูกค้าไม่ถูกส่ง ────────────────────────────────
 * งานบัญชีไม่ต้องใช้ และข้อมูลส่วนบุคคลที่ส่งออกนอกระบบแล้วเรียกคืนไม่ได้
 *
 * ผมไม่ใช่ผู้ทำบัญชี — โครงสร้างข้อมูลนี้ต้องให้ทีม SAM และผู้ทำบัญชียืนยันก่อนใช้จริง
 */
class SalesExportService
{
    /** ขึ้นเวอร์ชันเมื่อโครงสร้างเปลี่ยนจนปลายทางต้องแก้ตาม */
    public const SCHEMA = 'foodpos.sales.v1';

    public function __construct(protected ActivityLogger $logger) {}

    /**
     * ชุดข้อมูลมาตรฐานของวันขายหนึ่งวัน
     *
     * @param  string|null  $detail  summary|bills|both (ปล่อยว่าง = ตามค่าตั้งใน config)
     * @return array<string, mixed>
     */
    public function payload(Branch $branch, string $businessDate, ?string $detail = null): array
    {
        $detail = in_array($detail, ['summary', 'bills', 'both'], true)
            ? $detail
            : (string) config('pos.export.detail', 'both');

        $bills = $this->bills($branch, $businessDate);

        $payload = [
            'schema' => self::SCHEMA,
            'generated_at' => now($branch->timezone ?: config('app.timezone'))->toIso8601String(),
            'detail' => $detail,
            'branch' => [
                'code' => $branch->code,
                'name' => $branch->name,
                'tax_id' => $branch->tax_id,
                'currency' => $branch->currency ?: 'THB',
                'vat_rate' => (float) $branch->vat_rate,
                'vat_included' => (bool) $branch->vat_included,
                // ตอบคำถามข้อ 12 — ถ้า SAM ตัดรอบเที่ยงคืนจะได้รู้ว่าของเราไม่ใช่
                'business_day_start' => substr((string) $branch->business_day_start, 0, 5),
            ],
            'business_date' => $businessDate,
            'summary' => $this->summarise($bills),
        ];

        if ($detail !== 'summary') {
            $payload['bills'] = $bills;
        }

        return $payload;
    }

    /**
     * ยอดรวมของวัน — ฟังก์ชันล้วน ไม่แตะฐานข้อมูล
     *
     * ── ตัวเลขสองชุดที่ไม่เท่ากันและไม่ควรเท่า ──────────────
     * ยอดขาย   นับเฉพาะบิลที่ counts_as_sale (ปิดบิลแล้วและไม่ถูกยกเลิก)
     * ช่องทางเงิน  นับเงินที่เคลื่อนจริงทุกบิล แล้วแยกยอดคืนไว้ต่างหาก
     * บิลที่คืนเงินจึงไม่อยู่ในยอดขาย แต่ยังเห็นในช่องทางเงิน ซึ่งตรงกับสเตทเมนต์ธนาคาร
     *
     * @param  array<int, array<string, mixed>>  $bills
     * @return array<string, mixed>
     */
    public function summarise(array $bills): array
    {
        $sales = array_values(array_filter($bills, fn (array $b) => $b['counts_as_sale']));

        $sum = function (array $rows, string $key): float {
            return Money::round(array_sum(array_map(
                fn (array $b) => (float) $b['amounts'][$key],
                $rows,
            )));
        };

        $methods = [];

        foreach ($bills as $bill) {
            foreach ($bill['payments'] as $payment) {
                $key = $payment['method'];
                $methods[$key] ??= ['method' => $key, 'amount' => 0.0, 'fee' => 0.0, 'refund' => 0.0, 'count' => 0];
                $methods[$key]['amount'] += (float) $payment['amount'];
                $methods[$key]['fee'] += (float) $payment['fee'];
                $methods[$key]['count']++;
            }

            foreach ($bill['refunds'] as $refund) {
                $key = $refund['method'];
                $methods[$key] ??= ['method' => $key, 'amount' => 0.0, 'fee' => 0.0, 'refund' => 0.0, 'count' => 0];
                $methods[$key]['refund'] += (float) $refund['amount'];
            }
        }

        $methods = array_map(fn (array $m) => [
            ...$m,
            'amount' => Money::round($m['amount']),
            'fee' => Money::round($m['fee']),
            'refund' => Money::round($m['refund']),
            'net' => Money::round($m['amount'] - $m['fee'] - $m['refund']),
        ], $methods);

        ksort($methods);

        return [
            'bill_count' => count($sales),
            'void_count' => count(array_filter($bills, fn (array $b) => $b['status'] === OrderStatus::Void->value)),
            'refund_count' => count(array_filter($bills, fn (array $b) => $b['status'] === OrderStatus::Refunded->value)),
            'subtotal' => $sum($sales, 'subtotal'),
            'discount_total' => $sum($sales, 'discount_total'),
            'service_charge' => $sum($sales, 'service_charge'),
            'delivery_fee' => $sum($sales, 'delivery_fee'),
            'net_amount' => $sum($sales, 'net_amount'),
            'tax_amount' => $sum($sales, 'tax_amount'),
            'rounding' => $sum($sales, 'rounding'),
            'grand_total' => $sum($sales, 'grand_total'),
            'by_payment_method' => array_values($methods),
        ];
    }

    /**
     * ส่งข้อมูลของวันนั้นออกไป และบันทึกผล
     *
     * เรียกซ้ำได้ ถ้าส่งสำเร็จแล้วและข้อมูลต้นทางไม่เปลี่ยน จะไม่ส่งซ้ำให้
     * (ตอบคำถามข้อ 10 เรื่องกันข้อมูลซ้ำจากฝั่งเรา ไม่ใช่ไปพึ่งปลายทางอย่างเดียว)
     */
    public function run(Branch $branch, string $businessDate, ?User $user = null, bool $force = false): SalesExport
    {
        $export = SalesExport::firstOrNew([
            'branch_id' => $branch->id,
            'business_date' => $businessDate,
        ]);

        $fingerprint = $this->fingerprint($branch, $businessDate);

        if (! $force
            && $export->exists
            && $export->status === ExportStatus::Sent
            && $export->source_fingerprint === $fingerprint) {
            return $export;
        }

        $driver = $this->driver();
        $detail = (string) config('pos.export.detail', 'both');
        $payload = $this->payload($branch, $businessDate, $detail);

        $export->forceFill([
            'branch_id' => $branch->id,
            'business_date' => $businessDate,
            'driver' => $driver->name(),
            'detail' => $detail,
            'bill_count' => (int) $payload['summary']['bill_count'],
            'grand_total' => (float) $payload['summary']['grand_total'],
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'attempts' => (int) $export->attempts + 1,
            'created_by' => $user?->id ?? $export->created_by,
        ])->save();

        try {
            $result = $driver->send($payload, $export);

            $export->forceFill([
                'status' => ExportStatus::Sent,
                'format' => $result['format'] ?? null,
                'reference' => $result['reference'] ?? null,
                'source_fingerprint' => $fingerprint,
                'last_error' => null,
                'sent_at' => now(),
            ])->save();

            $this->logger->log('sales_export.sent', $export, [
                'business_date' => $businessDate,
                'driver' => $driver->name(),
                'bill_count' => $export->bill_count,
            ], $branch);
        } catch (\Throwable $e) {
            /*
            | ไม่โยนต่อ เพราะคำสั่งเดียวมักส่งหลายวันหลายสาขา
            | ถ้าหยุดที่ตัวแรกที่ล้ม วันที่เหลือจะไม่ถูกส่งทั้งที่ไม่มีอะไรผิด
            | ความล้มเหลวถูกบันทึกไว้ในแถวแล้ว และหน้าจอกับคำสั่งอ่านจากตรงนั้น
            */
            $export->forceFill([
                'status' => ExportStatus::Failed,
                'last_error' => Str::limit($e->getMessage(), 900),
            ])->save();

            $this->logger->log('sales_export.failed', $export, [
                'business_date' => $businessDate,
                'error' => Str::limit($e->getMessage(), 200),
            ], $branch);
        }

        return $export;
    }

    /**
     * ลายนิ้วมือของข้อมูลต้นทาง
     *
     * ── ทำไมไม่ประกอบ payload ใหม่แล้วแฮชเทียบ ────────────
     * เพราะหน้ารายการแสดงทีละหลายสิบวัน การประกอบ payload ทุกวันเพื่อดูว่า "เปลี่ยนไหม"
     * แพงเกินไปมากเมื่อเทียบกับสิ่งที่ได้ อันนี้ใช้สองคิวรีเล็ก ๆ ต่อวันแทน
     *
     * ── ข้อจำกัดที่ต้องรู้ ─────────────────────────────────
     * จับได้เฉพาะการเปลี่ยนที่ขยับ updated_at ของบิลหรือรายการในบิล
     * ถ้ามีใครแก้ฐานข้อมูลตรงโดยไม่ผ่านระบบ จะไม่รู้ — ซึ่งก็เป็นเรื่องที่รู้ไม่ได้อยู่ดี
     */
    public function fingerprint(Branch $branch, string $businessDate): string
    {
        $orders = DB::table('orders')
            ->where('branch_id', $branch->id)
            ->where('business_date', $businessDate)
            // ไม่มี join จึงอ้างชื่อคอลัมน์เปล่า ๆ ได้ ไม่ต้องกังวลเรื่อง prefix
            ->selectRaw('COUNT(*) AS bill_count, SUM(grand_total) AS total, MAX(updated_at) AS touched')
            ->first();

        $itemsTouched = DB::table('order_items')
            ->whereIn('order_id', function ($query) use ($branch, $businessDate) {
                $query->select('id')
                    ->from('orders')
                    ->where('branch_id', $branch->id)
                    ->where('business_date', $businessDate);
            })
            ->max('updated_at');

        return hash('sha256', implode('|', [
            $branch->id,
            $businessDate,
            (int) ($orders->bill_count ?? 0),
            number_format((float) ($orders->total ?? 0), 2, '.', ''),
            (string) ($orders->touched ?? ''),
            (string) ($itemsTouched ?? ''),
        ]));
    }

    /** ข้อมูลขยับหลังจากส่งไปแล้วหรือยัง */
    public function isStale(SalesExport $export, Branch $branch): bool
    {
        if ($export->status !== ExportStatus::Sent || blank($export->source_fingerprint)) {
            return false;
        }

        return $export->source_fingerprint !== $this->fingerprint(
            $branch,
            $export->business_date->toDateString(),
        );
    }

    public function driver(): SalesExportDriver
    {
        $name = (string) config('pos.export.driver', 'file');

        return match ($name) {
            'file' => app(FileExportDriver::class),
            // api / database จะเพิ่มตรงนี้เมื่อได้คำตอบจากทีม SAM
            default => throw new \RuntimeException("ยังไม่มีช่องทางส่งข้อมูลชื่อ [{$name}] — ตรวจค่า pos.export.driver"),
        };
    }

    /**
     * บิลของวันนั้นในรูปแบบมาตรฐาน
     *
     * @return array<int, array<string, mixed>>
     */
    protected function bills(Branch $branch, string $businessDate): array
    {
        return Order::with(['activeItems.modifiers', 'activeItems.product:id,sku', 'payments', 'refunds'])
            ->where('branch_id', $branch->id)
            ->where('business_date', $businessDate)
            // บิลที่ยังเปิดอยู่ยังไม่จบ ยอดเปลี่ยนได้ตลอด จึงไม่ส่ง
            ->whereIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::Void->value,
                OrderStatus::Refunded->value,
            ])
            ->orderBy('order_no')
            ->get()
            ->map(fn (Order $order) => $this->billOf($order, $branch))
            ->all();
    }

    /** @return array<string, mixed> */
    protected function billOf(Order $order, Branch $branch): array
    {
        $discount = Money::round(
            (float) $order->item_discount
            + (float) $order->bill_discount
            + (float) $order->promotion_discount
            + (float) $order->voucher_discount
            + (float) $order->staff_discount
        );

        $line = 0;

        return [
            // คีย์กันซ้ำที่ปลายทางใช้ได้เลย — ตอบคำถามข้อ 10
            'key' => $branch->code.'|'.$order->order_no,
            'uuid' => $order->uuid,
            'order_no' => $order->order_no,
            'receipt_no' => $order->receipt_no,
            'business_date' => $order->business_date->toDateString(),
            'closed_at' => $order->closed_at?->toIso8601String(),
            'type' => $order->type?->value,
            'channel' => $order->channel,
            'source' => $order->source?->value,
            'status' => $order->status->value,
            'counts_as_sale' => $order->status->countsAsSale(),
            'guest_count' => (int) $order->guest_count,
            'customer_name' => $order->contact_name,
            'amounts' => [
                'subtotal' => (float) $order->subtotal,
                'discount_total' => $discount,
                'service_charge' => (float) $order->service_charge,
                'delivery_fee' => (float) $order->delivery_fee,
                // ฐานภาษี = ยอดรวม − ภาษี − ปัดเศษ (วิธีเดียวกับรายงานภาษีขาย)
                'net_amount' => Money::round(
                    (float) $order->grand_total - (float) $order->tax_amount - (float) $order->rounding
                ),
                'tax_amount' => (float) $order->tax_amount,
                'rounding' => (float) $order->rounding,
                'grand_total' => (float) $order->grand_total,
            ],
            'payments' => $order->payments->map(fn ($p) => [
                'method' => $p->method?->value ?? (string) $p->method,
                'amount' => (float) $p->amount,
                'fee' => (float) $p->fee,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->all(),
            'refunds' => $order->refunds->map(fn ($r) => [
                'method' => (string) $r->method,
                'amount' => (float) $r->amount,
                'reason' => $r->reason,
                'refunded_at' => $r->refunded_at?->toIso8601String(),
            ])->all(),
            'lines' => $order->activeItems->map(function (OrderItem $item) use (&$line) {
                return [
                    'no' => ++$line,
                    // sku อาจว่างได้ถ้าเมนูถูกลบทิ้ง ชื่อกับราคาถูก snapshot ไว้แล้วจึงยังอ่านออก
                    'sku' => $item->product?->sku,
                    'product_id' => $item->product_id,
                    'name' => $item->product_name,
                    'category' => $item->category_name,
                    'qty' => (float) $item->qty,
                    'unit_price' => (float) $item->unit_price,
                    'modifier_total' => (float) $item->modifier_total,
                    'discount' => (float) $item->discount,
                    'line_total' => (float) $item->line_total,
                    'modifiers' => $item->modifiers->map(fn ($m) => [
                        'name' => $m->name,
                        'price' => (float) $m->price,
                    ])->all(),
                ];
            })->all(),
        ];
    }
}
