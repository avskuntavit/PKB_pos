<?php

namespace App\Printing\Documents;

use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Printing\EscposBuilder;

/**
 * ใบสั่งครัว
 *
 * คนอ่านคือพ่อครัวที่กำลังยืนหน้าเตา มองผ่าน ๆ จากระยะหนึ่งเมตร
 * ทุกอย่างจึงเน้นไปที่ "ทำอะไร กี่จาน" — ชื่อเมนูตัวใหญ่สองเท่า
 * ส่วนเลขที่บิลกับเวลาตัวเล็กไว้ท้ายใบ เอาไว้ตามย้อนหลังเวลามีปัญหา
 *
 * ไม่มีราคาบนใบสั่งครัวโดยตั้งใจ — ครัวไม่ต้องรู้ และมีแต่จะกินที่
 */
class KitchenTicketDocument
{
    public function __construct(
        protected KitchenTicket $ticket,
        protected int $columns = 48,
    ) {}

    public function render(): string
    {
        $t = $this->ticket;
        $t->loadMissing(['items', 'diningTable:id,name', 'order:id,order_no,type']);

        $b = EscposBuilder::make($this->columns)->begin();

        // ── หัวใบ: จุดผลิต + โต๊ะ ตัวใหญ่ที่สุดบนใบ ──
        $b->align(1)->size(2, 2)->bold();
        $b->line($t->print_group->label());
        $b->normal()->align(1);

        $where = $t->diningTable?->name
            ? 'โต๊ะ '.$t->diningTable->name
            : ($t->order_type === 'takeaway' ? 'ซื้อกลับบ้าน' : 'รับที่ร้าน');

        $b->size(2, 2)->bold()->line($where)->normal();

        // คอร์สอยู่ใต้ชื่อโต๊ะ — ครัวต้องรู้ว่าใบนี้คือกองไหนก่อนเริ่มลงมือ
        if ($t->course) {
            $b->align(1)->bold()->line('['.$t->course->label().']')->bold(false);
        }

        if ($t->round > 1) {
            $b->align(1)->line('สั่งเพิ่ม ครั้งที่ '.$t->round);
        }

        $b->align(0)->rule('=');

        // ── รายการ ──
        foreach ($t->items as $item) {
            $this->item($b, $item);
        }

        $b->rule('=');

        if (filled($t->note)) {
            $b->bold()->line('หมายเหตุทั้งใบ:')->bold(false);
            $b->line($t->note);
            $b->rule();
        }

        // ── ท้ายใบ: ข้อมูลไว้ตามย้อนหลัง ──
        $b->line('บิล '.($t->order?->order_no ?? '-').'   ใบ '.$t->ticket_no);
        $b->line('เวลา '.$t->queued_at?->format('d/m/Y H:i'));

        return $b->cut()->toBytes();
    }

    /**
     * หนึ่งรายการ — จำนวนนำหน้าเสมอ
     *
     * พ่อครัวสแกนหาตัวเลขก่อนชื่อ ถ้าเอาชื่อขึ้นก่อนจะต้องอ่านจนจบบรรทัด
     * ถึงจะรู้ว่ากี่จาน ซึ่งช้ากว่าตอนออเดอร์เข้ารัว ๆ
     */
    protected function item(EscposBuilder $b, KitchenTicketItem $item): void
    {
        $qty = rtrim(rtrim(number_format((float) $item->qty, 2), '0'), '.');

        $b->size(2, 2)->bold();
        $b->line($qty.' x '.$b->truncate($item->product_name, intdiv($this->columns, 2) - strlen($qty) - 3));
        $b->normal();

        if (filled($item->modifiers_text)) {
            // ย่อหน้าให้เห็นชัดว่าเป็นส่วนขยายของเมนูข้างบน ไม่ใช่เมนูใหม่
            $b->line('    - '.$item->modifiers_text);
        }

        if (filled($item->note)) {
            $b->bold()->line('    ** '.$item->note)->bold(false);
        }

        $b->feed();
    }
}
