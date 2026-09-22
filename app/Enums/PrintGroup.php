<?php

namespace App\Enums;

/** จุดผลิต — ใช้แยกใบสั่งครัวว่าใบไหนไปครัว ใบไหนไปบาร์ */
enum PrintGroup: int
{
    case Kitchen = 1;
    case Bar = 2;
    case Dessert = 3;

    public function label(): string
    {
        return match ($this) {
            self::Kitchen => 'ครัว',
            self::Bar => 'บาร์',
            self::Dessert => 'ของหวาน',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $g) => ['value' => $g->value, 'label' => $g->label()],
            self::cases()
        );
    }
}
