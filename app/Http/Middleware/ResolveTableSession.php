<?php

namespace App\Http\Middleware;

use App\Models\DiningTable;
use App\Models\TableSession;
use App\Services\TableSessionService;
use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;

/**
 * เส้นทางฝั่งลูกค้า (/t/{qrToken}) — ไม่ต้องล็อกอิน
 *
 * แปลง token ใน QR เป็นโต๊ะ + รอบการนั่ง แล้วแนบไปกับ request
 * ตัว session_token เก็บใน session ของเบราว์เซอร์ลูกค้า เพื่อแยกว่ารายการไหน "ของฉัน"
 */
class ResolveTableSession
{
    public function __construct(protected TableSessionService $sessions) {}

    public function handle(Request $request, Closure $next)
    {
        $qrToken = (string) $request->route('qrToken');

        $table = $this->sessions->findTableByQrToken($qrToken);

        abort_if(! $table, 404, 'ไม่พบโต๊ะนี้ กรุณาติดต่อพนักงาน');
        abort_if(! $table->branch?->is_active, 404, 'สาขานี้ปิดให้บริการอยู่');

        $sessionKey = 'table_session_token.'.$table->id;

        // token เดิมในเบราว์เซอร์ยังใช้ได้ไหม ถ้าไม่ได้ก็เปิดรอบใหม่ให้
        $session = $this->sessions->validateToken($request->session()->get($sessionKey));

        if (! $session || $session->dining_table_id !== $table->id) {
            $session = $this->sessions->resolve($table, $request->ip());
            $request->session()->put($sessionKey, $session->session_token);
        }

        CurrentBranch::set($table->branch);

        $request->attributes->set('diningTable', $table);
        $request->attributes->set('tableSession', $session);

        return $next($request);
    }

    public static function table(Request $request): DiningTable
    {
        return $request->attributes->get('diningTable');
    }

    public static function session(Request $request): TableSession
    {
        return $request->attributes->get('tableSession');
    }
}
