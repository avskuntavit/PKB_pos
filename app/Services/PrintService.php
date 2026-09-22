<?php

namespace App\Services;

use App\Enums\PrintGroup;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Printing\Documents\KitchenTicketDocument;
use App\Printing\Documents\ReceiptDocument;
use App\Printing\EscposBuilder;
use App\Printing\PrinterException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * คิวงานพิมพ์
 *
 * ── ทำไมต้องมีคิว ไม่ยิงตรงไปเลย ─────────────────────────────
 * เครื่องพิมพ์กระดาษหมด ฝาเปิด สายหลุด ไฟดับ — เกิดทุกวันในร้านจริง
 * ถ้ายิงตรงแล้วพังตอนนั้น ใบสั่งครัวจะหายไปเฉย ๆ โดยไม่มีใครรู้
 * จนอาหารไม่ออกแล้วลูกค้ามาถาม
 *
 * ── จังหวะการทำงาน ──────────────────────────────────────────
 * 1. สร้างงาน -> ลองพิมพ์ทันทีเลย (กรณีปกติ กระดาษออกใน ~50 มิลลิวินาที)
 * 2. ไม่สำเร็จ -> งานค้างไว้ในคิว ถอยเวลาแล้วให้ printers:work มาลองใหม่
 * 3. ครบจำนวนครั้ง -> ขึ้นสถานะ failed รอคนกดสั่งพิมพ์ซ้ำ
 *
 * ขั้นที่ 1 ทำในคำขอเดียวกับที่พนักงานกดส่งครัว แต่ห้ามโยน exception ออกไป
 * บิลต้องบันทึกสำเร็จเสมอ ต่อให้เครื่องพิมพ์ดับอยู่
 */
class PrintService
{
    public function __construct(protected ActivityLogger $logger) {}

    /* ---------- สร้างงาน ---------- */

    /**
     * ใบสั่งครัว — ส่งไปทุกเครื่องที่รับจุดผลิตนี้
     *
     * ไม่มีเครื่องไหนรับเลยก็ไม่สร้างงาน ไม่ใช่ error — ร้านที่ยังใช้ระบบ
     * แบบตะโกนบอกครัวก็ไม่ต้องตั้งเครื่องพิมพ์
     */
    public function queueKitchenTicket(KitchenTicket $ticket): int
    {
        $printers = $this->printersFor($ticket->branch_id)
            ->filter(fn (Printer $p) => $p->handles($ticket->print_group));

        $queued = 0;

        foreach ($printers as $printer) {
            $bytes = (new KitchenTicketDocument($ticket, $printer->columns))->render();

            $this->push($printer, 'kitchen_ticket', $bytes, $ticket,
                $ticket->print_group->label().' '.$ticket->ticket_no);

            $queued++;
        }

        return $queued;
    }

    /** ใบเสร็จ + เตะลิ้นชักในงานเดียวกัน ถ้าลิ้นชักต่ออยู่ที่เครื่องนั้น */
    public function queueReceipt(Order $order, bool $openDrawer = false): int
    {
        $printers = $this->printersFor($order->branch_id)
            ->filter(fn (Printer $p) => $p->prints_receipt);

        $queued = 0;

        foreach ($printers as $printer) {
            $doc = new ReceiptDocument($order, $printer->columns);

            // เตะลิ้นชักก่อนเริ่มพิมพ์ ลิ้นชักจะได้เปิดพร้อมกระดาษออก
            // ไม่ใช่เปิดตอนกระดาษออกหมดแล้วซึ่งช้าไปครึ่งวินาที
            $bytes = ($openDrawer && $printer->opens_cash_drawer)
                ? EscposBuilder::make($printer->columns)->begin()->kickDrawer()->toBytes().$doc->render()
                : $doc->render();

            $this->push($printer, 'receipt', $bytes, $order, 'ใบเสร็จ '.$order->order_no);

            $queued++;
        }

        return $queued;
    }

    /** เตะลิ้นชักอย่างเดียว เช่น ตอนพนักงานขอทอนเงินย่อย */
    public function queueDrawerKick(Branch $branch): int
    {
        $printer = $this->printersFor($branch->id)->first(fn (Printer $p) => $p->opens_cash_drawer);

        if (! $printer) {
            return 0;
        }

        $bytes = EscposBuilder::make($printer->columns)->begin()->kickDrawer()->toBytes();
        $this->push($printer, 'drawer', $bytes, null, 'เปิดลิ้นชัก');

        return 1;
    }

    /** ใบทดสอบ — ใช้ตอนตั้งค่าเครื่องพิมพ์ครั้งแรก */
    public function queueTest(Printer $printer): PrintJob
    {
        $b = EscposBuilder::make($printer->columns)->begin();

        $b->align(1)->size(2, 2)->bold()->line('ทดสอบการพิมพ์')->normal();
        $b->align(1)->line($printer->name)->line($printer->host.':'.$printer->port);
        $b->feed()->align(0)->rule();
        $b->line('ภาษาไทย: ก๋วยเตี๋ยวน้ำตก ต้มยำกุ้ง');
        $b->line('ตัวเลข: 0123456789');
        $b->columns('ทดสอบคอลัมน์ซ้าย', '999.00');
        $b->rule();
        $b->line('กว้าง '.$printer->columns.' ตัวอักษรต่อบรรทัด');
        $b->line(str_repeat('.', $printer->columns));
        $b->line('ถ้าจุดด้านบนเต็มบรรทัดพอดี แปลว่าตั้งค่าถูก');
        $b->cut();

        return $this->push($printer, 'test', $b->toBytes(), null, 'ทดสอบ '.$printer->name);
    }

    /* ---------- ส่งงาน ---------- */

    /**
     * ดึงงานที่ถึงคิวมาพิมพ์
     *
     * @return array{done: int, failed: int}
     */
    public function work(?int $branchId = null, int $limit = 20): array
    {
        $jobs = PrintJob::ready()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $done = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $this->attempt($job) ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * ลองพิมพ์หนึ่งงาน — คืน true เมื่อกระดาษออกแล้ว
     *
     * ไม่โยน exception ออกไปไหน ความล้มเหลวของเครื่องพิมพ์ไม่ควรทำให้
     * การบันทึกบิลล้มตาม
     */
    public function attempt(PrintJob $job): bool
    {
        $printer = $job->printer;

        if (! $printer || ! $printer->is_active) {
            $this->markFailed($job, 'ไม่พบเครื่องพิมพ์ หรือเครื่องถูกปิดใช้งาน', permanent: true);

            return false;
        }

        // กันสองโปรเซสหยิบงานเดียวกัน — อัปเดตแบบมีเงื่อนไข ใครได้ 1 แถวคนนั้นได้งาน
        $claimed = PrintJob::where('id', $job->id)
            ->where('status', PrintJob::STATUS_PENDING)
            ->update(['status' => PrintJob::STATUS_PRINTING, 'attempts' => DB::raw('attempts + 1')]);

        if ($claimed === 0) {
            return false;
        }

        $job->refresh();

        try {
            for ($copy = 0; $copy < max(1, $printer->copies); $copy++) {
                $printer->driver()->send($printer, $job->payload);
            }

            $job->forceFill([
                'status' => PrintJob::STATUS_DONE,
                'printed_at' => now(),
                'last_error' => null,
            ])->save();

            return true;
        } catch (PrinterException $e) {
            $this->markFailed($job, $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            $this->markFailed($job, 'ผิดพลาดไม่ทราบสาเหตุ: '.$e->getMessage());

            return false;
        }
    }

    /* ---------- ภายใน ---------- */

    /** สร้างงานแล้วลองพิมพ์ทันที — สำเร็จก็จบในคำขอเดียว */
    protected function push(Printer $printer, string $kind, string $bytes, ?Model $source, ?string $title): PrintJob
    {
        $job = new PrintJob([
            'branch_id' => $printer->branch_id,
            'printer_id' => $printer->id,
            'kind' => $kind,
            'title' => $title,
            'payload' => $bytes,
            'status' => PrintJob::STATUS_PENDING,
        ]);

        if ($source) {
            $job->source()->associate($source);
        }

        $job->save();

        $this->attempt($job);

        return $job->fresh();
    }

    /**
     * บันทึกความล้มเหลวแล้วตัดสินว่าจะลองใหม่ไหม
     *
     * ถอยเวลาแบบเพิ่มขึ้นเรื่อย ๆ (5, 20, 45, 80 วินาที) เครื่องพิมพ์ที่กระดาษหมด
     * ต้องใช้เวลาให้คนเดินไปเปลี่ยน ยิงรัวทุกวินาทีไม่ได้ช่วยอะไร
     */
    protected function markFailed(PrintJob $job, string $error, bool $permanent = false): void
    {
        $exhausted = $permanent || $job->isExhausted();

        $job->forceFill([
            'status' => $exhausted ? PrintJob::STATUS_FAILED : PrintJob::STATUS_PENDING,
            'last_error' => $error,
            'available_at' => $exhausted ? null : now()->addSeconds(5 * $job->attempts * $job->attempts),
        ])->save();

        if ($exhausted) {
            $this->logger->log('print.failed', $job, ['error' => $error, 'title' => $job->title]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, Printer> */
    protected function printersFor(int $branchId)
    {
        return Printer::where('branch_id', $branchId)
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Printer $p) => $p->isConfigured());
    }
}
