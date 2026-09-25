<?php

namespace App\Console\Commands;

use App\Services\PaymentChargeService;
use Illuminate\Console\Command;

/**
 * ไล่ถามเกตเวย์ว่า QR ที่ออกไปมีเงินเข้าหรือยัง
 *
 * ── ทำไมไล่ถาม ไม่รอ webhook ────────────────────────────────────────────
 * เซิร์ฟเวอร์อยู่บนเครื่องในร้าน เกตเวย์ยิงเข้ามาไม่ได้ถ้าไม่เปิดทางจากอินเทอร์เน็ต
 * และระบบนี้ไม่มี queue worker เดินอยู่ งานที่โยนเข้าคิวจะนอนอยู่ตลอดไป
 * `schedule:work` ที่เดินอยู่แล้วจึงเป็นเครื่องมือที่มีจริง
 *
 * ── ทำไมไม่ลองซ้ำเองเมื่อถามไม่ได้ ───────────────────────────────────────
 * รอบถัดไปมาในอีกหนึ่งนาทีอยู่แล้ว การวนลองซ้ำในรอบเดียวทำให้รอบนั้นยาว
 * แล้วไปทับรอบถัดไป (ซึ่ง withoutOverlapping จะข้ามให้ = ถามช้าลงกว่าเดิม)
 */
class PollPaymentCharges extends Command
{
    protected $signature = 'pos:poll-charges
                            {--limit=50 : ถามไม่เกินกี่ใบในรอบนี้}';

    protected $description = 'ไล่ถามเกตเวย์ว่า QR รับเงินที่ค้างอยู่มีเงินเข้าหรือยัง';

    public function handle(PaymentChargeService $charges): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $stat = $charges->pollOpen($limit);

        if ($stat['polled'] + $stat['expired'] === 0) {
            $this->info('ไม่มี QR ค้างที่ต้องถาม');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'ถาม %d ใบ · ลงบิลแล้ว %d · รอตัดสิน %d · ล้มเหลว %d · หมดอายุ %d · ยังไม่ถึงคิวถาม %d',
            $stat['polled'],
            $stat['settled'],
            $stat['needs_decision'],
            $stat['failed'],
            $stat['expired'],
            $stat['skipped'],
        ));

        /*
        | เงินที่เข้ามาแล้วแต่ลงบิลไม่ได้ ต้องเห็นตั้งแต่ใน log ของตัวตั้งเวลา
        | ไม่ใช่รอให้คนไปเปิดหน้าจอเจอเอง — เงินที่ไม่มีใครรู้ว่ามีคือเงินที่หาย
        */
        if ($stat['needs_decision'] > 0) {
            $this->warn('มี '.$stat['needs_decision'].' ใบที่เงินเข้าแล้วแต่ลงบิลไม่ได้ — ต้องมีคนตัดสิน');
        }

        return self::SUCCESS;
    }
}
