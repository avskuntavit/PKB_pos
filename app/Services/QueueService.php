<?php

namespace App\Services;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * เลขคิวรับอาหาร
 *
 * รันใหม่ทุกวันขาย แยกตามสาขา — ลูกค้าจะได้เห็นเลขสั้น ๆ (คิว 12)
 * แทนที่จะต้องจำเลขบิลยาว ๆ และพนักงานเรียกคิวได้ด้วยเสียง
 *
 * ใช้ร่วมกันทั้งออเดอร์ที่สั่งออนไลน์ล่วงหน้า และบิลที่พนักงานกดหน้าเคาน์เตอร์
 * เพราะสุดท้ายลูกค้ายืนรออยู่ที่เดียวกัน ต้องอยู่ในแถวเดียวกัน
 */
class QueueService
{
    /** ถ้าเรียกไปแล้วเกินเวลานี้ยังไม่มารับ ถือว่าควรเรียกซ้ำ */
    public const RECALL_AFTER_MINUTES = 3;

    /**
     * กดเรียกซ้ำเร็วกว่านี้ ถือว่านิ้วลั่น ไม่ใช่การเรียกซ้ำจริง
     *
     * หน้าจอกันกดซ้ำไว้ชั้นหนึ่งแล้ว แต่กันได้เฉพาะเครื่องนั้น
     * เน็ตสะดุดแล้วเบราว์เซอร์ยิงซ้ำ หรือพนักงานสองคนกดคิวเดียวกันพร้อมกัน
     * จะหลุดด่านนั้นมาได้ ถ้าไม่กันตรงนี้จอลูกค้าจะขึ้นว่า "เรียกครั้งที่ 3"
     * ทั้งที่พนักงานตั้งใจเรียกครั้งเดียว
     */
    protected const RECALL_DEBOUNCE_SECONDS = 4;

    /** ช่วงห่างระหว่างจานที่ยาวเกินนี้ ถือว่าร้านพัก ไม่เอามาคิดเวลารอ */
    protected const MAX_GAP_MINUTES = 30;

    /** ออกเลขคิวให้บิล ถ้ายังไม่มี */
    public function assign(Order $order): int
    {
        if ($order->queue_number) {
            return $order->queue_number;
        }

        return DB::transaction(function () use ($order) {
            $businessDate = $order->business_date->toDateString();

            $last = Order::where('branch_id', $order->branch_id)
                ->whereDate('business_date', $businessDate)
                ->lockForUpdate()
                ->max('queue_number');

            $next = $last
                ? $last + 1
                : (int) config('foodpos.queue_number.start_at', 1);

            $order->update(['queue_number' => $next]);

            return $next;
        });
    }

    /** มีกี่คิวที่อยู่ก่อนหน้าบิลนี้และยังทำไม่เสร็จ */
    public function aheadOf(Order $order): int
    {
        if (! $order->queue_number) {
            return 0;
        }

        return Order::where('branch_id', $order->branch_id)
            ->whereDate('business_date', $order->business_date->toDateString())
            ->whereNotNull('queue_number')
            ->where('queue_number', '<', $order->queue_number)
            ->where('status', '!=', OrderStatus::Void->value)
            ->whereIn('fulfilment_status', [
                FulfilmentStatus::Placed->value,
                FulfilmentStatus::Accepted->value,
                FulfilmentStatus::Preparing->value,
            ])
            ->count();
    }

    /* ---------- เรียกคิว ---------- */

    /**
     * เรียกคิว — ใช้ทั้งครั้งแรกและเรียกซ้ำ นับจำนวนครั้งไว้ให้พนักงานเห็น
     *
     * การเรียกคิวหมายถึง "ของพร้อมแล้ว มารับได้" จึงดันสถานะเป็นพร้อมรับไปด้วย
     * พนักงานจะได้ไม่ต้องกด 2 ปุ่ม และจอหน้าร้านขึ้นเลขทันที
     */
    public function call(Order $order): Order
    {
        if ($this->calledJustNow($order)) {
            return $order;
        }

        $order->forceFill([
            'queue_called_at' => now(),
            'queue_called_count' => min(255, (int) $order->queue_called_count + 1),
            'queue_skipped_at' => null,
            'fulfilment_status' => FulfilmentStatus::Ready,
            'ready_at' => $order->ready_at ?? now(),
        ])->save();

        return $order;
    }

    /** เพิ่งเรียกไปหยก ๆ หรือเปล่า */
    protected function calledJustNow(Order $order): bool
    {
        if (! $order->queue_called_at) {
            return false;
        }

        // abs เพราะทิศทางของ diff ขึ้นกับรุ่นของ Carbon และนาฬิกาเครื่องที่เพี้ยนไปเล็กน้อย
        return abs($order->queue_called_at->diffInSeconds(now())) < self::RECALL_DEBOUNCE_SECONDS;
    }

    /** เรียกแล้วลูกค้ายังไม่มา — พักไว้ก่อน ไม่ให้เกะกะจอ แต่ยังกดเรียกซ้ำได้ */
    public function skip(Order $order): Order
    {
        $order->forceFill(['queue_skipped_at' => now()])->save();

        return $order;
    }

    /** ส่งของให้ลูกค้าแล้ว — ออกจากแถว */
    public function complete(Order $order): Order
    {
        $order->forceFill([
            'fulfilment_status' => FulfilmentStatus::Completed,
            'completed_at' => $order->completed_at ?? now(),
            'queue_skipped_at' => null,
        ])->save();

        return $order;
    }

    /** คิวถัดไปที่ควรเรียก — ของพร้อมแล้วแต่ยังไม่เคยเรียก เลขน้อยสุดก่อน */
    public function nextToCall(Branch $branch): ?Order
    {
        return $this->todaysQueue($branch)
            ->where('fulfilment_status', FulfilmentStatus::Ready->value)
            ->whereNull('queue_called_at')
            ->orderBy('queue_number')
            ->first();
    }

    /* ---------- เวลารอโดยประมาณ ---------- */

    /**
     * เดาว่าลูกค้าต้องรออีกกี่นาที
     *
     * วัดจากจังหวะที่ร้านทำเสร็จจริงในวันนี้ (ห่างกันเฉลี่ยกี่นาทีต่อจาน)
     * ถ้ายังทำไม่ถึง 3 จาน ก็ยังไม่มีข้อมูล ให้ถอยไปใช้เวลาครัวที่ตั้งไว้
     *
     * คืน null เมื่อไม่ต้องรอแล้ว (ของพร้อม / จบไปแล้ว) เพื่อให้หน้าบ้านซ่อนบรรทัดนี้ไปเลย
     */
    public function estimatedWaitMinutes(Order $order): ?int
    {
        $status = $order->fulfilment_status;

        if ($status === null || $status->isFinished() || $status === FulfilmentStatus::Ready) {
            return null;
        }

        $branch = $order->branch;
        $prep = max(5, (int) ($branch->prep_minutes ?? 15));

        $gap = $this->averageGapMinutes($order->branch_id, $order->business_date->toDateString())
            ?? max(2.0, $prep / 2);

        $minutes = $this->aheadOf($order) * $gap + $prep;

        // ปัดขึ้นทีละ 5 นาที — บอกละเอียดกว่านี้ก็ไม่จริงอยู่ดี และลูกค้าอ่านง่ายกว่า
        return (int) (ceil($minutes / 5) * 5);
    }

    /** ช่วงห่างเฉลี่ยระหว่างจานที่ทำเสร็จวันนี้ (นาที) */
    protected function averageGapMinutes(int $branchId, string $businessDate): ?float
    {
        /** @var list<Carbon> $times */
        $times = Order::where('branch_id', $branchId)
            ->whereDate('business_date', $businessDate)
            ->whereNotNull('ready_at')
            ->orderByDesc('ready_at')
            ->limit(30)
            ->pluck('ready_at')
            ->reverse()
            ->values()
            ->all();

        if (count($times) < 3) {
            return null;
        }

        $gaps = [];

        for ($i = 1, $n = count($times); $i < $n; $i++) {
            $minutes = $times[$i - 1]->diffInSeconds($times[$i]) / 60;

            if ($minutes > 0 && $minutes <= self::MAX_GAP_MINUTES) {
                $gaps[] = $minutes;
            }
        }

        return $gaps === [] ? null : array_sum($gaps) / count($gaps);
    }

    /**
     * ซิงค์เลขคิวและสถานะของออเดอร์ที่มีตั๋วครัวในวันนี้แต่ยังไม่มีเลขคิวหรือสถานะไม่ตรง
     */
    public function syncActiveKitchenOrders(Branch $branch): void
    {
        $businessDate = $branch->businessDateFor()->toDateString();

        $orders = Order::where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate)
            ->where('status', '!=', OrderStatus::Void->value)
            ->whereHas('kitchenTickets', function ($q) {
                $q->whereIn('status', [
                    \App\Enums\KitchenTicketStatus::Queued->value,
                    \App\Enums\KitchenTicketStatus::Preparing->value,
                    \App\Enums\KitchenTicketStatus::Ready->value,
                ]);
            })
            ->with(['kitchenTickets' => function ($q) {
                $q->where('status', '!=', \App\Enums\KitchenTicketStatus::Cancelled->value);
            }])
            ->get();

        foreach ($orders as $order) {
            if ($order->queue_number === null) {
                $this->assign($order);
            }

            $activeTickets = $order->kitchenTickets;
            if ($activeTickets->isEmpty()) {
                continue;
            }

            $hasPreparing = $activeTickets->contains(fn ($t) => $t->status === \App\Enums\KitchenTicketStatus::Preparing);
            $hasQueued = $activeTickets->contains(fn ($t) => $t->status === \App\Enums\KitchenTicketStatus::Queued);
            $hasReady = $activeTickets->contains(fn ($t) => $t->status === \App\Enums\KitchenTicketStatus::Ready);
            $allReadyOrServed = $activeTickets->every(fn ($t) => in_array($t->status, [\App\Enums\KitchenTicketStatus::Ready, \App\Enums\KitchenTicketStatus::Served], true));

            $newStatus = null;
            if ($allReadyOrServed && $hasReady) {
                $newStatus = FulfilmentStatus::Ready;
            } elseif ($hasPreparing || $hasReady) {
                $newStatus = FulfilmentStatus::Preparing;
            } elseif ($hasQueued) {
                $newStatus = FulfilmentStatus::Accepted;
            }

            if ($newStatus && $order->fulfilment_status !== $newStatus) {
                $order->forceFill([
                    'fulfilment_status' => $newStatus,
                    'accepted_at' => $order->accepted_at ?? now(),
                    'ready_at' => $newStatus === FulfilmentStatus::Ready ? ($order->ready_at ?? now()) : $order->ready_at,
                ])->save();
            }
        }
    }

    /* ---------- จอแสดงคิวหน้าร้าน ---------- */

    /**
     * ข้อมูลสำหรับจอที่แขวนหน้าร้าน — เห็นแค่เลขคิวกับชื่อต้น
     *
     * last_called ใช้เป็นตัวจุดเสียงเตือน จอจะเทียบกับรอบก่อนแล้วดังเมื่อมีเลขใหม่ถูกเรียก
     */
    public function board(Branch $branch): array
    {
        $this->syncActiveKitchenOrders($branch);

        $orders = $this->todaysQueue($branch)
            ->with('diningTable:id,name')
            ->whereIn('fulfilment_status', [
                FulfilmentStatus::Accepted->value,
                FulfilmentStatus::Preparing->value,
                FulfilmentStatus::Ready->value,
            ])
            ->orderBy('queue_number')
            ->limit(60)
            ->get(['id', 'branch_id', 'dining_table_id', 'queue_number', 'contact_name', 'fulfilment_status', 'ready_at', 'queue_called_at', 'queue_called_count', 'queue_skipped_at']);

        $ready = $orders->filter(fn (Order $o) => $o->fulfilment_status === FulfilmentStatus::Ready);

        return [
            'business_date' => $branch->businessDateFor()->toDateString(),
            'preparing' => $this->publicRows(
                $orders->filter(fn (Order $o) => $o->fulfilment_status !== FulfilmentStatus::Ready)
            ),
            'ready' => $this->publicRows($ready->filter(fn (Order $o) => $o->queue_skipped_at === null)),
            'missed' => $this->publicRows($ready->filter(fn (Order $o) => $o->queue_skipped_at !== null)),
            'last_called' => $this->lastCalled($ready),
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * ข้อมูลสำหรับหน้าจัดการคิวของพนักงาน
     *
     * มีรายละเอียดมากกว่าจอหน้าร้าน (เลขบิล ช่องทาง จำนวนรายการ) เพราะพนักงานต้องหยิบของถูกใบ
     */
    public function staffBoard(Branch $branch): array
    {
        $this->syncActiveKitchenOrders($branch);

        $orders = $this->todaysQueue($branch)
            ->with('diningTable:id,name')
            ->whereIn('fulfilment_status', [
                FulfilmentStatus::Placed->value,
                FulfilmentStatus::Accepted->value,
                FulfilmentStatus::Preparing->value,
                FulfilmentStatus::Ready->value,
            ])
            ->withCount(['activeItems as item_count'])
            ->orderBy('queue_number')
            ->limit(100)
            ->get();

        $ready = $orders->filter(fn (Order $o) => $o->fulfilment_status === FulfilmentStatus::Ready);

        return [
            'business_date' => $branch->businessDateFor()->toDateString(),
            'waiting' => $this->staffRows(
                $orders->filter(fn (Order $o) => $o->fulfilment_status !== FulfilmentStatus::Ready)
            ),
            'ready' => $this->staffRows($ready->filter(fn (Order $o) => $o->queue_skipped_at === null)),
            'missed' => $this->staffRows($ready->filter(fn (Order $o) => $o->queue_skipped_at !== null)),
            'next_id' => $ready
                ->filter(fn (Order $o) => $o->queue_called_at === null)
                ->sortBy('queue_number')
                ->first()?->id,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /* ---------- ภายใน ---------- */

    /** ฐานของทุก query: คิวของสาขานี้ ในวันขายวันนี้ ที่บิลยังไม่ถูกยกเลิก */
    protected function todaysQueue(Branch $branch)
    {
        return Order::where('branch_id', $branch->id)
            ->whereDate('business_date', $branch->businessDateFor()->toDateString())
            ->whereNotNull('queue_number')
            // บิลที่ถูกยกเลิกยังถือเลขคิวไว้ (ไว้ตรวจย้อนหลัง) แต่ไม่ควรค้างอยู่บนจอ
            ->where('status', '!=', OrderStatus::Void->value);
    }

    /** @param  \Illuminate\Support\Collection<int, Order>|EloquentCollection<int, Order>  $orders */
    protected function publicRows($orders): array
    {
        return $orders->map(function (Order $o) {
            $displayName = $this->maskName($o->contact_name);
            if (blank($displayName) && $o->diningTable) {
                $displayName = 'โต๊ะ ' . $o->diningTable->name;
            }

            return [
                'queue_number' => $o->queue_number,
                // แสดงแค่ชื่อต้น หรือชื่อโต๊ะ
                'name' => $displayName,
                'ready_at' => $o->ready_at?->toIso8601String(),
                'called_at' => $o->queue_called_at?->toIso8601String(),
                'called_count' => (int) $o->queue_called_count,
            ];
        })->values()->all();
    }

    /** @param  \Illuminate\Support\Collection<int, Order>|EloquentCollection<int, Order>  $orders */
    protected function staffRows($orders): array
    {
        return $orders->map(fn (Order $o) => [
            'id' => $o->id,
            'queue_number' => $o->queue_number,
            'order_no' => $o->order_no,
            'name' => $o->contact_name ?: ($o->diningTable ? 'โต๊ะ ' . $o->diningTable->name : null),
            'phone' => $o->contact_phone,
            'source' => $o->source->value,
            'source_label' => $o->diningTable ? ('โต๊ะ ' . $o->diningTable->name) : ($o->track_token ? 'สั่งออนไลน์' : 'หน้าร้าน'),
            'status' => $o->fulfilment_status->value,
            'status_label' => $o->fulfilment_status->label(),
            'item_count' => (int) ($o->item_count ?? 0),
            'grand_total' => (float) $o->grand_total,
            'is_paid' => $o->status->countsAsSale(),
            'pickup_at' => $o->pickup_at?->toIso8601String(),
            'called_at' => $o->queue_called_at?->toIso8601String(),
            'called_count' => (int) $o->queue_called_count,
            'skipped_at' => $o->queue_skipped_at?->toIso8601String(),
        ])->values()->all();
    }

    /** @param  \Illuminate\Support\Collection<int, Order>  $ready */
    protected function lastCalled($ready): ?array
    {
        $order = $ready->filter(fn (Order $o) => $o->queue_called_at !== null)
            ->sortByDesc(fn (Order $o) => $o->queue_called_at->getTimestamp())
            ->first();

        if (! $order) {
            return null;
        }

        return [
            'queue_number' => $order->queue_number,
            'at' => $order->queue_called_at->toIso8601String(),
            'count' => (int) $order->queue_called_count,
        ];
    }

    /** "สมชาย ใจดี" -> "สมชาย" / ไม่มีชื่อก็ไม่ต้องโชว์ */
    protected function maskName(?string $name): ?string
    {
        if (blank($name)) {
            return null;
        }

        return explode(' ', trim($name))[0];
    }
}
