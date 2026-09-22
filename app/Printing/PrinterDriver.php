<?php

namespace App\Printing;

use App\Models\Printer;

/**
 * สัญญาที่ไดรเวอร์เครื่องพิมพ์ทุกเจ้าต้องทำตาม
 *
 * ตอนนี้มีเจ้าเดียว (ESC/POS ผ่าน LAN) แต่แยกชั้นไว้ตั้งแต่แรก
 * เพราะวันที่ย้ายขึ้นคลาวด์ทิศทางการเชื่อมต่อจะกลับด้าน — เซิร์ฟเวอร์
 * จะเข้าหาเครื่องพิมพ์ไม่ได้ ต้องให้เครื่องพิมพ์วิ่งมาถามเองแทน
 * ถ้าเขียนแบบเปิด socket ตรง ๆ กระจายทั่วโค้ด วันนั้นต้องรื้อทั้งระบบ
 */
abstract class PrinterDriver
{
    /**
     * ส่งไบต์ไปที่เครื่องพิมพ์
     *
     * @throws PrinterException เมื่อส่งไม่สำเร็จ — ผู้เรียกจะเก็บงานไว้ในคิวแล้วลองใหม่
     */
    abstract public function send(Printer $printer, string $bytes): void;

    /** ตั้งค่าครบพอที่จะใช้งานได้หรือยัง */
    abstract public function isConfigured(Printer $printer): bool;

    /** ช่องที่ต้องกรอกในหน้าตั้งค่า — หน้าเว็บสร้างฟอร์มจากตัวนี้ */
    abstract public function fields(): array;
}
