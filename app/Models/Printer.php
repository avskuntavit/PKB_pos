<?php

namespace App\Models;

use App\Enums\PrintGroup;
use App\Models\Concerns\BelongsToBranch;
use App\Printing\Drivers\EscposNetworkDriver;
use App\Printing\PrinterDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * เครื่องพิมพ์หนึ่งเครื่องของสาขา
 *
 * เครื่องเดียวรับได้หลายหน้าที่ — ร้านเล็กใช้เครื่องเดียวออกทั้งใบสั่งครัว
 * และใบเสร็จ ส่วนร้านใหญ่แยกครัวร้อน ครัวเย็น บาร์ เคาน์เตอร์
 */
class Printer extends Model
{
    use BelongsToBranch;

    /** ไดรเวอร์ที่มี — เพิ่มเจ้าใหม่ = เขียนคลาสแล้วใส่ชื่อตรงนี้ */
    public const DRIVERS = [
        'escpos_network' => EscposNetworkDriver::class,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'print_groups' => 'array',
            'columns' => 'integer',
            'copies' => 'integer',
            'port' => 'integer',
            'prints_receipt' => 'boolean',
            'opens_cash_drawer' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function driver(): PrinterDriver
    {
        $class = self::DRIVERS[$this->driver] ?? EscposNetworkDriver::class;

        return app($class);
    }

    public function isConfigured(): bool
    {
        return $this->driver()->isConfigured($this);
    }

    /** เครื่องนี้รับใบสั่งครัวของจุดผลิตนี้ไหม */
    public function handles(PrintGroup $group): bool
    {
        return in_array($group->value, array_map('intval', $this->print_groups ?? []), true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
