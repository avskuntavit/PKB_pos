<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Support\CurrentBranch;
use App\Support\StorefrontSession;
use Illuminate\Http\Request;

/**
 * ฐานร่วมของหน้าร้านออนไลน์
 *
 * หน้านี้เปิดได้ทั้งลูกค้าที่ไม่ได้ล็อกอิน และพนักงานที่ล็อกอินอยู่
 * ถ้าเป็นพนักงาน หน้าจอเดิมจะมีแถบเสริมให้สั่งแทนลูกค้าได้
 */
abstract class StorefrontController extends Controller
{
    /**
     * หาร้านจากรหัสใน URL ถ้าไม่ระบุก็ใช้ร้านที่ลูกค้าเลือกไว้
     *
     * ใช้กับหน้าที่ต้องมีร้านแน่ ๆ (เช็คเอาต์ / ติดตามออเดอร์) — ตัดสินไม่ได้ก็ 404
     * หน้าเมนูใช้ StorefrontSession::resolveStation() ตรง ๆ เพราะต้องพาไปเลือกร้านแทนที่จะพัง
     */
    protected function resolveBranch(?string $code = null): Branch
    {
        $branch = app(StorefrontSession::class)->resolveStation(request(), $code);

        abort_if(! $branch, 404, 'ไม่พบร้านที่ต้องการ');

        CurrentBranch::set($branch);

        return $branch;
    }

    /**
     * กรองปลายทาง redirect ให้เหลือเฉพาะ path ภายในระบบ
     *
     * กัน open redirect — ค่านี้มาจาก query string หรือฟอร์ม ซึ่งใครก็แต่งได้
     * ที่ต้องกันเป็นพิเศษคือ "//evil.com" กับ "/\evil.com" เพราะขึ้นต้นด้วย /
     * แต่เบราว์เซอร์อ่านเป็นชื่อโดเมน ไม่ใช่ path ในเว็บเรา
     */
    protected function safePath(?string $path, string $fallback = '/order'): string
    {
        $path = trim((string) $path);

        if ($path === '' || ! str_starts_with($path, '/')) {
            return $fallback;
        }

        return str_starts_with($path, '//') || str_starts_with($path, '/\\') ? $fallback : $path;
    }

    /** ลูกค้าที่ล็อกอินอยู่ (อาจไม่มี — สั่งแบบไม่ระบุตัวตนก็ได้) */
    protected function customer(Request $request): ?Customer
    {
        return $request->user('customer');
    }

    /** ใช้ในหน้าที่ต้องล็อกอินแน่ ๆ */
    protected function currentCustomer(Request $request): Customer
    {
        $customer = $this->customer($request);

        abort_if(! $customer, 403, 'กรุณาเข้าสู่ระบบก่อน');

        return $customer;
    }

    /** พนักงานที่ล็อกอินอยู่และมีสิทธิ์สั่งแทนลูกค้าได้ */
    protected function staffUser(Request $request): ?User
    {
        $user = $request->user();

        return $user && $user->is_active ? $user : null;
    }
}
