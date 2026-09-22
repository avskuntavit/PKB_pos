<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * กันคำสั่งเดิมถูกบันทึกซ้ำเมื่อถูกยิงซ้ำ
 *
 * ── ปัญหาที่แก้ ─────────────────────────────────────────────
 * พนักงานกด "ชำระเงิน" แล้วเน็ตหลุดก่อนคำตอบจะกลับมา หน้าจอขึ้นว่าพลาด
 * พนักงานกดใหม่ — แต่ครั้งแรกเซิร์ฟเวอร์บันทึกไปแล้ว บิลเลยถูกจ่ายสองรอบ
 * นี่คือความเสียหายที่แก้ย้อนหลังยากที่สุดในร้าน เพราะเงินในลิ้นชักไม่ตรงระบบ
 *
 * ── วิธีแก้ ────────────────────────────────────────────────
 * หน้าจอแนบ X-Idempotency-Key มาหนึ่งค่าต่อ "หนึ่งความตั้งใจของผู้ใช้"
 * ค่าเดิมถูกยิงซ้ำกี่ครั้งก็ทำงานจริงแค่ครั้งเดียว
 *
 * ใช้ cache เป็นที่จองคีย์ ไม่ใช่ตารางใหม่ เพราะ Cache::add() เป็น atomic อยู่แล้ว
 * และหมดอายุเองโดยไม่ต้องมีงานล้างข้อมูลมาคอยตามเก็บ
 *
 * ── สิ่งที่จงใจไม่ทำ ────────────────────────────────────────
 * ไม่เก็บคำตอบของครั้งแรกไว้ตอบซ้ำ เพราะทุก endpoint ที่ใช้ตัวนี้ตอบเป็น redirect
 * การเด้งกลับหน้าเดิมพร้อมข้อความว่า "บันทึกไปแล้ว" ให้ผลที่ถูกต้องและอ่านง่ายกว่า
 */
class IdempotentRequest
{
    /**
     * อายุของคีย์
     *
     * ยาวพอให้ครอบคลุมการกดซ้ำของคนจริง (เน็ตหลุดแล้วกดใหม่อีกที)
     * แต่ไม่ยาวจนบิลถัดไปของโต๊ะเดิมถูกปฏิเสธเพราะคีย์เก่ายังค้าง
     */
    protected const TTL_SECONDS = 900;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->cacheKey($request);

        // ไม่ส่งคีย์มาก็ทำงานปกติ — ไม่บังคับ เพราะหน้าจอที่ยังไม่ได้อัปเดตต้องใช้งานได้อยู่
        if ($key === null) {
            return $next($request);
        }

        // ใส่ได้ก็ต่อเมื่อยังไม่มีคีย์นี้ — เป็นการจองสิทธิ์แบบ atomic ในคำสั่งเดียว
        if (! Cache::add($key, true, self::TTL_SECONDS)) {
            return back()->with('success', 'รายการนี้บันทึกไปแล้ว ระบบไม่ได้ทำซ้ำให้');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // งานไม่สำเร็จ ต้องคืนคีย์ ไม่งั้นผู้ใช้แก้แล้วส่งใหม่ไม่ได้เลย
            Cache::forget($key);

            throw $e;
        }

        if ($this->failed($request, $response)) {
            Cache::forget($key);
        }

        return $response;
    }

    /**
     * คีย์ที่จะใช้จองใน cache — null = ไม่ต้องกันซ้ำสำหรับคำขอนี้
     *
     * ผูกกับตัวผู้ใช้ด้วย เพราะคีย์ถูกสุ่มจากฝั่งเบราว์เซอร์
     * ถึงจะชนกันได้ยาก แต่ถ้าชนขึ้นมาจะกลายเป็นการบล็อกงานของคนอื่น
     */
    protected function cacheKey(Request $request): ?string
    {
        $key = trim((string) $request->header('X-Idempotency-Key'));

        if (! preg_match('/^[A-Za-z0-9._-]{8,64}$/', $key)) {
            return null;
        }

        $owner = $request->user()?->getAuthIdentifier()
            ?? $request->user('customer')?->getAuthIdentifier()
            ?? ($request->hasSession() ? $request->session()->getId() : 'guest');

        return 'idem:'.sha1($owner.'|'.$key);
    }

    /**
     * คำขอนี้ล้มเหลวไหม
     *
     * เช็ค _flash.new ไม่ใช่ session('errors') ตรง ๆ
     * เพราะ errors ของคำขอ "ก่อนหน้า" ยังค้างอยู่ใน flash เก่าตอนที่คำขอนี้ทำงาน
     * ถ้าอ่านตรง ๆ คำขอที่สำเร็จจะถูกตัดสินว่าล้มเหลวแล้วคืนคีย์ทิ้ง
     * ซึ่งทำให้การกันซ้ำหายไปเงียบ ๆ โดยไม่มีใครรู้
     */
    protected function failed(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }

        return in_array('errors', (array) $request->session()->get('_flash.new', []), true);
    }
}
