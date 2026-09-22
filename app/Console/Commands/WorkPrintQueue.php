<?php

namespace App\Console\Commands;

use App\Services\PrintService;
use Illuminate\Console\Command;

/**
 * ตัวลองพิมพ์ซ้ำสำหรับงานที่ค้างคิว
 *
 * กรณีปกติกระดาษออกตั้งแต่ตอนกดส่งครัวแล้ว คำสั่งนี้มีไว้เก็บกวาดงาน
 * ที่ตอนนั้นเครื่องพิมพ์ไม่พร้อม — กระดาษหมด ฝาเปิด สายหลุด ไฟดับ
 *
 * รันค้างไว้ด้วย --watch บนเครื่องที่ร้าน หรือให้ตัวตั้งเวลาเรียกทุกนาที
 * ถ้าไม่รันเลยก็ยังขายได้ แค่ใบที่พลาดไปจะไม่ออกเองจนกว่าจะมีคนกดพิมพ์ซ้ำ
 */
class WorkPrintQueue extends Command
{
    protected $signature = 'printers:work
        {--branch= : เฉพาะสาขาที่ระบุ}
        {--watch : วนรอตลอด ไม่จบการทำงาน}
        {--sleep=5 : วินาทีที่พักระหว่างรอบ ใช้กับ --watch}';

    protected $description = 'ส่งงานพิมพ์ที่ค้างอยู่ในคิวไปยังเครื่องพิมพ์';

    public function handle(PrintService $printing): int
    {
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;

        if (! $this->option('watch')) {
            $this->report($printing->work($branchId));

            return self::SUCCESS;
        }

        $this->info('กำลังเฝ้าคิวงานพิมพ์ — กด Ctrl+C เพื่อหยุด');

        while (true) {
            $result = $printing->work($branchId);

            if ($result['done'] || $result['failed']) {
                $this->report($result);
            }

            sleep(max(1, (int) $this->option('sleep')));
        }
    }

    protected function report(array $result): void
    {
        $this->line(now()->format('H:i:s').'  พิมพ์สำเร็จ '.$result['done'].'  ล้มเหลว '.$result['failed']);
    }
}
