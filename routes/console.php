<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
