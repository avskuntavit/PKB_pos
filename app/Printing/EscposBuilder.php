<?php

namespace App\Printing;

/**
 * สร้างไบต์ ESC/POS ทีละคำสั่ง
 *
 * ── เรื่องภาษาไทย ซึ่งเป็นจุดที่พังบ่อยที่สุด ─────────────────────
 * ESC/POS ส่งได้ทีละไบต์ ไม่ใช่ UTF-8 จึงต้องแปลงข้อความเป็น TIS-620 ก่อน
 * (1 อักษรไทย = 1 ไบต์) แล้วสั่งเครื่องพิมพ์สลับไปใช้ code page 255 ซึ่งเป็น
 * ตารางอักษรไทยมาตรฐานของเครื่องพิมพ์ที่ขายในไทย
 *
 * ถ้าไม่สั่งสลับ code page เครื่องจะอ่านไบต์ 0xA1 ว่าเป็นอักษรละตินตัวอื่น
 * แล้วพ่นตัวประหลาดออกมาทั้งใบ — อาการ "พิมพ์ไทยไม่ได้" ที่เจอกันบ่อย
 *
 * ── ทำไมนับความกว้างด้วย mb_strwidth ไม่ได้ ──────────────────────
 * สระบนล่างและวรรณยุกต์ไทยไม่กินความกว้างบนกระดาษ (ลอยอยู่บน/ล่างพยัญชนะ)
 * แต่ mb_strlen นับเป็นตัวอักษร ถ้าใช้ตรง ๆ คอลัมน์ราคาจะเลื่อนไม่ตรง
 * จึงนับความกว้างจริงด้วย printableWidth() ที่ข้ามอักขระลอยเหล่านั้น
 */
class EscposBuilder
{
    /* คำสั่งดิบ — อ้างอิงสเปก ESC/POS */
    protected const ESC = "\x1b";

    protected const GS = "\x1d";

    /** อักขระไทยที่ลอยอยู่บน/ล่างพยัญชนะ ไม่กินความกว้างหนึ่งช่อง */
    protected const THAI_COMBINING = [
        'ั', 'ิ', 'ี', 'ึ', 'ื', 'ุ', 'ู', 'ฺ',
        '่', '้', '๊', '๋', '็', '์', 'ํ', '๎',
    ];
    // ไม่รวม ๆ กับ ฯ — สองตัวนี้วางบนบรรทัดปกติ กินความกว้างเต็มช่อง

    protected string $buffer = '';

    public function __construct(protected int $columns = 48) {}

    public static function make(int $columns = 48): self
    {
        return new self($columns);
    }

    /** เริ่มงานใหม่ — รีเซ็ตเครื่องแล้วสลับไปโหมดอักษรไทย */
    public function begin(): self
    {
        $this->raw(self::ESC.'@');            // ESC @  คืนค่าเริ่มต้น
        $this->raw(self::ESC.'t'.chr(255));   // ESC t 255  ใช้ code page ไทย (TIS-620)

        return $this;
    }

    public function raw(string $bytes): self
    {
        $this->buffer .= $bytes;

        return $this;
    }

    /** ข้อความหนึ่งบรรทัด — แปลงเป็น TIS-620 ให้เอง */
    public function line(string $text = ''): self
    {
        return $this->raw($this->encode($text)."\n");
    }

    public function feed(int $lines = 1): self
    {
        return $this->raw(str_repeat("\n", max(1, $lines)));
    }

    /* ---------- จัดรูปแบบ ---------- */

    /** 0 = ชิดซ้าย, 1 = กลาง, 2 = ชิดขวา */
    public function align(int $mode): self
    {
        return $this->raw(self::ESC.'a'.chr(max(0, min(2, $mode))));
    }

    public function bold(bool $on = true): self
    {
        return $this->raw(self::ESC.'E'.chr($on ? 1 : 0));
    }

    /**
     * ขยายตัวอักษร — 1 คือขนาดปกติ สูงสุด 8 เท่า
     *
     * GS ! รับไบต์เดียว: 4 บิตบนคือความกว้าง 4 บิตล่างคือความสูง
     */
    public function size(int $width = 1, int $height = 1): self
    {
        $w = max(1, min(8, $width)) - 1;
        $h = max(1, min(8, $height)) - 1;

        return $this->raw(self::GS.'!'.chr(($w << 4) | $h));
    }

    public function normal(): self
    {
        return $this->size(1, 1)->bold(false)->align(0);
    }

    /* ---------- ชิ้นส่วนที่ใช้บ่อย ---------- */

    public function rule(string $char = '-'): self
    {
        return $this->line(str_repeat($char, $this->columns));
    }

    /**
     * ซ้าย-ขวาในบรรทัดเดียว เช่น ชื่อเมนู ... ราคา
     *
     * ยาวเกินก็ตัดฝั่งซ้ายทิ้ง ไม่ปล่อยให้ดันราคาตกบรรทัด
     * ราคาคือสิ่งที่ต้องอ่านออกเสมอ ชื่อเมนูขาดหายพออนุมานได้
     */
    /**
     * @param  int|null  $width  ความกว้างเฉพาะบรรทัดนี้ — ต้องส่งเมื่อพิมพ์ตัวขยาย
     *                           ตัวอักษรขนาด 2 เท่ากินสองช่อง บรรทัดจึงใส่ได้แค่ครึ่งเดียว
     *                           ถ้าไม่ส่งแล้วพิมพ์ตัวใหญ่ ราคาจะทะลุขอบกระดาษหายไป
     */
    public function columns(string $left, string $right, int $indent = 0, ?int $width = null): self
    {
        $width ??= $this->columns;
        $right = trim($right);
        $rightWidth = $this->printableWidth($right);
        $room = $width - $rightWidth - $indent;

        if ($room < 1) {
            return $this->line($right);
        }

        $left = $this->truncate(trim($left), $room - 1);
        $gap = max(1, $room - $this->printableWidth($left));

        return $this->line(str_repeat(' ', $indent).$left.str_repeat(' ', $gap).$right);
    }

    /** ตัดข้อความให้พอดีความกว้าง โดยนับแบบไทย */
    public function truncate(string $text, int $width): string
    {
        if ($this->printableWidth($text) <= $width) {
            return $text;
        }

        $out = '';

        foreach (mb_str_split($text) as $ch) {
            $next = $out.$ch;

            if ($this->printableWidth($next) > $width) {
                break;
            }

            $out = $next;
        }

        return $out;
    }

    /**
     * ความกว้างจริงบนกระดาษ — สระลอยและวรรณยุกต์ไม่นับ
     */
    public function printableWidth(string $text): int
    {
        $width = 0;

        foreach (mb_str_split($text) as $ch) {
            if (! in_array($ch, self::THAI_COMBINING, true)) {
                $width++;
            }
        }

        return $width;
    }

    /* ---------- ปิดท้าย ---------- */

    /**
     * เตะลิ้นชักเก็บเงิน
     *
     * ESC p m t1 t2 — m คือช่องเสียบ (0 = ช่องแรก ซึ่งร้านส่วนใหญ่ใช้)
     * t1/t2 คือความยาวพัลส์ หน่วยละ 2 มิลลิวินาที ค่า 25/250 คือค่าที่
     * Epson แนะนำและลิ้นชักยี่ห้ออื่นรับได้
     */
    public function kickDrawer(int $pin = 0): self
    {
        return $this->raw(self::ESC.'p'.chr($pin === 1 ? 1 : 0).chr(25).chr(250));
    }

    /** ตัดกระดาษ — เดินกระดาษพ้นหัวตัดก่อน ไม่งั้นบรรทัดท้ายจะถูกตัดคาไว้ */
    public function cut(): self
    {
        return $this->feed(4)->raw(self::GS.'V'.chr(66).chr(0));
    }

    public function toBytes(): string
    {
        return $this->buffer;
    }

    /* ---------- ภายใน ---------- */

    /**
     * UTF-8 -> TIS-620
     *
     * //TRANSLIT ไม่ใส่โดยตั้งใจ — ถ้าแปลงไม่ได้อยากให้หายไปเฉย ๆ
     * ดีกว่าได้ '?' หรือตัวอักษรมั่วบนใบสั่งครัวที่พ่อครัวต้องอ่าน
     */
    protected function encode(string $text): string
    {
        $converted = @iconv('UTF-8', 'TIS-620//IGNORE', $text);

        return $converted === false ? $text : $converted;
    }
}
