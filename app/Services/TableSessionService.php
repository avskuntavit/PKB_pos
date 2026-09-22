<?php

namespace App\Services;

use App\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;

/**
 * รอบการนั่งโต๊ะที่ผูกกับ QR
 *
 * QR บนโต๊ะเป็น token ถาวร ไม่ต้องพิมพ์ใหม่ทุกวัน
 * ความปลอดภัยอยู่ที่ session: ปิดบิลแล้ว session ปิดตาม ลูกค้าโต๊ะเดิมสั่งต่อไม่ได้
 * และคนที่เก็บลิงก์เก่าไว้ก็ใช้ไม่ได้ ต้องสแกนใหม่เพื่อเปิด session ใหม่
 */
class TableSessionService
{
    /** กี่ชั่วโมงถึงถือว่า session ถูกทิ้งร้าง */
    public const IDLE_HOURS = 6;

    public function __construct(protected ActivityLogger $logger) {}

    /** หาโต๊ะจาก token ใน QR */
    public function findTableByQrToken(string $qrToken): ?DiningTable
    {
        return DiningTable::with('branch', 'zone')
            ->where('qr_token', $qrToken)
            ->where('is_active', true)
            ->first();
    }

    /**
     * โต๊ะนี้เปิดให้ลูกค้าสั่งผ่าน QR ได้หรือยัง
     *
     * ── ปัญหาที่แก้ ─────────────────────────────────────────────
     * QR ติดโต๊ะเป็นสติกเกอร์ถาวร ใครถ่ายรูปกลับบ้านก็สแกนได้ตลอด
     * ห้ามไม่ให้สแกนไม่ได้ ที่ทำได้คือ "สแกนได้ แต่สั่งไม่ได้"
     *
     * ตัวชี้ขาดคือสถานะโต๊ะ เพราะคนที่ไม่ได้อยู่ในร้านทำให้โต๊ะเปลี่ยนเป็น
     * "มีลูกค้า" ไม่ได้ — ต้องมีพนักงานเปิดบิลให้โต๊ะนั้นก่อน (OrderService::open)
     * และพอปิดบิล สถานะกลับเป็น "ว่าง" เอง ลิงก์เก่าจึงตายไปพร้อมกัน
     * ไม่ต้องพิมพ์ QR ใหม่ทุกวัน
     */
    public function isOpenForGuests(DiningTable $table): bool
    {
        // ร้านที่ให้ลูกค้าสแกนสั่งเองโดยไม่ต้องรอพนักงาน ปิดด่านนี้ได้
        if ($table->branch && ! $table->branch->qr_requires_open_table) {
            return true;
        }

        if (in_array($table->status, [TableStatus::Occupied, TableStatus::Reserved], true)) {
            return true;
        }

        // สถานะเพี้ยนแต่มีบิลเปิดค้างอยู่จริง ให้ผ่าน
        // ไม่งั้นลูกค้าที่นั่งอยู่จะสั่งรอบสองไม่ได้เพราะสถานะโต๊ะไปคนละทาง
        return $table->openOrder()->exists();
    }

    /**
     * สแกน QR — คืน session ที่ใช้อยู่ หรือเปิดใหม่ถ้าโต๊ะว่าง
     *
     * ถ้าพนักงานเปิดบิลให้โต๊ะนี้ไว้แล้ว session จะเกาะบิลนั้นเลย
     * ลูกค้าจะเห็นรายการที่พนักงานสั่งให้ก่อนหน้าด้วย
     */
    public function resolve(DiningTable $table, ?string $ipAddress = null): TableSession
    {
        return DB::transaction(function () use ($table, $ipAddress) {
            $this->expireStaleSessions($table);

            $session = TableSession::where('dining_table_id', $table->id)
                ->where('status', 'active')
                ->latest('started_at')
                ->first();

            if ($session) {
                // บิลถูกปิดไปแล้วแต่ session ยังค้าง — ปิดทิ้งแล้วเปิดใหม่ให้ลูกค้ากลุ่มถัดไป
                if ($session->order_id && ! $session->order?->isOpen()) {
                    $this->close($session, 'paid');
                } else {
                    $session->touchActivity();

                    return $session;
                }
            }

            $openOrder = Order::where('dining_table_id', $table->id)->open()->latest('opened_at')->first();

            $session = TableSession::create([
                'branch_id' => $table->branch_id,
                'dining_table_id' => $table->id,
                'order_id' => $openOrder?->id,
                'guest_count' => $openOrder?->guest_count ?? 1,
                'status' => 'active',
                'started_at' => now(),
                'last_activity_at' => now(),
                'ip_address' => $ipAddress,
            ]);

            $this->logger->log('self_order.session_start', $session, [
                'table' => $table->name,
            ], $table->branch);

            return $session;
        });
    }

    /** ตรวจ token ที่ลูกค้าถืออยู่ว่ายังใช้ได้ */
    public function validateToken(?string $sessionToken): ?TableSession
    {
        if (blank($sessionToken)) {
            return null;
        }

        $session = TableSession::with(['diningTable', 'order'])
            ->where('session_token', $sessionToken)
            ->where('status', 'active')
            ->first();

        if (! $session) {
            return null;
        }

        // บิลปิดไปแล้ว = จบรอบ ลูกค้าต้องสแกนใหม่
        if ($session->order_id && ! $session->order?->isOpen()) {
            $this->close($session, 'paid');

            return null;
        }

        return $session;
    }

    public function close(TableSession $session, string $reason = 'paid'): TableSession
    {
        $session->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_reason' => $reason,
        ]);

        return $session;
    }

    /** ปิด session ทั้งหมดของบิลที่เพิ่งชำระเงิน */
    public function closeForOrder(Order $order, string $reason = 'paid'): void
    {
        TableSession::where('order_id', $order->id)
            ->where('status', 'active')
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_reason' => $reason,
            ]);
    }

    /** session ที่ไม่มีความเคลื่อนไหวนานเกินไปและไม่มีบิลผูกอยู่ ถือว่าถูกทิ้ง */
    protected function expireStaleSessions(DiningTable $table): void
    {
        TableSession::where('dining_table_id', $table->id)
            ->where('status', 'active')
            ->whereNull('order_id')
            ->where('last_activity_at', '<', now()->subHours(self::IDLE_HOURS))
            ->update([
                'status' => 'expired',
                'closed_at' => now(),
                'closed_reason' => 'expired',
            ]);
    }
}
