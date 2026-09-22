<?php

namespace App\Console\Commands;

use App\Enums\PrintGroup;
use App\Models\Branch;
use App\Models\Printer;
use App\Services\PrintService;
use Illuminate\Console\Command;

/**
 * ตั้งค่าเครื่องพิมพ์จากบรรทัดคำสั่ง
 *
 * มีไว้ให้ตั้งเครื่องแรกได้ทันทีที่แกะกล่อง โดยไม่ต้องรอหน้าจอในหลังบ้าน
 * และใช้ตรวจสอบเวลามีปัญหาได้เร็วกว่าเปิดเบราว์เซอร์
 */
class ManagePrinters extends Command
{
    protected $signature = 'printers:add
        {--branch= : รหัสสาขา (ไม่ระบุ = สาขาแรก)}
        {--name= : ชื่อเครื่อง เช่น เครื่องครัว}
        {--host= : หมายเลขไอพีของเครื่องพิมพ์}
        {--port=9100 : พอร์ต}
        {--columns=48 : ตัวอักษรต่อบรรทัด (กระดาษ 80มม. = 48, 58มม. = 32)}
        {--groups= : จุดผลิตที่เครื่องนี้รับ คั่นด้วยจุลภาค เช่น 1,2}
        {--receipt : ออกใบเสร็จที่เครื่องนี้}
        {--drawer : ลิ้นชักเก็บเงินต่ออยู่ที่เครื่องนี้}
        {--test : พิมพ์ใบทดสอบทันทีหลังเพิ่ม}';

    protected $description = 'เพิ่มเครื่องพิมพ์ให้สาขา แล้วพิมพ์ใบทดสอบ';

    public function handle(PrintService $printing): int
    {
        $branch = $this->option('branch')
            ? Branch::find((int) $this->option('branch'))
            : Branch::orderBy('id')->first();

        if (! $branch) {
            $this->error('ไม่พบสาขา — รัน migrate:fresh --seed ก่อน');

            return self::FAILURE;
        }

        $host = $this->option('host') ?: $this->ask('หมายเลขไอพีของเครื่องพิมพ์ (ดูได้จากใบ self-test ที่เครื่องพิมพ์ออกมาตอนกดปุ่ม FEED ค้างแล้วเปิดเครื่อง)');

        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            $this->error('หมายเลขไอพีไม่ถูกต้อง: '.$host);

            return self::FAILURE;
        }

        $groups = collect(explode(',', (string) $this->option('groups')))
            ->map(fn ($g) => (int) trim($g))
            ->filter(fn ($g) => PrintGroup::tryFrom($g) !== null)
            ->values()
            ->all();

        $printer = Printer::create([
            'branch_id' => $branch->id,
            'name' => $this->option('name') ?: $this->ask('ชื่อเครื่อง', 'เครื่องพิมพ์'),
            'driver' => 'escpos_network',
            'host' => $host,
            'port' => (int) $this->option('port'),
            'columns' => (int) $this->option('columns'),
            'print_groups' => $groups,
            'prints_receipt' => (bool) $this->option('receipt'),
            'opens_cash_drawer' => (bool) $this->option('drawer'),
        ]);

        $this->info("เพิ่มเครื่องพิมพ์ #{$printer->id} {$printer->name} ที่ {$printer->host}:{$printer->port} แล้ว");

        $labels = array_map(fn (int $g) => PrintGroup::from($g)->label(), $groups);
        $this->line('  รับใบสั่งครัว: '.($labels ? implode(', ', $labels) : 'ไม่รับ'));
        $this->line('  ออกใบเสร็จ: '.($printer->prints_receipt ? 'ใช่' : 'ไม่'));
        $this->line('  ลิ้นชัก: '.($printer->opens_cash_drawer ? 'ต่ออยู่' : 'ไม่มี'));

        if ($this->option('test') || $this->confirm('พิมพ์ใบทดสอบเลยไหม', true)) {
            $job = $printing->queueTest($printer);

            $job->status === 'done'
                ? $this->info('กระดาษออกแล้ว — ถ้าอ่านภาษาไทยได้ครบแปลว่าใช้งานได้')
                : $this->error('พิมพ์ไม่สำเร็จ: '.$job->last_error);
        }

        return self::SUCCESS;
    }
}
