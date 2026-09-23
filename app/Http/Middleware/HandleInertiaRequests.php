<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\Branch;
use App\Services\SystemHealthService;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** ข้อมูลที่ทุกหน้าใช้ร่วมกัน */
    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            /*
            | ชื่อและโลโก้ระบบ — ส่งไปทุกหน้าเพื่อให้ฝั่ง Vue มีที่มาที่เดียว
            |
            | ถ้าปล่อยให้แต่ละหน้าพิมพ์ชื่อเองเหมือนเดิม เปลี่ยนชื่อระบบทีต้องไล่แก้
            | สามไฟล์แล้วลืมไฟล์ใดไฟล์หนึ่งเสมอ ค่าพวกนี้คงที่ทั้ง request
            | จึงไม่ต้องห่อ closure ให้โหลดทีหลัง
            */
            'brand' => [
                'name' => (string) config('pos.brand.name'),
                'logo' => config('pos.brand.logo'),
                'mark' => config('pos.brand.mark'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'branch_id' => $user->branch_id,
                ] : null,
            ],
            'branches' => fn () => $user
                ? Branch::whereIn('id', $user->accessibleBranchIds())
                    ->orderBy('name')
                    ->get(['id', 'name', 'code', 'logo_path', 'theme_color'])
                : [],
            'currentBranch' => fn () => CurrentBranch::get()?->only([
                'id', 'name', 'code', 'vat_rate', 'vat_included', 'service_charge_rate', 'currency', 'logo_path', 'cover_path', 'theme_color',
            ]),
            // ลูกค้าใช้ guard คนละตัวกับพนักงาน เปิดหน้าร้านออนไลน์พร้อมกันได้โดยไม่ตีกัน
            'customer' => fn () => ($c = auth('customer')->user()) ? [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'points' => (int) $c->points,
                'tier' => $c->tier,
                'employee_status' => $c->employee_status?->value,
                'is_verified_employee' => $c->isVerifiedEmployee(),
            ] : null,
            /*
            | แถบเตือนสุขภาพระบบ
            |
            | ── ทำไมต้องอยู่ในของที่ส่งไปทุกหน้า ─────────────────────────
            | ถ้ามีแต่หน้า /backoffice/health คนจะเข้าไปดูก็ต่อเมื่อสงสัยอยู่แล้วว่ามีปัญหา
            | ซึ่งไม่เคยเกิดขึ้น — การสำรองที่หยุดไปสามสัปดาห์จึงเงียบได้จนถึงวันที่ต้องกู้
            | ตัวเลขชุดนี้ทำให้ "ไม่รู้" กลายเป็น "เห็นทุกครั้งที่เปิดหลังบ้าน"
            |
            | ส่งเฉพาะคนที่มีสิทธิ์เข้าหน้านั้น คนอื่นได้ null แล้วแถบไม่ขึ้นเลย
            | และแคชไว้ 60 วินาทีในตัว SystemHealthService เพราะ closure ตรงนี้
            | ถูกเรียกทุกครั้งที่โหลดหน้า ไม่ได้เรียกเฉพาะตอนที่ฝั่ง Vue ขอ
            */
            'systemAlerts' => fn () => $user?->can(Permission::SystemHealth)
                ? app(SystemHealthService::class)->alerts()
                : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
