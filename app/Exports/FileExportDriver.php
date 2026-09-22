<?php

namespace App\Exports;

use App\Models\SalesExport;
use Illuminate\Support\Facades\Storage;

/**
 * เขียนข้อมูลการขายลงไฟล์
 *
 * ใช้ได้ทันทีโดยไม่ต้องรอคำตอบจาก SAM และไฟล์ที่ได้เอาไปให้ทีม SAM
 * ดูโครงสร้างได้เลย ซึ่งทำให้คุยเรื่องรูปแบบข้อมูลได้จากของจริงไม่ใช่จากคำอธิบาย
 *
 * ── json กับ csv ต่างกันตรงไหน ─────────────────────────────
 * json  ครบทุกชั้นในไฟล์เดียว รวมรายการในบิลและตัวเลือกของแต่ละรายการ
 * csv   แยกสองไฟล์ — ไฟล์บิล และไฟล์รายการในบิล เพราะตารางแบนใส่ข้อมูลซ้อนชั้นไม่ได้
 *       ผูกกลับหากันด้วยคอลัมน์ bill_key
 */
class FileExportDriver implements SalesExportDriver
{
    public function name(): string
    {
        return 'file';
    }

    public function send(array $payload, SalesExport $export): array
    {
        $config = (array) config('pos.export.file');
        $disk = Storage::disk($config['disk'] ?? 'local');
        $format = ($config['format'] ?? 'json') === 'csv' ? 'csv' : 'json';

        $base = $this->basePath(
            (string) ($config['path'] ?? 'exports/sam'),
            (string) ($config['filename'] ?? 'sales-{branch}-{date}'),
            (string) ($payload['branch']['code'] ?? 'branch'),
            (string) ($payload['business_date'] ?? ''),
        );

        $written = $format === 'csv'
            ? $this->writeCsv($disk, $base, $payload, (string) ($config['encoding'] ?? 'utf-8'))
            : $this->writeJson($disk, $base, $payload);

        return ['reference' => implode(', ', $written), 'format' => $format];
    }

    /**
     * เส้นทางไฟล์ (ยังไม่มีนามสกุล)
     *
     * แยกเป็นเมธอดสาธารณะเพราะเป็นตรรกะล้วน ๆ ที่ทดสอบได้โดยไม่ต้องแตะดิสก์
     * และเป็นจุดที่ผิดแล้วไฟล์ไปโผล่ผิดที่แบบไม่มีใครรู้
     */
    public function basePath(string $dir, string $pattern, string $branchCode, string $date): string
    {
        /*
        | รหัสสาขาที่เป็นภาษาไทยล้วนจะเหลือค่าว่างหลังกรองอักขระ
        | ถ้าปล่อยให้ว่าง ทุกสาขาจะเขียนทับไฟล์ชื่อเดียวกันโดยไม่มีใครรู้
        | จึงใช้แฮชสั้น ๆ ของรหัสแทน — อ่านไม่สวยแต่ไม่ชนกันแน่นอน
        | (ตั้ง branches.code เป็นอักษรอังกฤษจะได้ชื่อไฟล์ที่อ่านออก)
        */
        $name = strtr($pattern, [
            '{branch}' => $this->slug($branchCode) ?: 'b'.substr(md5($branchCode), 0, 8),
            '{date}' => $this->slug($date) ?: 'nodate',
        ]);

        // กันชื่อไฟล์ที่ทำให้หลุดออกนอกโฟลเดอร์ที่ตั้งไว้
        $name = $this->slug($name) ?: 'sales';

        return trim(str_replace('\\', '/', $dir), '/').'/'.$name;
    }

    /** เหลือเฉพาะตัวอักษร ตัวเลข ขีด และจุด — ตัด ../ กับช่องว่างทิ้งทั้งหมด */
    protected function slug(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';

        return trim($value, '-.');
    }

    /** @return array<int, string> */
    protected function writeJson($disk, string $base, array $payload): array
    {
        $path = $base.'.json';

        $disk->put($path, json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return [$path];
    }

    /** @return array<int, string> */
    protected function writeCsv($disk, string $base, array $payload, string $encoding): array
    {
        $bills = $payload['bills'] ?? [];
        $written = [];

        $billRows = [];
        $lineRows = [];

        foreach ($bills as $bill) {
            $billRows[] = [
                $bill['key'],
                $bill['business_date'],
                $bill['order_no'],
                $bill['receipt_no'],
                $bill['type'],
                $bill['channel'],
                $bill['source'],
                $bill['status'],
                $bill['counts_as_sale'] ? '1' : '0',
                $this->num($bill['amounts']['subtotal']),
                $this->num($bill['amounts']['discount_total']),
                $this->num($bill['amounts']['service_charge']),
                $this->num($bill['amounts']['delivery_fee']),
                $this->num($bill['amounts']['net_amount']),
                $this->num($bill['amounts']['tax_amount']),
                $this->num($bill['amounts']['rounding']),
                $this->num($bill['amounts']['grand_total']),
                implode('|', array_map(
                    fn (array $p) => $p['method'].':'.$this->num($p['amount']),
                    $bill['payments'],
                )),
                $bill['closed_at'],
            ];

            foreach ($bill['lines'] as $line) {
                $lineRows[] = [
                    $bill['key'],
                    $line['no'],
                    $line['sku'],
                    $line['name'],
                    $line['category'],
                    $this->num($line['qty'], 3),
                    $this->num($line['unit_price']),
                    $this->num($line['modifier_total']),
                    $this->num($line['discount']),
                    $this->num($line['line_total']),
                    implode('|', array_column($line['modifiers'], 'name')),
                ];
            }
        }

        $written[] = $this->putCsv($disk, $base.'.csv', [
            'bill_key', 'business_date', 'order_no', 'receipt_no', 'type', 'channel', 'source',
            'status', 'counts_as_sale', 'subtotal', 'discount_total', 'service_charge',
            'delivery_fee', 'net_amount', 'tax_amount', 'rounding', 'grand_total',
            'payments', 'closed_at',
        ], $billRows, $encoding);

        if ($lineRows) {
            $written[] = $this->putCsv($disk, $base.'-lines.csv', [
                'bill_key', 'line_no', 'sku', 'product_name', 'category',
                'qty', 'unit_price', 'modifier_total', 'discount', 'line_total', 'modifiers',
            ], $lineRows, $encoding);
        }

        return $written;
    }

    protected function putCsv($disk, string $path, array $header, array $rows, string $encoding): string
    {
        $out = fopen('php://temp', 'r+');

        fputcsv($out, $header);

        foreach ($rows as $row) {
            fputcsv($out, $row);
        }

        rewind($out);
        $body = (string) stream_get_contents($out);
        fclose($out);

        $disk->put($path, $this->encode($body, $encoding));

        return $path;
    }

    /**
     * แปลงอักขระตามที่ปลายทางรับได้
     *
     * utf-8 ใส่ BOM นำหน้า ไม่งั้น Excel บนวินโดวส์เดาว่าไฟล์เป็น TIS-620
     * แล้วภาษาไทยกลายเป็นตัวขยะทั้งไฟล์ — เป็นปัญหาที่เจอทุกครั้งที่ลืม
     *
     * tis-620 ใช้ //IGNORE เพื่อไม่ให้ทั้งไฟล์พังเพราะตัวอักษรตัวเดียวที่แปลงไม่ได้
     * (อีโมจิในชื่อเมนู เป็นต้น) ยอมเสียตัวนั้นดีกว่าเสียทั้งไฟล์
     */
    protected function encode(string $body, string $encoding): string
    {
        if (strtolower($encoding) === 'tis-620') {
            return (string) iconv('UTF-8', 'TIS-620//IGNORE', $body);
        }

        return "\xEF\xBB\xBF".$body;
    }

    protected function num(mixed $value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }
}
