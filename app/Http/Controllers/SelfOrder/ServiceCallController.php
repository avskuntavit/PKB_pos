<?php

namespace App\Http\Controllers\SelfOrder;

use App\Enums\ServiceCallType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTableSession;
use App\Services\SelfOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServiceCallController extends Controller
{
    /** ลูกค้ากดเรียกพนักงาน / ขอเช็คบิล */
    public function store(Request $request, SelfOrderService $selfOrders): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:bill,assist,water'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        $session = ResolveTableSession::session($request);
        $type = ServiceCallType::from($data['type']);

        $selfOrders->callStaff($session, $type, $data['note'] ?? null);

        return back()->with('success', match ($type) {
            ServiceCallType::Bill => 'แจ้งพนักงานให้มาเก็บเงินแล้ว กรุณารอสักครู่',
            default => 'แจ้งพนักงานแล้ว กำลังไปหาที่โต๊ะ',
        });
    }
}
