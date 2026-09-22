<?php

namespace App\Http\Controllers\SelfOrder;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTableSession;
use App\Services\SelfOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /** ลูกค้ากดยืนยันตะกร้า */
    public function store(Request $request, SelfOrderService $selfOrders): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:'.SelfOrderService::MAX_LINES_PER_SUBMIT],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:1', 'max:20'],
            'lines.*.modifier_ids' => ['array', 'max:10'],
            'lines.*.modifier_ids.*' => ['integer'],
            'lines.*.note' => ['nullable', 'string', 'max:120'],
        ], [], [
            'lines' => 'รายการอาหาร',
        ]);

        $session = ResolveTableSession::session($request);

        try {
            $selfOrders->submit($session, $data['lines']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'ส่งรายการให้พนักงานแล้ว รอยืนยันสักครู่');
    }
}
