<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;

/**
 * ชีพจรของเซิร์ฟเวอร์ สำหรับหน้าจอ POS ใช้เช็คว่ายังคุยกันได้อยู่ไหม
 *
 * ── ทำไมไม่ใช้ /up ของ Laravel ────────────────────────────
 * /up ไม่ผ่าน session จึงตอบ 200 แม้ตอนที่พนักงานหลุดล็อกอินไปแล้ว
 * ซึ่งเป็นอาการที่เจอจริงบ่อยกว่าเน็ตหลุดเสียอีก (แท็บเล็ตเปิดค้างข้ามคืน)
 * ตัวนี้อยู่ในกลุ่มที่ต้องล็อกอิน หน้าจอจึงแยกสองอาการนี้ออกจากกันได้
 *
 * ตอบสั้นที่สุดเท่าที่จะสั้นได้ เพราะถูกเรียกทุก 20 วินาทีตลอดวันทำการ
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json([
                'ok' => true,
                'at' => now()->toIso8601String(),
                'branch_id' => CurrentBranch::id(),
            ])
            ->header('Cache-Control', 'no-store');
    }
}
