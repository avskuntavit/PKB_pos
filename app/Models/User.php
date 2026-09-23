<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use BelongsToBranch, HasFactory, Notifiable, SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['password', 'pin_code', 'remember_token'];

    /*
    | ค่าตั้งต้นให้ตรงกับ default ของคอลัมน์ใน migration
    |
    | ── ทำไมต้องมี ──────────────────────────────────────────────────────
    | `User::create([...])` ที่ไม่ได้ส่ง is_active มา จะคืนอ็อบเจกต์ที่ is_active เป็น **null**
    | เพราะ default อยู่ที่ฐานข้อมูล ไม่ได้อยู่ที่ตัวโมเดล ต้องอ่านใหม่ถึงจะได้ค่า
    |
    | แล้ว `can()` ขึ้นต้นด้วย `$this->is_active && ...` → null แปลว่า false
    | = คนที่เพิ่งถูกสร้างไม่มีสิทธิ์อะไรเลยสักอย่าง จนกว่าจะ refresh
    | อาการจะโผล่เฉพาะโค้ดที่สร้างผู้ใช้แล้วเช็คสิทธิ์ต่อทันที ซึ่งเงียบมากเวลาเจอ
    |
    | ประกาศไว้ตรงนี้ให้อ็อบเจกต์ในหน่วยความจำตรงกับแถวในฐานข้อมูลตั้งแต่แรก
    | ส่งค่ามาเองก็ยังทับได้ตามปกติ และแถวที่อ่านจากฐานข้อมูลไม่เกี่ยวกับค่าชุดนี้
    */
    protected $attributes = [
        'role' => 'cashier',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_code' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'permissions' => 'array',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'opened_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function canAccessBackOffice(): bool
    {
        return $this->can(Permission::BackOfficeAccess);
    }

    /**
     * สิทธิ์จริงของคนนี้ = ค่าตั้งต้นของตำแหน่ง + ที่เพิ่มให้ - ที่ตัดออก
     *
     * ตั้งใจให้ override รายคนได้ เพราะร้านจริงมีข้อยกเว้นเสมอ
     * เช่น แคชเชียร์อาวุโสคนหนึ่งที่ให้ปิดรอบได้ แต่คนอื่นในตำแหน่งเดียวกันไม่ได้
     */
    public function permissionValues(): array
    {
        $defaults = array_map(fn (Permission $p) => $p->value, Permission::defaultsFor($this->role));

        $overrides = $this->permissions ?? [];
        $granted = array_merge($defaults, $overrides['grant'] ?? []);

        return array_values(array_diff(array_unique($granted), $overrides['revoke'] ?? []));
    }

    public function can($abilities, $arguments = []): bool
    {
        if ($abilities instanceof Permission) {
            return $this->is_active && in_array($abilities->value, $this->permissionValues(), true);
        }

        if (is_string($abilities) && Permission::tryFrom($abilities)) {
            return $this->is_active && in_array($abilities, $this->permissionValues(), true);
        }

        return parent::can($abilities, $arguments);
    }

    /** เจ้าของเห็นได้ทุกสาขา คนอื่นเห็นเฉพาะสาขาตัวเอง */
    public function accessibleBranchIds(): array
    {
        return $this->isOwner()
            ? Branch::where('is_active', true)->pluck('id')->all()
            : array_filter([$this->branch_id]);
    }
}
