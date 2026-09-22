<?php

namespace App\Printing;

/** ส่งงานพิมพ์ไม่สำเร็จ — ข้อความในนี้ถูกเก็บลง print_jobs.last_error ให้คนอ่าน */
class PrinterException extends \RuntimeException {}
