<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\OfflineSyncEntry;
use App\Services\ActivityLogger;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * เงินที่รับจากลูกค้าไปแล้วตอนระบบล่ม แต่ลงบิลไม่ได้
 *
 * ── ทำไมต้องมีหน้านี้ ────────────────────────────────────────────────────
 * พนักงานเก็บเงินตอนเน็ตหลุด ลูกค้าเดินออกจากร้านไปแล้ว พอเน็ตกลับมาปรากฏว่า
 * ลงบิลไม่ได้ (มีคนปิดบิลนั้นไปก่อน / ยอดเปลี่ยน / งวดถูกล็อก) — เงินอยู่ในลิ้นชักจริง
 * แต่ไม่มีบิลรองรับ ถ้าไม่มีที่ให้มันโผล่ เงินก้อนนี้จะกลายเป็น "เงินเกิน" ปริศนา
 * ตอนนับลิ้นชักปลายวัน แล้วไม่มีใครรู้ว่ามาจากไหน
 *
 * ── ระบบไม่เปิดบิลใหม่ให้เอง ─────────────────────────────────────────────
 * ตั้งใจ — การสร้างบิลอัตโนมัติจากเงินที่ไม่รู้ที่มา คือการสร้างยอดขายปลอมได้ง่ายเกินไป
 * ผู้จัดการทำด้วยมือ แล้วมาบันทึกว่าทำอะไรไป หน้านี้เก็บ "คำตัดสิน" ไม่ได้เก็บ "การกระทำ"
 *
 * ── สิทธิ์ ───────────────────────────────────────────────────────────────
 * ใช้ cash.settle_verify ตัวเดียวกับการตรวจว่าเงินสดเข้าบัญชีจริง
 * เพราะเป็นคำถามเดียวกันคือ "เงินก้อนนี้ไปไหน" และผู้จัดการมีสิทธิ์นี้อยู่แล้ว
 */
class OfflineHoldController extends Controller
{
    public function __construct(protected ActivityLogger $logger) {}

    public function index(Request $request): Response
    {
        $branch = CurrentBranch::getOrFail();

        return Inertia::render('BackOffice/OfflineHolds/Index', [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],

            'holds' => OfflineSyncEntry::openHolds()
                ->where('branch_id', $branch->id)
                ->with(['order:id,order_no,status,grand_total', 'resolver:id,name'])
                ->orderBy('created_at')
                ->get()
                ->map(fn (OfflineSyncEntry $e) => $this->row($e))
                ->all(),

            /*
            | ที่ตัดสินไปแล้วใน 30 วัน — ไว้ให้ดูย้อนหลังตอนกระทบยอดปลายเดือน
            | ไม่ใช่ตัดออกทันทีที่กด เพราะคำถาม "เดือนที่แล้วมีเงินค้างกี่ก้อน" ต้องตอบได้
            */
            'resolved' => OfflineSyncEntry::where('branch_id', $branch->id)
                ->where('status', OfflineSyncEntry::STATUS_HELD)
                ->whereNotNull('resolved_at')
                ->where('resolved_at', '>=', Carbon::now()->subDays(30))
                ->with(['order:id,order_no', 'resolver:id,name'])
                ->orderByDesc('resolved_at')
                ->limit(50)
                ->get()
                ->map(fn (OfflineSyncEntry $e) => $this->row($e))
                ->all(),

            /*
            | ใบที่ลงสำเร็จแต่มีเรื่องต้องดู เช่น ตัดสต๊อกแล้วติดลบ
            | อยู่หน้าเดียวกันเพราะเป็นคำถามเดียวกัน: "ตอนระบบล่มเกิดอะไรขึ้นบ้าง"
            */
            'warnings' => OfflineSyncEntry::where('branch_id', $branch->id)
                ->whereNotNull('warning')
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->with('order:id,order_no')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (OfflineSyncEntry $e) => $this->row($e))
                ->all(),

            'resolutions' => collect(OfflineSyncEntry::resolutionLabels())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),

            'openTotal' => (float) OfflineSyncEntry::openHolds()
                ->where('branch_id', $branch->id)
                ->sum('amount'),
        ]);
    }

    public function resolve(Request $request, OfflineSyncEntry $entry): RedirectResponse
    {
        // ด่านสาขา — เงินของสาขาอื่นไม่ใช่เรื่องของคนที่กำลังดูหน้านี้
        abort_unless((int) $entry->branch_id === CurrentBranch::id(), 403);

        if ($entry->resolved_at) {
            return back()->with('error', 'รายการนี้ถูกตัดสินไปแล้ว');
        }

        $data = $request->validate([
            'resolution' => ['required', Rule::in(array_keys(OfflineSyncEntry::resolutionLabels()))],
            // บังคับให้เขียนเหตุผล เกณฑ์เดียวกับการเปิดงวดบัญชีกลับ
            // เงินที่หายไปโดยไม่มีคำอธิบายคือเรื่องที่ตรวจย้อนหลังไม่ได้เลย
            'note' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'note.required' => 'ต้องเขียนว่าทำอะไรกับเงินก้อนนี้',
            'note.min' => 'เขียนให้คนอื่นอ่านแล้วเข้าใจด้วย อย่างน้อย 10 ตัวอักษร',
        ]);

        $entry->update([
            'resolution' => $data['resolution'],
            'resolution_note' => $data['note'],
            'resolved_by' => $request->user()->getAuthIdentifier(),
            'resolved_at' => now(),
        ]);

        $this->logger->log('offline.hold_resolved', $entry->order, [
            'uuid' => $entry->uuid,
            'amount' => (float) $entry->amount,
            'resolution' => $data['resolution'],
        ]);

        return back()->with('success', 'บันทึกคำตัดสินแล้ว');
    }

    /** @return array<string, mixed> */
    protected function row(OfflineSyncEntry $entry): array
    {
        $payload = (array) ($entry->payload ?? []);

        return [
            'id' => $entry->id,
            'uuid' => $entry->uuid,
            'kind' => $entry->kind->value,
            'kind_label' => $entry->kind->label(),
            'amount' => (float) $entry->amount,
            'expected_total' => isset($payload['expected_total']) ? (float) $payload['expected_total'] : null,
            'message' => $entry->message,
            'warning' => $entry->warning,
            'order_no' => $entry->order?->order_no,
            'order_id' => $entry->order_id,
            'client_at' => $entry->client_at?->toIso8601String(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'resolution' => $entry->resolution,
            'resolution_label' => $entry->resolution
                ? (OfflineSyncEntry::resolutionLabels()[$entry->resolution] ?? $entry->resolution)
                : null,
            'resolution_note' => $entry->resolution_note,
            'resolved_by' => $entry->resolver?->name,
            'resolved_at' => $entry->resolved_at?->toIso8601String(),
        ];
    }
}
