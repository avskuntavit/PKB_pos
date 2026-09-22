<?php

namespace App\Exports;

use App\Models\SalesExport;

/**
 * ช่องทางส่งข้อมูลการขายออกไปข้างนอก
 *
 * ── ทำไมต้องเป็น interface ทั้งที่มี driver เดียว ───────────
 * เพราะยังไม่รู้ว่า SAM รับทางไหน (REST API · ไฟล์วางโฟลเดอร์/FTP · เขียนฐานข้อมูลตรง)
 * ถ้าเขียนการเขียนไฟล์ปนลงไปในตัวประกอบข้อมูล พอรู้คำตอบจริงจะต้องรื้อทั้งก้อน
 * แยกไว้แบบนี้ การเพิ่มช่องทางใหม่คือเขียนคลาสใหม่คลาสเดียว ไม่แตะของเดิม
 */
interface SalesExportDriver
{
    /** ชื่อที่บันทึกลง sales_exports.driver */
    public function name(): string;

    /**
     * ส่งข้อมูลออกไป — โยน exception เมื่อส่งไม่สำเร็จ
     *
     * @param  array<string, mixed>  $payload
     * @return array{reference: ?string, format: ?string}
     */
    public function send(array $payload, SalesExport $export): array;
}
