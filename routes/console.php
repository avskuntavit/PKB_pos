<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| ตารางงานอัตโนมัติ
|--------------------------------------------------------------------------
|
| ต้องมีตัวรันตารางนี้ค้างไว้ถึงจะทำงาน — ใน Docker คือ program:schedule
| ใน docker/supervisord.conf ถ้ารันนอก Docker ให้ตั้ง cron ของเครื่อง:
|
|     * * * * * cd /path/to/foodpos && php artisan schedule:run >> /dev/null 2>&1
|
| เช็คว่าตารางถูกต้องด้วย:  php artisan schedule:list
|
*/

/*
| งานพิมพ์ที่ค้างคิว
|
| กรณีปกติกระดาษออกตั้งแต่ตอนกดส่งครัวแล้ว ตัวนี้มีไว้เก็บกวาดใบที่พลาดไป
| เพราะตอนนั้นเครื่องพิมพ์ไม่พร้อม — กระดาษหมด ฝาเปิด สายหลุด ไฟดับ
|
| ทุกนาทีเพราะครัวรอไม่ได้ · withoutOverlapping กันรอบก่อนยังไม่จบแล้วรอบใหม่มาซ้อน
| · runInBackground เพื่อให้เครื่องพิมพ์ที่ค้างไม่ไปหน่วงงานอื่นในตาราง
*/
Schedule::command('printers:work')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
| ส่งข้อมูลการขายให้ระบบบัญชี (SAM)
|
| คำสั่งนี้ส่งของ "วันขายเมื่อวาน" เป็นค่าตั้งต้น จึงต้องรันหลังร้านปิดและปิดรอบแล้ว
| 06:00 อยู่หลัง business_day_start (05:00) ของสาขา แปลว่าวันเก่าปิดเรียบร้อยแน่นอน
|
| ถ้าวันไหนส่งไม่สำเร็จ จะไม่ลองใหม่เอง แต่ขึ้นค้างให้เห็นที่ /backoffice/sales-export
| ให้คนกดส่งซ้ำ เพราะสาเหตุที่ส่งไม่ผ่านมักต้องมีคนไปแก้ ไม่ใช่รอเฉย ๆ แล้วหาย
*/
Schedule::command('sales:export')
    ->dailyAt('06:00')
    ->withoutOverlapping();

/*
| สำรองข้อมูล
|
| ตีสามครึ่งเป็นค่าตั้งต้น — หลังร้านปิดแน่นอน และก่อน 05:00 ที่เป็นจุดตัดวันขาย
| จึงได้ภาพของ "วันที่ปิดไปแล้ว" เต็มวัน ไม่ใช่วันที่กำลังขายอยู่ครึ่ง ๆ กลาง ๆ
|
| ไม่ใส่ runInBackground โดยตั้งใจ — งานสำรองไม่ควรทับกับงานอื่น
| และเราอยากให้ล็อกของ withoutOverlapping ครอบทั้งช่วงที่มันทำงานจริง
*/
Schedule::command('backup:run')
    ->dailyAt((string) config('monitoring.backup.time', '03:30'))
    ->withoutOverlapping(120);

/*
| ไล่ถามเกตเวย์ว่า QR รับเงินที่ค้างอยู่มีเงินเข้าหรือยัง
|
| ── ทำไมไล่ถาม ไม่รอ webhook ────────────────────────────────────────────
| เซิร์ฟเวอร์อยู่บนเครื่องในร้าน เกตเวย์ยิงเข้ามาไม่ได้ถ้าไม่เปิดทางจากอินเทอร์เน็ต
| และระบบนี้ไม่มี queue worker เดินอยู่ ตัวตั้งเวลาคือเครื่องมือที่มีจริง
|
| ทุกนาทีเพราะลูกค้ายืนรออยู่หน้าเคาน์เตอร์ · withoutOverlapping กันรอบซ้อน
| · runInBackground เพื่อให้เกตเวย์ที่ตอบช้าไม่ไปหน่วงงานอื่นในตาราง
|
| ถ้ายังใช้ QR พร้อมเพย์ของร้าน (driver static) คำสั่งนี้จะไม่มีอะไรให้ถาม
| เพราะใบของ static ไม่มี provider_charge_id — ปล่อยให้เดินไว้ได้ ไม่เสียอะไร
*/
Schedule::command('pos:poll-charges')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
| สัญญาณชีพของตัวตั้งเวลา
|
| ตัวตั้งเวลาตายแล้วหน้าเว็บยังใช้งานได้ทุกอย่าง จึงไม่มีใครรู้ว่ามันตาย
| แต่ของที่หยุดตามคือ งานพิมพ์ที่ค้าง การส่งข้อมูลบัญชี และการสำรองข้อมูล
|
| ให้มันเคาะเวลาทิ้งไว้ แล้วหน้า /backoffice/health ดูว่าเคาะล่าสุดเมื่อไหร่
| ถ้าเงียบเกิน SCHEDULER_STALE_MINUTES จะขึ้นเตือนทันที
| ตัวนี้ต้องเบาที่สุดเพราะเดินทุก 5 นาทีตลอดเวลา — เขียนค่าเดียวลงแคชเท่านั้น
*/
Schedule::call(function () {
    Cache::forever((string) config('monitoring.scheduler.ping_key'), now()->toIso8601String());
})->everyFiveMinutes()->name('scheduler-heartbeat');
