<?php

namespace App\Services;

use App\Contracts\OtpSender;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOtpCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * ล็อกอินลูกค้าด้วยเบอร์ + OTP
 *
 * สิ่งที่ระบบนี้ทำ: ออกรหัส เก็บเป็น hash คุมความถี่ และตรวจรหัส
 * สิ่งที่ระบบนี้ไม่ได้ทำ: ส่ง SMS — ต้องเสียบ OtpSender ของผู้ให้บริการเอง
 *
 * การป้องกันมี 3 ชั้น:
 *   1. ขอรหัสใหม่ได้จำกัดครั้งต่อเบอร์ต่อชั่วโมง (กันยิงค่า SMS ทิ้ง)
 *   2. เว้นช่วงก่อนขอซ้ำ (กันกดรัว)
 *   3. กรอกผิดเกินโควตา รหัสนั้นตายทันที ต้องขอใหม่ (กันเดารหัส)
 */
class OtpService
{
    public function __construct(protected OtpSender $sender) {}

    /**
     * ออกรหัสใหม่ให้เบอร์นี้
     *
     * @return array{sent: bool, expires_at: string, dev_code: string|null}
     */
    public function request(string $phone, ?Branch $branch = null, ?string $ip = null): array
    {
        $phone = $this->normalizePhone($phone);

        $this->assertNotThrottled($phone);

        $config = config('foodpos.otp');
        $code = $this->generateCode((int) $config['length']);
        $expiresAt = now()->addMinutes((int) $config['ttl_minutes']);

        DB::transaction(function () use ($phone, $code, $expiresAt, $branch, $ip) {
            // รหัสเก่าที่ยังไม่ถูกใช้ ถือว่าหมดอายุทันทีเมื่อขอใหม่
            CustomerOtpCode::where('phone', $phone)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            CustomerOtpCode::create([
                'branch_id' => $branch?->id,
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => $expiresAt,
                'ip_address' => $ip,
            ]);
        });

        $this->sender->send($phone, $code, (int) $config['ttl_minutes']);

        return [
            'sent' => true,
            'expires_at' => $expiresAt->toIso8601String(),
            'channel' => $this->sender->name(),
            // โหมด dev เท่านั้น — ไว้ทดสอบโดยไม่ต้องมี SMS
            'dev_code' => config('foodpos.otp.expose_in_response') ? $code : null,
        ];
    }

    /** ตรวจรหัสแล้วคืนลูกค้าที่ล็อกอินได้ (สร้างให้ถ้ายังไม่เคยมี) */
    public function verify(string $phone, string $code, ?Branch $branch = null, ?string $name = null): Customer
    {
        $phone = $this->normalizePhone($phone);

        $record = CustomerOtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $record) {
            throw new \DomainException('รหัสหมดอายุแล้ว กรุณากดขอรหัสใหม่');
        }

        $maxAttempts = (int) config('foodpos.otp.max_attempts');

        if ($record->attempts >= $maxAttempts) {
            $record->update(['expires_at' => now()]);

            throw new \DomainException('กรอกรหัสผิดหลายครั้งเกินไป กรุณากดขอรหัสใหม่');
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');
            $remaining = $maxAttempts - $record->attempts;

            throw new \DomainException(
                $remaining > 0
                    ? "รหัสไม่ถูกต้อง เหลืออีก {$remaining} ครั้ง"
                    : 'กรอกรหัสผิดหลายครั้งเกินไป กรุณากดขอรหัสใหม่'
            );
        }

        return DB::transaction(function () use ($record, $phone, $branch, $name) {
            $record->update(['consumed_at' => now()]);

            $customer = Customer::where('phone', $phone)
                ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
                ->first();

            if (! $customer) {
                $customer = Customer::create([
                    'branch_id' => $branch?->id,
                    'name' => $name ?: 'ลูกค้า '.substr($phone, -4),
                    'phone' => $phone,
                ]);
            }

            $customer->update([
                'phone_verified_at' => $customer->phone_verified_at ?? now(),
                'last_login_at' => now(),
            ]);

            return $customer;
        });
    }

    /** เหลืออีกกี่วินาทีจึงจะขอรหัสใหม่ได้ (0 = ขอได้เลย) */
    public function cooldownSeconds(string $phone): int
    {
        $last = CustomerOtpCode::where('phone', $this->normalizePhone($phone))
            ->latest('id')
            ->first();

        if (! $last) {
            return 0;
        }

        $cooldown = (int) config('foodpos.otp.resend_cooldown_seconds');
        $elapsed = (int) $last->created_at->diffInSeconds(now());

        return max(0, $cooldown - $elapsed);
    }

    /* ---------- ภายใน ---------- */

    protected function assertNotThrottled(string $phone): void
    {
        if (($wait = $this->cooldownSeconds($phone)) > 0) {
            throw new \DomainException("กรุณารออีก {$wait} วินาทีก่อนขอรหัสใหม่");
        }

        $perHour = (int) config('foodpos.otp.max_per_hour');

        $recent = CustomerOtpCode::where('phone', $phone)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recent >= $perHour) {
            throw new \DomainException('ขอรหัสบ่อยเกินไป กรุณาลองใหม่ในอีก 1 ชั่วโมง');
        }
    }

    /** ตัดอักขระที่ไม่ใช่ตัวเลขออก เพื่อให้ 08x-xxx-xxxx กับ 08xxxxxxxx เป็นเบอร์เดียวกัน */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '' || strlen($digits) < 9) {
            throw new \DomainException('เบอร์โทรไม่ถูกต้อง');
        }

        return $digits;
    }

    protected function generateCode(int $length): string
    {
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
