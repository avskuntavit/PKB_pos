<?php

namespace App\Http\Controllers\Pos;

use App\Enums\Course;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceCall;
use App\Models\Product;
use App\Models\Zone;
use App\Services\BranchSettingService;
use App\Services\ShiftService;
use App\Support\CurrentBranch;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    /** ผังโต๊ะ — หน้าแรกของ POS */
    public function tables(ShiftService $shifts): Response
    {
        $branch = CurrentBranch::getOrFail();

        $tables = DiningTable::with([
            'zone:id,name',
            'openOrder:id,dining_table_id,order_no,grand_total,guest_count,opened_at',
            'openOrder.pendingApprovalItems:id,order_id',
            'activeSession:id,dining_table_id,started_at,order_count',
        ])
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $serviceCalls = ServiceCall::with('diningTable:id,name')
            ->where('branch_id', $branch->id)
            ->pending()
            ->orderBy('created_at')
            ->get()
            ->map(fn (ServiceCall $c) => [
                'id' => $c->id,
                'type' => $c->type->value,
                'label' => $c->type->label(),
                'status' => $c->status,
                'table' => $c->diningTable?->name,
                'dining_table_id' => $c->dining_table_id,
                'order_id' => $c->order_id,
                'waiting_minutes' => (int) $c->created_at?->diffInMinutes(now()),
            ]);

        return Inertia::render('Pos/Tables', [
            'zones' => Zone::where('branch_id', $branch->id)->orderBy('sort_order')->get(['id', 'name']),
            'tables' => $tables,
            'shift' => $shifts->current($branch),
            'serviceCalls' => $serviceCalls,
            'stats' => [
                'occupied' => $tables->filter(fn (DiningTable $t) => $t->status === TableStatus::Occupied)->count(),
                'total' => $tables->count(),
                'open_bills' => Order::where('branch_id', $branch->id)->open()->count(),
                'pending_approvals' => OrderItem::whereHas(
                    'order',
                    fn ($q) => $q->where('branch_id', $branch->id)->where('status', OrderStatus::Open->value)
                )->where('approval_status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * บิลที่ส่งไปหน้าจอขาย
     *
     * แนบวิธีจ่ายที่ลูกค้าแจ้งไว้ตอนสั่งล่วงหน้าไปด้วย
     * พนักงานจะได้ไม่ต้องถามซ้ำ และเลือกช่องทางให้ตรงได้ตั้งแต่เปิดหน้าจอ
     */
    protected function orderPayload(Order $order): array
    {
        $order->load([
            'items.modifiers',
            'diningTable:id,name',
            'customer:id,name,phone',
            'tableSession:id,order_id,started_at,order_count',
        ]);

        return array_merge($order->toArray(), [
            'payment_intent_label' => $order->payment_intent?->label(),
            'is_government_scheme' => (bool) $order->payment_intent?->isGovernmentScheme(),
            'contact_name' => $order->contact_name,
            'contact_phone' => $order->contact_phone,
        ]);
    }

    /** หน้าจอขาย — เลือกเมนูใส่บิล */
    public function terminal(?Order $order = null): Response
    {
        $branch = CurrentBranch::getOrFail();

        $categories = Category::forCatalog($branch->id)
            ->active()
            ->orderBy('sort_order')
            ->get(['id', 'name', 'color']);

        $products = Product::with([
            // เอาเฉพาะตัวเลือกที่เปิดใช้งาน ของที่ปิดไว้ต้องไม่โผล่บนหน้าขาย
            // activeModifierGroups กรองเซ็ตที่ปิดทั้งชุด และที่ปิดเฉพาะเมนูนี้ออกแล้ว
            'activeModifierGroups.modifiers' => fn ($q) => $q->where('is_active', true),
        ])
            ->sellableAt($branch->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category_id' => $p->category_id,
                'price' => $p->priceAt($branch->id),
                'image_path' => $p->image_path,
                'is_open_price' => $p->is_open_price,
                'modifier_groups' => $p->activeModifierGroups->map(fn ($g) => [
                    'id' => $g->id,
                    // ลูกค้าเห็นชื่อหน้าบ้าน "เพิ่มเติม" ไม่ใช่ชื่อภายใน "ก๋วยเตี๋ยว - เพิ่มเติม"
                    'name' => $g->displayName(),
                    'min_select' => $g->min_select,
                    'max_select' => $g->max_select,
                    'is_required' => $g->is_required,
                    'modifiers' => $g->modifiers->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'price_delta' => (float) $m->price_delta,
                        // ใช้ตั้งค่าเริ่มต้น และโชว์ป้าย ×1.5 / ×2 บนปุ่มขนาดจาน
                        'portion_multiplier' => (float) $m->portion_multiplier,
                        'is_default' => $m->is_default,
                    ])->values(),
                ])->values(),
            ]);

        return Inertia::render('Pos/Terminal', [
            'categories' => $categories,
            'products' => $products,
            'order' => $order?->exists ? $this->orderPayload($order) : null,
            'kitchenTickets' => $order?->exists
                ? $order->kitchenTickets()->with('items')->latest('queued_at')->limit(10)->get()
                : [],
            'tables' => DiningTable::where('branch_id', $branch->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'status', 'seats']),
            // คอร์ส — ป้ายบอกลำดับที่อาหารควรออก ใช้กดส่งครัวทีละกอง
            'courses' => Course::options(),
            'orderTypes' => collect(OrderType::cases())
                ->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
            // ช่องทางจ่ายมาจากการตั้งค่าสาขา ไม่ใช่ enum ทั้งชุด
            // ร้านที่ปิดรับเงินสดจะไม่เห็นปุ่มเงินสดเลย
            'paymentMethods' => app(BranchSettingService::class)->enabledMethods($branch),
            'openOrders' => Order::where('branch_id', $branch->id)
                ->where('status', OrderStatus::Open->value)
                ->with('diningTable:id,name')
                ->latest('opened_at')
                ->get(['id', 'order_no', 'dining_table_id', 'grand_total', 'opened_at']),
        ]);
    }
}
