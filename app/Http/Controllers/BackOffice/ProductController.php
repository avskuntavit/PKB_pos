<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\DietTag;
use App\Enums\PrintGroup;
use App\Http\Controllers\Controller;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Services\ActivityLogger;
use App\Services\ImageService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = CurrentBranch::id();

        $products = Product::query()
            ->with(['category:id,name,color', 'modifierGroups:id,name,display_name,is_active'])
            ->forCatalog($branchId)
            ->withOverride($branchId)
            ->search($request->input('search'))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('BackOffice/Products/Index', [
            'products' => $products,
            // ส่งทุกหมวดรวมที่ปิดไว้ เพราะหน้าจัดการหมวดหมู่ต้องเห็นครบ
            // ส่วนช่องเลือกหมวดตอนเพิ่มเมนู หน้าบ้านกรองเฉพาะที่เปิดอยู่เอง
            'categories' => Category::forCatalog($branchId)
                ->withCount('products')
                ->orderBy('sort_order')
                ->get(['id', 'name', 'color', 'is_active', 'sort_order'])
                ->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'color' => $c->color,
                    'is_active' => $c->is_active,
                    'sort_order' => $c->sort_order,
                    'products_count' => $c->products_count,
                ]),
            // เซ็ตตัวเลือกทั้งหมดของสาขา ให้เลือกผูกจากฝั่งเมนูได้
            'modifierGroups' => ModifierGroup::forCatalog($branchId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'display_name', 'is_active'])
                ->map(fn (ModifierGroup $g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'shown_as' => $g->displayName(),
                    'is_active' => $g->is_active,
                ]),
            'printGroups' => PrintGroup::options(),
            // ป้ายข้อมูลอาหาร จัดกลุ่มมาให้แล้ว หน้าจอจึงไม่ต้องรู้ว่าป้ายไหนอยู่กลุ่มไหน
            'dietTags' => DietTag::grouped(),
            'imageSpec' => ImageService::spec('product'),

            /*
            | เมนูกลางแก้ได้เฉพาะเจ้าของ — ส่งสิทธิ์ลงไปให้หน้าจอปิดฟอร์มเอง
            |
            | ไม่ใช่การป้องกัน (ฝั่งเซิร์ฟเวอร์ guard() กันอยู่แล้ว) แต่เป็นการบอกล่วงหน้า
            | ปล่อยให้กรอกจนเสร็จแล้วค่อยเด้ง 403 คือเสียเวลาคนใช้ฟรี ๆ
            */
            'canEditCentral' => (bool) $request->user()?->isOwner(),
            'branchName' => CurrentBranch::getOrFail()->name,

            // กลุ่มโปรโมทบนหน้าสั่งอาหาร — จัดการผ่าน PromoController
            'promo' => [
                'title' => CurrentBranch::getOrFail()->promo_title ?: 'สำหรับคุณ',
                'featured_id' => Product::forCatalog($branchId)->where('is_featured', true)->value('id'),
                'items' => Product::forCatalog($branchId)
                    ->promoted()
                    ->get(['id', 'promo_label'])
                    ->map(fn (Product $p) => [
                        'product_id' => $p->id,
                        'promo_label' => (string) ($p->promo_label ?? ''),
                    ]),
                'max_slots' => PromoController::MAX_SLOTS,
            ],

            // รายชื่อเมนูทั้งสาขาแบบย่อ ใช้ในช่องเลือกของหน้าต่างโปรโมท
            // (ตารางด้านบนแบ่งหน้า เลยหยิบจากตรงนั้นไม่ได้)
            'allProducts' => Product::forCatalog($branchId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'image_path']),

            'filters' => $request->only('search', 'category_id'),
        ]);
    }

    public function store(Request $request, ImageService $images, ActivityLogger $logger): RedirectResponse
    {
        $data = $this->validated($request);
        $file = $request->file('image');
        unset($data['image'], $data['remove_image']);

        if ($file) {
            $data['image_path'] = $images->store($file, 'product');
        }

        $product = Product::create($data + ['branch_id' => CurrentBranch::id()]);
        $logger->log('product.update', $product, ['mode' => 'create']);

        return back()->with('success', 'เพิ่มสินค้าเรียบร้อย');
    }

    public function update(Request $request, Product $product, ImageService $images, ActivityLogger $logger): RedirectResponse
    {
        $this->guard($product);

        $data = $this->validated($request, $product->id);
        $file = $request->file('image');
        $remove = (bool) ($data['remove_image'] ?? false);
        unset($data['image'], $data['remove_image']);

        if ($file) {
            $data['image_path'] = $images->store($file, 'product', $product->image_path);
        } elseif ($remove) {
            $images->delete($product->image_path, 'product');
            $data['image_path'] = null;
        }

        $product->update($data);
        $logger->log('product.update', $product, ['mode' => 'update']);

        return back()->with('success', 'บันทึกการแก้ไขแล้ว');
    }

    public function destroy(Product $product, ActivityLogger $logger): RedirectResponse
    {
        $this->guard($product);

        // ไม่ลบไฟล์รูปทิ้ง เพราะเมนูใช้ soft delete กู้คืนได้
        // ถ้าลบรูปไปด้วย เมนูที่กู้กลับมาจะกลายเป็นรูปหาย
        $product->delete();
        $logger->log('product.update', $product, ['mode' => 'delete']);

        return back()->with('success', 'ลบสินค้าแล้ว');
    }

    /** ผูกเมนูนี้กับเซ็ตตัวเลือกไหนบ้าง — ฝั่งเมนูมองออกไปหาเซ็ต */
    public function syncModifierGroups(Request $request, Product $product, ActivityLogger $logger): RedirectResponse
    {
        $this->guard($product);

        $own = ModifierGroup::where('branch_id', CurrentBranch::id())->pluck('id')->all();

        $data = $request->validate([
            'items' => ['present', 'array', 'max:30'],
            'items.*.modifier_group_id' => ['required', Rule::in($own)],
            'items.*.is_active' => ['boolean'],
        ]);

        $sync = [];

        foreach (array_values($data['items']) as $i => $row) {
            $sync[(int) $row['modifier_group_id']] = [
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => $i,
            ];
        }

        $product->modifierGroups()->sync($sync);
        $logger->log('product.modifiers', $product, ['count' => count($sync)]);

        return back()->with('success', 'บันทึกเซ็ตตัวเลือกของเมนูแล้ว');
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        $branchId = CurrentBranch::id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // เลือกได้ทั้งหมวดกลางและหมวดของสาขานี้
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')
                    ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId)),
            ],
            // ตาราง products มี unique(branch_key, sku) อยู่ ถ้าไม่ตรวจตรงนี้จะเด้งเป็น SQL error
            // branch_key คือ branch_id ที่แปลง NULL เป็น 0 — เมนูกลางจึงชนกันเองได้ด้วย
            'sku' => [
                'nullable', 'string', 'max:40',
                Rule::unique('products', 'sku')
                    ->where(fn ($q) => $q->where('branch_key', $branchId ?? 0))
                    ->ignore($ignoreId),
            ],
            'barcode' => ['nullable', 'string', 'max:64'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'staff_price' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'unit' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'is_alcohol' => ['boolean'],
            'diet_tags' => ['nullable', 'array', 'max:9'],
            'diet_tags.*' => [Rule::enum(DietTag::class)],
            'track_stock' => ['boolean'],
            'is_open_price' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'print_group' => ['nullable', 'integer', 'between:1,3'],
            'image' => ImageService::rules('product'),
            'remove_image' => ['boolean'],
        ], [
            'sku.unique' => 'รหัส SKU นี้มีเมนูอื่นในสาขาใช้อยู่แล้ว',
            'staff_price.lte' => 'ราคาพนักงานต้องไม่สูงกว่าราคาขายปกติ',
            'image.max' => 'ไฟล์รูปใหญ่เกินขนาดที่กำหนด',
            'image.image' => 'ไฟล์ที่เลือกไม่ใช่รูปภาพ',
        ]);

        // ฟอร์มอัปโหลดไฟล์ส่งมาเป็น multipart ทุกค่าจึงเป็นสตริง
        // print_group cast เป็น enum ฐาน int ถ้าปล่อยเป็น '1' จะเสี่ยงพังตอน cast
        foreach (['sort_order', 'print_group'] as $key) {
            if (isset($data[$key]) && $data[$key] !== null) {
                $data[$key] = (int) $data[$key];
            }
        }

        /*
        | ไม่ส่ง diet_tags มาเลย = ไม่ได้ติ๊กสักอัน
        |
        | ฟอร์มเมนูส่งแบบ multipart เพราะมีไฟล์รูปติดไปด้วย และ FormData
        | ไม่มีทางแทน "อาร์เรย์ว่าง" ได้ ติ๊กออกหมดแล้วกดบันทึก คีย์นี้จึงหายไปทั้งคีย์
        |
        | ถ้าอ่านว่า "ไม่ได้ส่งมา = ไม่ต้องแก้" ป้ายเดิมจะติดค้างตลอดไป
        | และไม่มีทางเอาออกได้เลยจากหน้าจอ ซึ่งแย่เป็นพิเศษกับป้ายกลุ่ม "ต้องระวัง"
        | เพราะเมนูที่เปลี่ยนสูตรจนไม่มีถั่วแล้วจะยังเขียนว่ามีถั่วอยู่
        |
        | เมธอดนี้มีผู้เรียกเดียวคือฟอร์มเมนู ซึ่งมีตัวเลือกป้ายอยู่เสมอ
        | ตีความว่า "ไม่ส่งมา = ล้างทิ้ง" จึงตรงกับสิ่งที่คนกดตั้งใจ
        */
        $tags = array_values(array_unique($data['diet_tags'] ?? []));
        $data['diet_tags'] = $tags === [] ? null : $tags;

        return $data;
    }

    /**
     * ใครแก้เมนูนี้ได้
     *
     * เมนูกลางกระทบทุกสาขาพร้อมกัน จึงล็อกไว้ที่เจ้าของ
     * ผู้จัดการสาขาปรับได้แค่ราคา/เปิด-ปิดของสาขาตัวเองผ่าน saveOverride()
     * ซึ่งไม่แตะข้อมูลกลางเลย
     */
    protected function guard(Product $product): void
    {
        if ($product->branch_id === null) {
            abort_unless(auth()->user()?->isOwner(), 403, 'เมนูกลางแก้ได้เฉพาะเจ้าของ — สาขาปรับได้เฉพาะราคาและการเปิดขาย');

            return;
        }

        abort_unless($product->branch_id === CurrentBranch::id(), 403, 'เมนูนี้เป็นของสาขาอื่น');
    }

    /**
     * ค่าเฉพาะสาขาของเมนูหนึ่ง — ราคา เปิด/ปิดขาย ลำดับ จุดพิมพ์
     *
     * ผู้จัดการสาขาใช้ตัวนี้ได้ เพราะไม่แตะข้อมูลกลาง
     * ส่งค่าว่างมา = กลับไปใช้ค่ากลางของข้อนั้น (null ไม่ใช่ 0 — ราคา 0 คือแจกฟรี)
     */
    public function saveOverride(Request $request, Product $product, ActivityLogger $logger): RedirectResponse
    {
        $branchId = CurrentBranch::id();

        abort_unless($product->branch_id === null || $product->branch_id === $branchId, 403, 'เมนูนี้เป็นของสาขาอื่น');

        $data = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'print_group' => ['nullable', 'integer', 'between:1,3'],
        ]);

        $override = BranchProduct::firstOrNew([
            'branch_id' => $branchId,
            'product_id' => $product->id,
        ]);

        foreach (['price', 'is_active', 'sort_order', 'print_group'] as $field) {
            // ไม่ได้ส่ง key มา = ไม่แตะข้อนั้น / ส่งมาเป็น null = ล้างกลับไปใช้ค่ากลาง
            if ($request->exists($field)) {
                $override->{$field} = $data[$field] ?? null;
            }
        }

        // ไม่เหลืออะไรทับแล้ว เก็บแถวเปล่าไว้ก็รกฐานข้อมูล
        if ($override->isEmpty()) {
            $override->exists ? $override->delete() : null;
        } else {
            $override->save();
        }

        $logger->log('product.update', $product, ['mode' => 'branch_override', 'branch_id' => $branchId]);

        return back()->with('success', 'บันทึกค่าของสาขานี้แล้ว');
    }
}
