<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'vat_included' => 'boolean',
            'service_charge_rate' => 'decimal:2',
            'rounding_mode' => 'integer',
            'is_active' => 'boolean',
            'is_accepting_online_orders' => 'boolean',
            'award_points_online' => 'boolean',
            'qr_requires_open_table' => 'boolean',
            'prep_minutes' => 'integer',
            'staff_benefit_enabled' => 'boolean',
            'staff_benefit_monthly_cap' => 'decimal:2',
            'staff_benefit_exclude_alcohol' => 'boolean',
            'restrict_alcohol_hours' => 'boolean',
            'alcohol_hours' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $branch) {
            $branch->uuid ??= (string) Str::uuid();
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function diningTables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(BranchPaymentMethod::class)->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(OrderReview::class);
    }

    /**
     * ขายแอลกอฮอล์ได้ตอนนี้ไหม
     *
     * ปิดไว้เป็นค่าเริ่มต้น ร้านที่ต้องการคุมต้องเปิดและตั้งช่วงเวลาเอง
     * ให้ตรงกับระเบียบที่บังคับใช้อยู่ ณ เวลานั้น — ระบบไม่ได้ฝังกฎหมายไว้
     */
    public function canSellAlcoholNow(?Carbon $at = null): bool
    {
        if (! $this->restrict_alcohol_hours) {
            return true;
        }

        $windows = $this->alcohol_hours ?: [];

        if (! $windows) {
            return true;
        }

        $time = ($at ?? now($this->timezone))->copy()->setTimezone($this->timezone)->format('H:i');

        foreach ($windows as $window) {
            $from = $window['from'] ?? null;
            $to = $window['to'] ?? null;

            if (! $from || ! $to) {
                continue;
            }

            $inWindow = $from <= $to
                ? ($time >= $from && $time <= $to)
                : ($time >= $from || $time <= $to);   // ช่วงคร่อมเที่ยงคืน

            if ($inWindow) {
                return true;
            }
        }

        return false;
    }

    /** ตอนนี้อยู่ในเวลาทำการไหม (ใช้โชว์ป้ายเปิด/ปิดบนหน้าร้านออนไลน์) */
    public function isOpenNow(?Carbon $at = null): bool
    {
        $at = ($at ?? now($this->timezone))->copy()->setTimezone($this->timezone);
        $time = $at->format('H:i:s');

        // สาขาที่ยังไม่ได้ตั้งเวลา ถือว่าเปิดตลอด ดีกว่าปิดรับออเดอร์เงียบ ๆ โดยไม่มีใครรู้
        $open = (string) ($this->open_time ?: '00:00:00');
        $close = (string) ($this->close_time ?: '23:59:59');

        // ร้านที่ปิดหลังเที่ยงคืน เช่น 17:00-02:00 ต้องเช็คคร่อมวัน
        return $open <= $close
            ? ($time >= $open && $time <= $close)
            : ($time >= $open || $time <= $close);
    }

    public function isTakingOnlineOrders(): bool
    {
        return $this->is_active && $this->is_accepting_online_orders && $this->isOpenNow();
    }

    /** เวลาที่เร็วที่สุดที่ลูกค้ามารับได้ */
    public function earliestPickupAt(): Carbon
    {
        return now($this->timezone)->addMinutes(max(5, (int) $this->prep_minutes));
    }

    /**
     * วันขายของเวลาที่กำหนด — ถ้าขายข้ามเที่ยงคืน ให้นับเป็นวันก่อนหน้า
     * จนกว่าจะถึง business_day_start
     */
    public function businessDateFor(?Carbon $at = null): Carbon
    {
        $tz = $this->timezone ?: config('app.timezone', 'Asia/Bangkok');
        $at = ($at ?? now($tz))->copy()->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', (string) ($this->business_day_start ?: '05:00')));

        $cutoff = $at->copy()->setTime($h, $m, 0);

        return $at->lt($cutoff)
            ? $at->copy()->subDay()->startOfDay()
            : $at->copy()->startOfDay();
    }
}
