<?php

namespace App\Models;

use App\Enums\StoreType;
use App\Enums\VoucherBase;
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

    /*
    | ค่าตั้งต้นให้ตรงกับ default ของคอลัมน์ใน migration
    |
    | เหตุผลเดียวกับ User — `Branch::create([...])` คืนอ็อบเจกต์ที่คอลัมน์ซึ่งไม่ได้ส่งมา
    | เป็น null เพราะ default อยู่ที่ฐานข้อมูล ไม่ได้อยู่ที่โมเดล
    |
    | ผลที่เคยเจอ: `isTakingOnlineOrders()` = `is_active && is_accepting_online_orders && isOpenNow()`
    | เจอ null ตัวแรกก็คืน false ทันที → "ตอนนี้ร้านปิดรับออเดอร์ออนไลน์" ทั้งที่ร้านเปิดอยู่
    | และ `businessDateFor()` / `isOpenNow()` ก็ต้องมี timezone กับเวลาเปิด-ปิดจริง ๆ ถึงจะคิดถูก
    |
    | ถ้าเพิ่มคอลัมน์ที่มี default ใน migration ต้องมาเพิ่มที่นี่ด้วย
    */
    protected $attributes = [
        'timezone' => 'Asia/Bangkok',
        'currency' => 'THB',
        'vat_rate' => 7.00,
        'vat_included' => true,
        'service_charge_rate' => 0,
        'rounding_mode' => 0,
        'business_day_start' => '05:00:00',
        'is_accepting_online_orders' => true,
        'default_voucher_base' => 'menu_total',
        'open_time' => '09:00:00',
        'close_time' => '21:00:00',
        'prep_minutes' => 20,
        'award_points_online' => true,
        'qr_requires_open_table' => true,
        'staff_benefit_enabled' => true,
        'staff_benefit_monthly_cap' => 0,
        'staff_benefit_exclude_alcohol' => true,
        'restrict_alcohol_hours' => false,
        'is_active' => true,
    ];

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
            'store_type' => StoreType::class,
            'default_voucher_base' => VoucherBase::class,
            // 7 ตำแหน่ง ละเอียดระดับ ~1 ซม. — cast เป็น string ของ decimal ไม่ใช่ float
            // เพราะ float ทำให้พิกัดเพี้ยนท้าย ๆ ทศนิยมเวลาบันทึกกลับ
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
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

    /** รูปบรรยากาศร้าน เรียงตามลำดับที่เจ้าของจัดไว้ */
    public function images(): HasMany
    {
        return $this->hasMany(BranchImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(BranchStockItem::class);
    }

    /** ชื่อประเภทร้านเป็นภาษาไทย — สถานีที่ยังไม่ได้เลือกประเภทคืน null */
    public function storeTypeLabel(): ?string
    {
        return $this->store_type?->label();
    }

    /** มีหมุดบนแผนที่ครบทั้งสองค่าไหม — ครึ่งเดียวใช้ปักหมุดไม่ได้ */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
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

    /**
     * งวดเดือนของ "วันขาย" — ไม่ใช่เดือนตามนาฬิกา
     *
     * ร้านที่ตัดรอบ 05:00 ตอนตีสองของวันที่ 1 ยังขายอยู่ในวันขายของเดือนก่อน
     * ยอดสะสมของงวดจึงต้องนับด้วยเดือนนี้ ไม่ใช่เดือนที่นาฬิกาบอก
     *
     * รวมไว้ที่เดียวเพราะมีสามที่ที่ต้องตอบคำถามนี้ให้ตรงกัน —
     * ถ้าปล่อยให้แต่ละที่เขียน now()->format('Y-m') เอง มันจะไม่ตรงกันอีกแน่
     */
    public function currentPeriod(?Carbon $at = null): string
    {
        return $this->businessDateFor($at)->format('Y-m');
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
