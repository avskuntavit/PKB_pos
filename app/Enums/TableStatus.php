<?php

namespace App\Enums;

enum TableStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Reserved = 'reserved';
    case Cleaning = 'cleaning';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'ว่าง',
            self::Occupied => 'มีลูกค้า',
            self::Reserved => 'จองแล้ว',
            self::Cleaning => 'กำลังเก็บโต๊ะ',
        };
    }
}
