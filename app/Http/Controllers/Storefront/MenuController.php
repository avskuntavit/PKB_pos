<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\KitchenTicketStatus;
use App\Enums\OrderType;
use App\Enums\PaymentIntent;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Services\BranchSettingService;
use App\Services\PaymentService;
use App\Enums\DietTag;
use App\Services\MenuInsightService;
use App\Services\StaffBenefitService;
use App\Services\TableBillService;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Product;
use App\Models\ServiceCall;
use App\Support\StorefrontSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends StorefrontController
{
    /** หน้าเมนูร้าน — จุดเริ่มของทั้งลูกค้าและพนักงานที่สั่งแทน */
    public function index(
        Request $request,
        StorefrontSession $storefront,
        ?string $branchCode = null,
    ): Response|RedirectResponse {
        $branch = $storefront->resolveStation($request, $branchCode);

        // ยังไม่รู้ว่าลูกค้าจะสั่งจากร้านไหน — ถามก่อน ดีกว่าเดาแล้วให้ไปรับผิดสาขา
        if (! $branch) {
            return redirect()->route('storefront.stations');
        }

        $staff = $this->staffUser($request);
        $customer = $this->customer($request);

        // นั่งโต๊ะไหน หรือสแกนโต๊ะไหนมาแล้วแต่ยังรอพนักงานเปิดให้
        ['table' => $table, 'pendingTable' => $pendingTable] = $storefront->seating($request, $branch);

        // โชว์ราคาพนักงานเฉพาะคนที่ได้สิทธิ์แล้ว คนอื่นเห็นราคาปกติ
        $showStaffPrice = $branch->staff_benefit_enabled && (bool) $customer?->isVerifiedEmployee();

        // sellableAt = เมนูกลาง + เมนูเฉพาะสาขานี้ หักที่ปิดขาย/ของหมด ทั้งค่ากลางและค่าสาขา
        // และลาก override มาด้วย ราคาที่ map ข้างล่างจึงเป็นราคาของสาขานี้จริง ๆ
        // เมนูขายดีคิดจากบิลที่ปิดแล้วจริง ๆ ร้านติดป้ายเองไม่ได้
        $bestSellers = array_flip(app(MenuInsightService::class)->bestSellerIds($branch));

        $products = Product::with('category:id,name,color', 'activeModifierGroups.modifiers')
            ->sellableAt($branch->id)
            ->where('is_open_price', false)   // ราคาเปิดต้องให้พนักงานกรอกที่เครื่อง POS
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'category_id' => $p->category_id,
                'price' => $p->priceAt($branch->id),
                'staff_price' => $showStaffPrice ? $p->staffPrice() : null,
                'is_alcohol' => (bool) $p->is_alcohol,
                'diet_tags' => array_map(
                    fn (DietTag $t) => ['value' => $t->value, 'label' => $t->label(), 'kind' => $t->kind()],
                    $p->dietTags(),
                ),
                'is_best_seller' => isset($bestSellers[$p->id]),
                'image_path' => $p->image_path,
                'promo_label' => $p->promo_label,
                'modifier_groups' => $p->activeModifierGroups->map(fn ($g) => [
                    'id' => $g->id,
                    // ลูกค้าเห็นชื่อหน้าบ้าน ไม่ใช่ชื่อภายในของเซ็ต
                    'name' => $g->displayName(),
                    'min_select' => $g->min_select,
                    'max_select' => $g->max_select,
                    'is_required' => $g->is_required,
                    'modifiers' => $g->modifiers->where('is_active', true)->values()->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'price_delta' => (float) $m->price_delta,
                        // ติ๊กไว้ให้ตั้งแต่เปิดหน้าต่างสั่ง เช่น ขนาด "ธรรมดา"
                        'is_default' => $m->is_default,
                    ]),
                ]),
            ]);

        /*
        | กลุ่มโปรโมท — หยิบจาก $products ที่ดึงมาแล้ว ไม่ query ซ้ำ
        | และหยิบจากชุดเดียวกันจึงการันตีว่าเมนูที่โปรโมทยังเปิดขายจริง
        | (ปิดขาย/ของหมด/ราคาเปิด จะไม่หลุดมาโผล่บนหัวหน้าจอ)
        */
        $byId = $products->keyBy('id');

        // forCatalog ไม่ใช่ sellableAt — ผลลัพธ์ถูกกรองอีกชั้นด้วย $byId ข้างล่างอยู่แล้ว
        // เมนูที่โปรโมทไว้แต่ปิดขายที่สาขานี้จะหล่นออกตรงนั้นเอง
        $featuredId = Product::forCatalog($branch->id)
            ->where('is_featured', true)
            ->value('id');

        $promoIds = Product::forCatalog($branch->id)
            ->promoted()
            ->pluck('id');

        /*
        | สถานะคิวหน้าร้าน — นับใบสั่งครัวที่ยังรอทำกับกำลังทำ
        | ไม่นับ "รอเสิร์ฟ" เพราะของทำเสร็จแล้ว ไม่ได้กินเวลาครัวของคนที่กำลังจะสั่ง
        */
        $queueCount = KitchenTicket::where('branch_id', $branch->id)
            ->whereIn('status', [KitchenTicketStatus::Queued, KitchenTicketStatus::Preparing])
            ->count();

        return Inertia::render('Storefront/Menu', [
            'branch' => [
                'code' => $branch->code,
                'name' => $branch->name,
                'intro' => $branch->intro,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'cover_path' => $branch->cover_path,
                'logo_path' => $branch->logo_path,
                'open_time' => substr((string) $branch->open_time, 0, 5),
                'close_time' => substr((string) $branch->close_time, 0, 5),
                'is_open_now' => $branch->isOpenNow(),
                'is_taking_orders' => $branch->isTakingOnlineOrders(),
                'prep_minutes' => (int) $branch->prep_minutes,
                'earliest_pickup_at' => $branch->earliestPickupAt()->toIso8601String(),
            ],
            'categories' => Category::forCatalog($branch->id)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name', 'color']),
            'products' => $products,
            'queue' => [
                'count' => $queueCount,
                'label' => $queueCount === 0
                    ? 'ไม่มีคิว สั่งได้เลย!'
                    : 'มีคิวรออยู่ '.$queueCount.' รายการ',
            ],
            // อัตราแต้มสะสม — หน้าตะกร้าเอาไปคำนวณโชว์ ใช้ค่าเดียวกับตอนให้แต้มจริง
            'points' => [
                'baht_per_point' => PaymentService::BAHT_PER_POINT,
                'enabled' => (bool) $branch->award_points_online,
            ],
            'promo' => [
                'title' => $branch->promo_title ?: 'สำหรับคุณ',
                'featured' => $featuredId ? $byId->get($featuredId) : null,
                'items' => $promoIds->map(fn ($id) => $byId->get($id))->filter()->values(),
            ],
            'orderTypes' => [
                ['value' => OrderType::Takeaway->value, 'label' => 'สั่งล่วงหน้า รับที่ร้าน'],
                ['value' => OrderType::DineIn->value, 'label' => 'ทานที่ร้าน (จองล่วงหน้า)'],
            ],
            // ให้ลูกค้าเลือกเฉพาะวิธีที่สาขานี้เปิดรับจริง
            'paymentIntents' => $this->availableIntents($branch),
            'benefit' => $showStaffPrice
                ? app(StaffBenefitService::class)->balanceFor($customer, $branch)
                : null,

            // โต๊ะที่ลูกค้าสแกน QR มา — ค่านี้มาจาก session ฝั่งเซิร์ฟเวอร์เท่านั้น
            // หน้าเว็บใช้แค่แสดงผล ตอนเช็คเอาต์เซิร์ฟเวอร์อ่าน session ใหม่อยู่ดี
            'table' => $table ? ['name' => $table->name, 'seats' => (int) $table->seats] : null,

            // สแกน QR โต๊ะมาแล้วแต่โต๊ะยังไม่ถูกเปิด — บอกลูกค้าว่าต้องทำอะไรต่อ
            'pendingTable' => $pendingTable,

            // ชื่อเล่นที่ลูกค้าตั้งไว้ — null = ยังไม่เคยตั้ง หน้าจอจะถามตอนหยิบของชิ้นแรก
            'guestName' => $storefront->guestName($request),

            // แจ้งพนักงานให้มาเปิดโต๊ะไปแล้วหรือยัง — ปุ่มจะได้ไม่ชวนให้กดซ้ำ
            'openRequested' => $pendingTable
                ? ServiceCall::query()
                    ->openRequestFor($storefront->pendingTable($request, $branch)?->id ?? 0)
                    ->exists()
                : false,

            // บิลที่โต๊ะนี้เปิดค้างอยู่ — สั่งไปแล้วกี่จาน รวมเท่าไหร่ ถึงไหนแล้ว
            // null = ยังไม่ได้สั่งอะไร หรือไม่ได้นั่งโต๊ะ แถบล่างจะไม่ขึ้น
            'tableBill' => app(TableBillService::class)->forTable($table),

            // ร้านอื่นที่ลูกค้าย้ายไปได้ — มีร้านเดียวก็ไม่ต้องโชว์ปุ่มเปลี่ยน
            'stationCount' => count($storefront->options()),

            // แถบพนักงานจะขึ้นก็ต่อเมื่อมีคนล็อกอินอยู่เท่านั้น
            'staffMode' => $staff ? [
                'user_name' => $staff->name,
                'tables' => DiningTable::where('branch_id', $branch->id)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'seats', 'status']),
            ] : null,
        ]);
    }

    /**
     * แปลงช่องทางจ่ายที่สาขาเปิดรับ ให้เป็นตัวเลือกบนหน้าเช็คเอาต์
     *
     * "จ่ายตอนมารับ" ยังมีให้เสมอ เพราะเป็นทางออกเวลาลูกค้าจ่ายล่วงหน้าไม่ได้
     */
    protected function availableIntents(Branch $branch): array
    {
        $enabled = app(BranchSettingService::class)->storefrontMethods($branch);

        $map = [
            PaymentIntent::PromptPay->value => PaymentMethod::PromptPay->value,
            PaymentIntent::KhonLaKhrueng->value => PaymentMethod::KhonLaKhrueng->value,
            PaymentIntent::ThaiChuayThai->value => PaymentMethod::ThaiChuayThai->value,
        ];

        return collect(PaymentIntent::options())
            ->filter(function (array $option) use ($map, $enabled) {
                $method = $map[$option['value']] ?? null;

                return $method === null || in_array($method, $enabled, true);
            })
            ->values()
            ->all();
    }
}
