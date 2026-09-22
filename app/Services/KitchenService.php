<?php

namespace App\Services;

use App\Enums\Course;
use App\Enums\KitchenTicketStatus;
use App\Enums\OrderSource;
use App\Enums\PrintGroup;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ใบสั่งครัว
 *
 * "ส่งครัว" 1 ครั้ง = 1 รอบ (round) ของบิลนั้น
 * ในรอบเดียวกัน ถ้ามีทั้งอาหารและเครื่องดื่ม จะแตกเป็นคนละใบตามจุดผลิต
 * เพราะครัวกับบาร์อยู่คนละที่และทำงานคนละคิว
 */
class KitchenService
{
    public function __construct(
        protected ActivityLogger $logger,
        protected PrintService $printing,
        protected QueueService $queue,
    ) {}

    /**
     * สร้างใบสั่งครัวจากรายการที่เพิ่งส่งครัว
     *
     * @param  Collection<int, OrderItem>  $items
     * @return Collection<int, KitchenTicket>
     */
    public function createTickets(
        Order $order,
        Collection $items,
        OrderSource $source = OrderSource::Pos,
        ?Course $course = null,
    ): Collection {
        if ($items->isEmpty()) {
            return collect();
        }

        return DB::transaction(function () use ($order, $items, $source, $course) {
            $order->loadMissing('branch', 'diningTable');

            $round = ((int) KitchenTicket::where('order_id', $order->id)->max('round')) + 1;
            $businessDate = $order->business_date?->toDateString()
                ?? $order->branch->businessDateFor()->toDateString();

            $items->loadMissing('product', 'modifiers');

            // แตกตามจุดผลิต — สินค้าที่ไม่ได้ตั้งค่าไว้ให้ถือว่าเป็นครัว
            $grouped = $items->groupBy(
                fn (OrderItem $item) => ($item->product?->print_group ?? PrintGroup::Kitchen)->value
            );

            $tickets = collect();

            foreach ($grouped as $printGroup => $groupItems) {
                $ticket = KitchenTicket::create([
                    'branch_id' => $order->branch_id,
                    'order_id' => $order->id,
                    'dining_table_id' => $order->dining_table_id,
                    'ticket_no' => $this->nextTicketNo($order, $businessDate),
                    'print_group' => PrintGroup::from((int) $printGroup),
                    'round' => $round,
                    'course' => $course,
                    'status' => KitchenTicketStatus::Queued,
                    'source' => $source,
                    'order_type' => $order->type->value,
                    'created_by' => auth()->id(),
                    'queued_at' => now(),
                    'business_date' => $businessDate,
                ]);

                foreach ($groupItems as $item) {
                    $ticket->items()->create([
                        'order_item_id' => $item->id,
                        'product_name' => $item->product_name,
                        'qty' => $item->qty,
                        'modifiers_text' => $item->modifiersText(),
                        'note' => $item->note,
                    ]);
                }

                $tickets->push($ticket->load('items'));
            }

            $this->logger->log('kitchen.ticket_created', $order, [
                'round' => $round,
                'tickets' => $tickets->pluck('ticket_no')->all(),
                'source' => $source->value,
            ], $order->branch);

            /*
            | ส่งเข้าคิวพิมพ์
            |
            | กรณีปกติกระดาษออกตรงนี้เลยภายในเสี้ยววินาที ถ้าเครื่องพิมพ์ไม่พร้อม
            | งานจะค้างคิวไว้ให้ printers:work มาลองใหม่ — ซึ่งห้ามทำให้การส่งครัว
            | ล้มตาม บิลต้องบันทึกสำเร็จเสมอ ต่อให้เครื่องพิมพ์ถูกถอดปลั๊กอยู่
            |
            | ร้านที่ไม่ได้ตั้งเครื่องพิมพ์ไว้เลยก็ไม่เกิดอะไรขึ้น ไม่ใช่ข้อผิดพลาด
            */
            foreach ($tickets as $ticket) {
                $this->printing->queueKitchenTicket($ticket);
            }

            $this->syncOrderFulfilment($order);

            return $tickets;
        });
    }

    /** เลื่อนสถานะไปขั้นถัดไป (รอทำ → กำลังทำ → รอเสิร์ฟ → เสิร์ฟแล้ว) */
    public function advance(KitchenTicket $ticket): KitchenTicket
    {
        $next = $ticket->status->next();

        if (! $next) {
            return $ticket;
        }

        return $this->moveTo($ticket, $next);
    }

    public function moveTo(KitchenTicket $ticket, KitchenTicketStatus $status): KitchenTicket
    {
        return DB::transaction(function () use ($ticket, $status) {
            $timestamps = match ($status) {
                KitchenTicketStatus::Preparing => ['started_at' => now()],
                KitchenTicketStatus::Ready => ['ready_at' => now()],
                KitchenTicketStatus::Served => ['served_at' => now()],
                default => [],
            };

            $ticket->update(['status' => $status] + $timestamps);

            // เสิร์ฟแล้วให้รายการในบิลขยับสถานะตาม เพื่อให้หน้า POS เห็นตรงกัน
            if ($status === KitchenTicketStatus::Served) {
                OrderItem::whereIn('id', $ticket->items()->pluck('order_item_id')->filter())
                    ->where('status', '!=', 'void')
                    ->update(['status' => 'served']);
            }

            $this->syncOrderFulfilment($ticket->order);

            return $ticket;
        });
    }

    public function syncOrderFulfilment(?Order $order): void
    {
        if (! $order) {
            return;
        }

        $order->loadMissing('kitchenTickets');

        $activeTickets = $order->kitchenTickets
            ->where('status', '!=', KitchenTicketStatus::Cancelled);

        if ($activeTickets->isEmpty()) {
            return;
        }

        if ($order->queue_number === null) {
            $this->queue->assign($order);
        }

        $hasPreparing = $activeTickets->contains(fn ($t) => $t->status === KitchenTicketStatus::Preparing);
        $hasQueued = $activeTickets->contains(fn ($t) => $t->status === KitchenTicketStatus::Queued);
        $hasReady = $activeTickets->contains(fn ($t) => $t->status === KitchenTicketStatus::Ready);
        $allServed = $activeTickets->every(fn ($t) => $t->status === KitchenTicketStatus::Served);
        $allReadyOrServed = $activeTickets->every(fn ($t) => in_array($t->status, [KitchenTicketStatus::Ready, KitchenTicketStatus::Served], true));

        $newStatus = null;
        if ($allServed) {
            $newStatus = \App\Enums\FulfilmentStatus::Completed;
        } elseif ($allReadyOrServed && $hasReady) {
            $newStatus = \App\Enums\FulfilmentStatus::Ready;
        } elseif ($hasPreparing || $hasReady) {
            $newStatus = \App\Enums\FulfilmentStatus::Preparing;
        } elseif ($hasQueued) {
            $newStatus = \App\Enums\FulfilmentStatus::Accepted;
        }

        if ($newStatus) {
            $order->forceFill([
                'fulfilment_status' => $newStatus,
                'accepted_at' => $order->accepted_at ?? now(),
                'ready_at' => $newStatus === \App\Enums\FulfilmentStatus::Ready ? ($order->ready_at ?? now()) : $order->ready_at,
                'completed_at' => $newStatus === \App\Enums\FulfilmentStatus::Completed ? ($order->completed_at ?? now()) : $order->completed_at,
            ])->save();
        }
    }

    public function cancel(KitchenTicket $ticket, ?string $reason = null): KitchenTicket
    {
        $ticket->update(['status' => KitchenTicketStatus::Cancelled, 'note' => $reason]);
        $ticket->items()->update(['status' => 'cancelled']);

        $this->syncOrderFulfilment($ticket->order);

        return $ticket;
    }

    public function markPrinted(KitchenTicket $ticket): KitchenTicket
    {
        $ticket->forceFill(['printed_at' => now()])->save();

        return $ticket;
    }

    /** เลขที่ใบสั่งครัว เช่น K2609160042 */
    protected function nextTicketNo(Order $order, string $businessDate): string
    {
        $prefix = 'K'.date('ymd', strtotime($businessDate));

        $last = KitchenTicket::where('branch_id', $order->branch_id)
            ->where('ticket_no', 'like', $prefix.'%')
            ->max('ticket_no');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
