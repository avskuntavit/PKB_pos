<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * บัญชีผู้ให้บริการชำระเงินของสาขาหนึ่ง
 *
 * ── ทำไมกุญแจอยู่ในฐานข้อมูล ไม่อยู่ใน .env ────────────────────────────
 * สาขาละบัญชี และเจ้าของร้านต้องแก้เองได้จากหลังบ้านโดยไม่ต้องรอ deploy
 * ถ้าอยู่ใน .env การเพิ่มสาขาที่ 6 คือการแก้ไฟล์บนเครื่องเซิร์ฟเวอร์
 *
 * ── ผลข้างเคียงที่ต้องรู้ ────────────────────────────────────────────
 * `credentials` เข้ารหัสด้วย APP_KEY และ `.env` **ไม่ได้อยู่ในชุดสำรอง**
 * ถ้า APP_KEY หาย คอลัมน์นี้อ่านไม่ออกทั้งตาราง ต้องไปขอกุญแจจากเกตเวย์ใหม่
 * (กติกาข้อ "เก็บ APP_KEY ไว้ที่อื่นด้วยมือ" ใน progress.md มีเหตุผลนี้เพิ่มอีกข้อ)
 */
class PaymentProviderAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            // เข้ารหัสที่ชั้นโมเดล — ใครที่อ่านฐานข้อมูลตรงจะเห็นแต่ข้อความที่อ่านไม่ออก
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isLive(): bool
    {
        return $this->mode === 'live';
    }

    /**
     * กุญแจหนึ่งค่า
     *
     * ผ่านเมธอดนี้ที่เดียว เพื่อให้มีจุดเดียวที่ต้องแก้เวลาอยากเปลี่ยนวิธีเก็บ
     * (เช่นย้ายไป vault) และเพื่อให้ไม่มีใครเผลอ `dd($account->credentials)`
     * ในโค้ดที่วิ่งบนเครื่องจริง
     */
    public function secret(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** กรอกกุญแจครบพอจะเรียกใช้งานได้หรือยัง */
    public function isUsable(): bool
    {
        return $this->is_active && $this->missingKeys() === [];
    }

    /**
     * กุญแจที่ยังไม่ได้กรอก ตามที่เจ้านี้ต้องการ
     *
     * เดิมเช็คแค่ `filled($this->credentials)` ซึ่งผ่านแม้กรอกมาช่องเดียว
     * แล้ว driver จะไปตายตอนเรียก API ด้วยข้อความของเกตเวย์ที่คนอ่านไม่รู้เรื่อง
     *
     * @return array<int, string>
     */
    public function missingKeys(): array
    {
        return array_values(array_filter(
            $this->provider->requiredCredentialKeys(),
            fn (string $key) => $this->secret($key) === null,
        ));
    }

    /**
     * กุญแจในรูปที่ส่งให้หน้าจอได้
     *
     * ── กฎเหล็กของหน้านี้ ────────────────────────────────────────────
     * **ค่าจริงของกุญแจไม่เคยถูกส่งออกไปที่เบราว์เซอร์** ส่งแค่สี่ตัวท้าย
     * ให้คนดูยืนยันได้ว่าใส่ตัวไหนไว้ กับธงว่ามีค่าอยู่แล้วหรือยังว่าง
     *
     * ถ้าส่งค่าจริงไป มันจะไปอยู่ในซอร์สของหน้า ใน history ของเบราว์เซอร์
     * และใน log ของ proxy ทุกตัวที่อยู่ระหว่างทาง — กุญแจที่หลุดแบบนั้น
     * เรียกคืนไม่ได้ ต้องไปขอออกใหม่จากเกตเวย์
     *
     * @return array<string, array{filled: bool, hint: ?string}>
     */
    public function maskedCredentials(): array
    {
        $out = [];

        foreach ($this->provider->credentialFields() as $field) {
            $value = $this->secret($field['key']);

            $out[$field['key']] = [
                'filled' => $value !== null,
                // ช่องที่ไม่ใช่ความลับ (เช่น publishable key) โชว์ได้เต็ม
                'hint' => $value === null
                    ? null
                    : ($field['secret'] ? self::mask($value) : $value),
            ];
        }

        return $out;
    }

    /** เหลือแค่สี่ตัวท้าย — พอให้คนยืนยันว่าใส่ตัวไหน ไม่พอให้ใครเอาไปใช้ */
    public static function mask(string $value): string
    {
        $tail = mb_substr($value, -4);

        return str_repeat('•', max(4, min(12, mb_strlen($value) - 4))).$tail;
    }
}
