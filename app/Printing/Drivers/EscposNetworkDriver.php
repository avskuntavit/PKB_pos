<?php

namespace App\Printing\Drivers;

use App\Models\Printer;
use App\Printing\PrinterDriver;
use App\Printing\PrinterException;

/**
 * เครื่องพิมพ์ความร้อนที่ต่อสาย LAN หรือ Wi-Fi ในวงเดียวกับเซิร์ฟเวอร์
 *
 * เกือบทุกยี่ห้อ (Epson, Star, Xprinter, Rongta) เปิดพอร์ต 9100 รอ raw socket
 * ส่งไบต์ ESC/POS เข้าไปตรง ๆ แล้วกระดาษออก ไม่ต้องมีไดรเวอร์ของวินโดวส์
 * ไม่ต้องลงโปรแกรมตัวกลาง
 *
 * ── ทำไมต้องตั้ง timeout สั้น ────────────────────────────────
 * เครื่องพิมพ์ที่ถูกถอดปลั๊กจะไม่ตอบอะไรเลย ถ้าไม่ตั้ง timeout
 * คำขอที่พนักงานกด "ส่งครัว" จะค้างจนหน้าจอหมดเวลา ทั้งที่ควรจะ
 * บันทึกบิลสำเร็จแล้วค่อยเก็บงานพิมพ์ไว้ลองใหม่เงียบ ๆ ข้างหลัง
 */
class EscposNetworkDriver extends PrinterDriver
{
    /** วินาที — สั้นพอที่พนักงานไม่รู้สึกว่าจอค้าง */
    public const CONNECT_TIMEOUT = 3;

    public const WRITE_TIMEOUT = 5;

    public function send(Printer $printer, string $bytes): void
    {
        if (! $this->isConfigured($printer)) {
            throw new PrinterException('ยังไม่ได้ตั้งค่าหมายเลขไอพีของเครื่องพิมพ์');
        }

        $target = 'tcp://'.$printer->host.':'.$printer->port;
        $socket = @stream_socket_client($target, $errno, $error, self::CONNECT_TIMEOUT);

        if ($socket === false) {
            throw new PrinterException("ต่อเครื่องพิมพ์ที่ {$printer->host}:{$printer->port} ไม่ได้ — ".($error ?: "รหัส {$errno}"));
        }

        try {
            stream_set_timeout($socket, self::WRITE_TIMEOUT);

            $total = strlen($bytes);
            $sent = 0;

            // fwrite ส่งไม่ครบในครั้งเดียวได้เมื่อบัฟเฟอร์เต็ม ต้องวนจนหมด
            // ไม่งั้นใบยาว ๆ จะถูกตัดกลางคันแล้วกระดาษออกมาไม่ครบ
            while ($sent < $total) {
                $written = @fwrite($socket, substr($bytes, $sent));

                if ($written === false || $written === 0) {
                    $meta = stream_get_meta_data($socket);

                    throw new PrinterException($meta['timed_out'] ?? false
                        ? 'เครื่องพิมพ์ไม่ตอบสนอง (อาจกระดาษหมดหรือฝาเปิดอยู่)'
                        : 'ส่งข้อมูลไปเครื่องพิมพ์ไม่สำเร็จ');
                }

                $sent += $written;
            }

            fflush($socket);
        } finally {
            fclose($socket);
        }
    }

    public function isConfigured(Printer $printer): bool
    {
        return filled($printer->host) && $printer->port > 0;
    }

    public function fields(): array
    {
        return [
            ['key' => 'host', 'label' => 'หมายเลขไอพีของเครื่องพิมพ์', 'placeholder' => '192.168.1.50', 'required' => true],
            ['key' => 'port', 'label' => 'พอร์ต', 'placeholder' => '9100', 'required' => true],
        ];
    }
}
