<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Purchase = 'purchase';         // เติมสินค้า
    case Usage = 'usage';               // ตัดจากการขาย
    case Waste = 'waste';               // ของเสีย
    case Adjust = 'adjust';             // ปรับยอด
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'เติมสินค้า',
            self::Usage => 'ตัดจากการขาย',
            self::Waste => 'ของเสีย',
            self::Adjust => 'ปรับยอด',
            self::TransferIn => 'รับโอนเข้า',
            self::TransferOut => 'โอนออก',
        };
    }
}
