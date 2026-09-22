<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use Closure;
use Illuminate\Http\Request;

/**
 * ตรวจสิทธิ์แบบละเอียด ใช้แทน role: ในเส้นทางที่ต้องการความแม่นยำ
 * รับได้หลายสิทธิ์ ผ่านถ้ามีอย่างน้อยหนึ่งอัน
 */
class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();

        abort_unless($user, 403);

        foreach ($permissions as $value) {
            $permission = Permission::tryFrom($value);

            if ($permission && $user->can($permission)) {
                return $next($request);
            }
        }

        abort(403, 'คุณไม่มีสิทธิ์ใช้งานส่วนนี้');
    }
}
