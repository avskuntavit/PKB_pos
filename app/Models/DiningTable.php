<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class DiningTable extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TableStatus::class,
            'seats' => 'integer',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'shape' => 'string',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $table) {
            // token ถาวรใน QR ที่ติดหน้าโต๊ะ — สุ่มยาวพอที่จะเดาไม่ได้
            $table->qr_token ??= Str::random(40);
        });
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** บิลที่ยังเปิดอยู่บนโต๊ะนี้ */
    public function openOrder(): HasOne
    {
        return $this->hasOne(Order::class)->where('status', OrderStatus::Open->value);
    }

    /** รอบการนั่งของลูกค้าที่กำลังใช้งาน QR อยู่ */
    public function activeSession(): HasOne
    {
        return $this->hasOne(TableSession::class)->where('status', 'active');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    public function serviceCalls(): HasMany
    {
        return $this->hasMany(ServiceCall::class);
    }

    /** ลิงก์เต็มที่จะฝังลงใน QR */
    public function qrUrl(): string
    {
        return url('/t/'.$this->qr_token);
    }
}
