<?php

namespace App\Http\Controllers\Storefront;

use App\Services\OtpService;
use App\Support\StorefrontSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ล็อกอินลูกค้าด้วยเบอร์ + OTP
 *
 * ไม่มีหน้า "สมัครสมาชิก" แยก — ยืนยันเบอร์ครั้งแรกคือการสมัครไปในตัว
 * ลดขั้นตอนให้เหลือน้อยที่สุดเพราะลูกค้ากำลังหิวและยืนรออยู่
 *
 * ── สาขามาจากไหน ────────────────────────────────────────────────
 * URL ของหน้านี้ไม่มีรหัสสาขา ระบบอ่านจาก session/cookie ที่ถูกเขียนไว้
 * ตอนลูกค้าเข้า /order, สแกน QR โต๊ะ หรือกดเลือกที่หน้าเลือกร้าน
 * ถ้าอ่านไม่ได้ ต้องพาไปเลือกร้านก่อน ไม่ใช่ 404 เพราะลูกค้าที่กดลิงก์
 * /order/login ตรง ๆ (เพื่อนส่งมา / ล้างคุกกี้) ไม่ได้ทำอะไรผิด
 */
class AuthController extends StorefrontController
{
    public function show(
        Request $request,
        StorefrontSession $storefront,
        ?string $branchCode = null,
    ): Response|RedirectResponse {
        $branch = $storefront->resolveStation($request, $branchCode);

        // พก URL เดิมไปด้วย เลือกร้านเสร็จจะได้กลับมาที่หน้านี้พร้อม redirect เดิม
        if (! $branch) {
            return $this->askForStation($request->getRequestUri());
        }

        return Inertia::render('Storefront/Auth/Login', [
            'branch' => ['code' => $branch->code, 'name' => $branch->name],
            'redirectTo' => $this->safePath($request->query('redirect')),
            'otpTtlMinutes' => (int) config('foodpos.otp.ttl_minutes'),
        ]);
    }

    /** ขอรหัส OTP */
    public function requestCode(Request $request, OtpService $otp, StorefrontSession $storefront): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'branch_code' => ['nullable', 'string', 'max:20'],
        ]);

        $branch = $storefront->resolveStation($request, $data['branch_code'] ?? null);

        // สาขาถูกปิดระหว่างที่ลูกค้าค้างหน้านี้ไว้ — ให้เลือกใหม่ ดีกว่าเด้ง 404 ทิ้ง
        if (! $branch) {
            return $this->askForStation(route('storefront.login', absolute: false));
        }

        try {
            $result = $otp->request($data['phone'], $branch, $request->ip());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with([
            'success' => 'ส่งรหัสยืนยันแล้ว',
            'otp' => [
                'phone' => $otp->normalizePhone($data['phone']),
                'expires_at' => $result['expires_at'],
                'channel' => $result['channel'],
                'dev_code' => $result['dev_code'],
            ],
        ]);
    }

    /** ยืนยันรหัสแล้วเข้าสู่ระบบ */
    public function verify(Request $request, OtpService $otp, StorefrontSession $storefront): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:10'],
            'name' => ['nullable', 'string', 'max:100'],
            'branch_code' => ['nullable', 'string', 'max:20'],
            'redirect' => ['nullable', 'string', 'max:255'],
        ]);

        $branch = $storefront->resolveStation($request, $data['branch_code'] ?? null);

        if (! $branch) {
            return $this->askForStation(route('storefront.login', absolute: false));
        }

        try {
            $customer = $otp->verify($data['phone'], $data['code'], $branch, $data['name'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        Auth::guard('customer')->login($customer, remember: true);
        $request->session()->regenerate();

        return redirect($this->safePath($data['redirect'] ?? null))
            ->with('success', 'ยินดีต้อนรับ '.$customer->name);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->regenerateToken();

        return redirect('/order')->with('success', 'ออกจากระบบแล้ว');
    }

    /**
     * ยังไม่รู้ว่าลูกค้าอยู่สาขาไหน — พาไปเลือกร้านก่อน แล้วค่อยกลับมาที่เดิม
     *
     * @param  string  $returnTo  path ภายในระบบที่จะกลับมาหลังเลือกร้านเสร็จ
     */
    protected function askForStation(string $returnTo): RedirectResponse
    {
        return redirect()
            ->route('storefront.stations', ['redirect' => $this->safePath($returnTo)])
            ->with('error', 'เลือกร้านที่จะสั่งก่อน แล้วระบบจะพากลับมาหน้าเข้าสู่ระบบ');
    }
}
