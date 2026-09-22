<?php

namespace App\Http\Controllers\SelfOrder;

use App\Enums\ServiceCallType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTableSession;
use App\Models\Category;
use App\Models\Product;
use App\Services\SelfOrderService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    /** หน้าเมนูที่ลูกค้าเห็นหลังสแกน QR */
    public function show(Request $request, SelfOrderService $selfOrders): Response
    {
        $table = ResolveTableSession::table($request);
        $session = ResolveTableSession::session($request);
        $branch = $table->branch;

        $products = Product::with('modifierGroups.modifiers')
            ->sellableAt($branch->id)
            ->where('is_open_price', false)   // ราคาเปิดต้องให้พนักงานกรอก ลูกค้าสั่งเองไม่ได้
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'category_id' => $p->category_id,
                'price' => $p->priceAt($branch->id),
                'image_path' => $p->image_path,
                'modifier_groups' => $p->modifierGroups->map(fn ($g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'min_select' => $g->min_select,
                    'max_select' => $g->max_select,
                    'is_required' => $g->is_required,
                    'modifiers' => $g->modifiers->where('is_active', true)->values()->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'price_delta' => (float) $m->price_delta,
                    ]),
                ]),
            ]);

        return Inertia::render('Guest/Menu', [
            'qrToken' => $table->qr_token,
            'branch' => [
                'name' => $branch->name,
                'vat_rate' => (float) $branch->vat_rate,
                'vat_included' => (bool) $branch->vat_included,
                'service_charge_rate' => (float) $branch->service_charge_rate,
            ],
            'table' => [
                'name' => $table->name,
                'zone' => $table->zone?->name,
            ],
            'categories' => Category::forCatalog($branch->id)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name', 'color']),
            'products' => $products,
            'status' => $selfOrders->statusFor($session),
            'callTypes' => ServiceCallType::options(),
        ]);
    }
}
