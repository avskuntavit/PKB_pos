<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * เข้าสู่ระบบฝั่งพนักงาน
 *
 * ── ทำไมต้องจำกัดจำนวนครั้ง ────────────────────────────────
 * เดิมเรียก Auth::attempt() ตรง ๆ ไม่มีอะไรกั้นเลย เดารหัสผ่านได้ไม่จำกัดครั้ง
 * ที่แปลกคือฝั่งลูกค้า (OtpService) กลับกันไว้ครบ — จำกัดครั้งต่อรหัส ต่อเบอร์
 * ต่อชั่วโมง มี cooldown — ทั้งที่บัญชีพนักงานเข้าถึงเงินและรายงานได้มากกว่ามาก
 *
 * ── ทำไมนับสองชั้น ────────────────────────────────────────
 * ชั้นแรกนับต่อ (อีเมล + IP) ให้พนักงานที่พิมพ์รหัสผิดเองไม่ไปล็อกเพื่อนร่วมร้าน
 * ที่นั่งอยู่หลัง IP เดียวกัน
 * ชั้นที่สองนับต่อ IP อย่างเดียว เพราะชั้นแรกไม่จับคนที่ไล่ยิงทีละอีเมล
 * ซึ่งเป็นวิธีที่ใช้จริงเวลาเจอหน้าล็อกอินที่ไม่มีอะไรกั้น
 */
class LoginController extends Controller
{
    /** ผิดได้กี่ครั้งต่อหนึ่งบัญชีจากไอพีเดียว */
    protected const MAX_PER_ACCOUNT = 5;

    /** ผิดได้กี่ครั้งต่อไอพี รวมทุกบัญชี */
    protected const MAX_PER_IP = 20;

    protected const DECAY_SECONDS = 60;

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureNotThrottled($request);

        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            foreach (array_keys($this->throttleKeys($request)) as $key) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }

            throw ValidationException::withMessages([
                'email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
            ]);
        }

        // ล็อกอินผ่านแล้วล้างตัวนับ ไม่งั้นคนที่พิมพ์ผิดสี่ครั้งแล้วถูกครั้งที่ห้า
        // จะยังเหลือโควตาแค่ครั้งเดียวไปอีกหนึ่งนาที
        foreach (array_keys($this->throttleKeys($request)) as $key) {
            RateLimiter::clear($key);
        }

        $request->session()->regenerate();

        return redirect()->intended(
            $request->user()->canAccessBackOffice()
                ? route('backoffice.dashboard')
                : route('pos.tables')
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * ปิดประตูถ้าลองผิดมาเกินโควตาแล้ว
     *
     * เช็คก่อนเรียก Auth::attempt() เสมอ — ถ้าเช็คทีหลัง ทุกครั้งที่ถูกบล็อก
     * ก็ยังได้ลองรหัสผ่านจริงไปแล้วหนึ่งครั้ง ซึ่งทำให้การจำกัดครั้งไม่มีความหมาย
     */
    protected function ensureNotThrottled(Request $request): void
    {
        foreach ($this->throttleKeys($request) as $key => $max) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "ลองเข้าสู่ระบบผิดหลายครั้งเกินไป กรุณารออีก {$seconds} วินาที",
            ]);
        }
    }

    /**
     * คีย์ตัวนับ — คีย์เป็นชื่อคีย์ ค่าเป็นเพดานของคีย์นั้น
     *
     * ใส่อีเมลลงในคีย์ผ่าน sha1 เพราะคีย์ของ cache ไม่ควรมีอักขระแปลก ๆ
     * และไม่มีเหตุผลที่จะเก็บอีเมลของคนที่ล็อกอินไม่ผ่านไว้ในแคชแบบอ่านได้
     *
     * @return array<string, int>
     */
    protected function throttleKeys(Request $request): array
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $ip = (string) $request->ip();

        return [
            'login:'.sha1($email.'|'.$ip) => self::MAX_PER_ACCOUNT,
            'login-ip:'.sha1($ip) => self::MAX_PER_IP,
        ];
    }
}
