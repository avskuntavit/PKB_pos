<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    /** รายการบิลทั้งหมด พร้อมตัวกรอง */
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);
        $branchIds = $this->branchIds($request);

        $orders = Order::query()
            ->with(['diningTable:id,name', 'closedBy:id,name', 'branch:id,name'])
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$from, $to])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where(fn ($sub) => $sub->where('order_no', 'like', "%{$term}%")
                    ->orWhere('receipt_no', 'like', "%{$term}%"));
            })
            ->latest('opened_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('BackOffice/Sales/Index', [
            'filters' => [
                'from' => $from,
                'to' => $to,
                'branch_ids' => $branchIds,
                'status' => $request->input('status'),
                'type' => $request->input('type'),
                'search' => $request->input('search'),
            ],
            'orders' => $orders,
            'statuses' => collect(OrderStatus::cases())
                ->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    /** รายละเอียดบิล 1 ใบ */
    public function show(Order $order): Response
    {
        $this->authorizeBranch($order);

        $order->load([
            'items.modifiers',
            'payments',
            'refunds',
            'orderPromotions',
            'diningTable:id,name',
            'customer:id,name,phone',
            'openedBy:id,name',
            'closedBy:id,name',
            'branch:id,name,vat_rate,vat_included',
        ]);

        return Inertia::render('BackOffice/Sales/Show', [
            'order' => $order,
        ]);
    }

    protected function authorizeBranch(Order $order): void
    {
        abort_unless(
            in_array($order->branch_id, request()->user()->accessibleBranchIds(), true),
            403
        );
    }
}
