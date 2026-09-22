<?php

namespace App\Http\Controllers\Pos;

use App\Enums\KitchenTicketStatus;
use App\Enums\PrintGroup;
use App\Http\Controllers\Controller;
use App\Models\KitchenTicket;
use App\Services\KitchenService;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KitchenController extends Controller
{
    /** หน้าจอครัว (KDS) */
    public function index(Request $request): Response
    {
        $printGroup = $request->integer('print_group') ?: null;

        return Inertia::render('Pos/Kitchen', [
            'tickets' => $this->tickets($printGroup),
            'printGroups' => PrintGroup::options(),
            'filters' => ['print_group' => $printGroup],
        ]);
    }

    /** ข้อมูลเดียวกันแบบ JSON — หน้าจอครัว poll ทุก 8 วินาที */
    public function feed(Request $request): JsonResponse
    {
        return response()->json([
            'tickets' => $this->tickets($request->integer('print_group') ?: null),
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    /** กดปุ่มหลัก: รอทำ → กำลังทำ → รอเสิร์ฟ → เสิร์ฟแล้ว */
    public function advance(KitchenTicket $ticket, KitchenService $kitchen): RedirectResponse
    {
        $this->authorizeTicket($ticket);
        $kitchen->advance($ticket);

        return back();
    }

    public function moveTo(Request $request, KitchenTicket $ticket, KitchenService $kitchen): RedirectResponse
    {
        $this->authorizeTicket($ticket);

        $data = $request->validate([
            'status' => ['required', 'in:queued,preparing,ready,served'],
        ]);

        $kitchen->moveTo($ticket, KitchenTicketStatus::from($data['status']));

        return back();
    }

    public function cancel(Request $request, KitchenTicket $ticket, KitchenService $kitchen): RedirectResponse
    {
        $this->authorizeTicket($ticket);
        abort_unless($request->user()->role->canVoidBill(), 403, 'ต้องเป็นผู้จัดการขึ้นไปจึงจะยกเลิกใบสั่งครัวได้');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $kitchen->cancel($ticket, $data['reason'] ?? null);

        return back()->with('success', 'ยกเลิกใบสั่งครัวแล้ว');
    }

    /** สลิปใบสั่งครัวขนาด 80 มม. สำหรับสั่งพิมพ์จากเบราว์เซอร์ */
    public function print(KitchenTicket $ticket, KitchenService $kitchen): Response
    {
        $this->authorizeTicket($ticket);

        $ticket->load(['items', 'diningTable:id,name', 'order:id,order_no,type', 'createdBy:id,name']);
        $kitchen->markPrinted($ticket);

        return Inertia::render('Pos/KitchenTicket', [
            'ticket' => array_merge($ticket->toArray(), [
                'print_group_label' => $ticket->print_group->label(),
                'status_label' => $ticket->status->label(),
            ]),
        ]);
    }

    /* ---------- ภายใน ---------- */

    protected function tickets(?int $printGroup): array
    {
        return KitchenTicket::with(['items', 'diningTable:id,name'])
            ->where('branch_id', CurrentBranch::id())
            ->open()
            ->when($printGroup, fn ($q) => $q->where('print_group', $printGroup))
            ->orderBy('queued_at')
            ->limit(60)
            ->get()
            ->map(fn (KitchenTicket $t) => [
                'id' => $t->id,
                'ticket_no' => $t->ticket_no,
                'table' => $t->diningTable?->name,
                'print_group' => $t->print_group->value,
                'print_group_label' => $t->print_group->label(),
                'round' => $t->round,
                // คอร์สบนใบ — null เมื่อใบนั้นรวมหลายคอร์ส หรือร้านไม่ได้จัดคอร์ส
                'course' => $t->course?->value,
                'course_label' => $t->course?->label(),
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'source' => $t->source->value,
                'order_type' => $t->order_type,
                'queued_at' => $t->queued_at?->toIso8601String(),
                'waiting_minutes' => $t->waitingMinutes(),
                'items' => $t->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->product_name,
                    'qty' => (float) $i->qty,
                    'modifiers_text' => $i->modifiers_text,
                    'note' => $i->note,
                ]),
            ])
            ->all();
    }

    protected function authorizeTicket(KitchenTicket $ticket): void
    {
        abort_unless($ticket->branch_id === CurrentBranch::id(), 403);
    }
}
