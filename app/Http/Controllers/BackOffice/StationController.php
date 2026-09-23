<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\OrderStatus;
use App\Enums\StoreType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchImage;
use App\Models\Order;
use App\Services\ImageService;
use App\Services\StationProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * เปิดสถานีใหม่ และแก้ข้อมูลแบรนด์ของทุกสถานี
 *
 * ต่างจาก BranchSettingController ตรงขอบเขต:
 *   • BranchSettingController = ค่าตั้งการขายของ **สถานีที่กำลังเปิดดูอยู่**
 *     (VAT ค่าบริการ ตัดรอบวัน ช่องทางชำระเงิน) ผู้จัดการสาขาแก้ของตัวเองได้
 *   • หน้านี้ = โครงสร้างของกิจการ — เปิดสถานีใหม่ ปิดสถานีเก่า และข้อมูลแบรนด์ของ
 *     **ทุกสถานี** จึงต้องมีสิทธิ์ station.manage ซึ่งค่าตั้งต้นมีแค่เจ้าของระบบ
 *
 * ── ทำไมหน้านี้อ่าน Branch::query() ตรง ๆ ไม่ผ่าน accessibleBranchIds() ──
 * เพราะ accessibleBranchIds() คืนเฉพาะสถานีที่ยัง is_active
 * ถ้าใช้ตัวนั้น พอปิดสถานีไปแล้วจะเปิดกลับมาไม่ได้อีกเลย — สถานีจะหายไปจากหน้าจอตัวเอง
 * ด่านกันที่แท้จริงของหน้านี้คือสิทธิ์ station.manage ที่ผูกไว้ที่ route
 */
class StationController extends Controller
{
    /** กันอัปโหลดรูปบรรยากาศจนล้น — หน้าร้านแสดงไม่ไหวและเปลืองที่เก็บเปล่า ๆ */
    public const MAX_IMAGES = 12;

    public function index(): Response
    {
        $branches = Branch::withCount(['diningTables', 'users', 'products', 'images'])
            ->with('images')
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get()
            ->map(fn (Branch $branch) => $this->payload($branch));

        return Inertia::render('BackOffice/Stations/Index', [
            'stations' => $branches,
            'storeTypes' => StoreType::options(),
            'copyOptions' => collect(StationProvisioner::COPYABLE)
                ->map(fn (string $key) => [
                    'value' => $key,
                    'label' => StationProvisioner::copyLabels()[$key],
                ])
                ->values(),
            'imageSpecs' => [
                'logo' => ImageService::spec('logo'),
                'cover' => ImageService::spec('cover'),
                'gallery' => ImageService::spec('gallery'),
            ],
            'maxImages' => self::MAX_IMAGES,
        ]);
    }

    /** เปิดสถานีใหม่ */
    public function store(Request $request, StationProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            ...$this->brandRules(),
            /*
            | รหัสสถานีอยู่ใน URL ที่ลูกค้าเปิด (/order/{code}) และจอคิว (/queue/{code})
            | จึงบังคับเป็น a-z A-Z 0-9 _ - เท่านั้น
            | รหัสภาษาไทยเคยทำให้ชื่อไฟล์ส่งออกบัญชีของสองสถานีชนกันมาแล้ว
            */
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('branches', 'code')],
            'copy_from' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'copy' => ['array'],
            'copy.*' => [Rule::in(StationProvisioner::COPYABLE)],
        ], [
            'code.regex' => 'รหัสสถานีใช้ได้เฉพาะ a-z A-Z 0-9 _ และ - (ห้ามภาษาไทยและเว้นวรรค)',
        ]);

        $source = isset($data['copy_from']) ? Branch::find($data['copy_from']) : null;
        $copy = $data['copy'] ?? [];

        unset($data['copy_from'], $data['copy']);

        $result = $provisioner->create($data, $source, $copy);

        return redirect()
            ->route('backoffice.stations.index')
            ->with('success', $this->createdMessage($result['branch'], $source, $result['copied']));
    }

    /** แก้ข้อมูลแบรนด์ของสถานีไหนก็ได้ — รหัสสถานีแก้ไม่ได้ */
    public function update(Request $request, Branch $branch, ImageService $images): RedirectResponse
    {
        $data = $request->validate([
            ...$this->brandRules(),
            'logo' => ImageService::rules('logo'),
            'cover' => ImageService::rules('cover'),
            'remove_logo' => ['boolean'],
            'remove_cover' => ['boolean'],
        ]);

        unset($data['logo'], $data['cover'], $data['remove_logo'], $data['remove_cover']);

        foreach (['logo' => 'logo_path', 'cover' => 'cover_path'] as $field => $column) {
            if ($file = $request->file($field)) {
                $data[$column] = $images->store($file, $field, $branch->{$column});
            } elseif ($request->boolean('remove_'.$field)) {
                $images->delete($branch->{$column}, $field);
                $data[$column] = null;
            }
        }

        $branch->update($data);

        return back()->with('success', 'บันทึกข้อมูล'.$branch->name.'แล้ว');
    }

    /** เปิด/ปิดใช้งานสถานี — ระบบนี้ไม่มีการลบสถานี เพราะบิลเก่าต้องอ่านย้อนหลังได้ตลอด */
    public function toggleActive(Request $request, Branch $branch): RedirectResponse
    {
        $activating = ! $branch->is_active;

        if (! $activating) {
            $othersActive = Branch::where('is_active', true)
                ->where('id', '!=', $branch->id)
                ->exists();

            if (! $othersActive) {
                return back()->with('error', 'ปิดสถานีสุดท้ายไม่ได้ — ต้องเหลือสถานีที่เปิดใช้งานอย่างน้อยหนึ่งแห่ง');
            }

            // ปิดสถานีทั้งที่ยังมีบิลค้าง = บิลนั้นกลายเป็นบิลกำพร้าที่ไม่มีใครปิดได้
            $openBills = Order::where('branch_id', $branch->id)
                ->where('status', OrderStatus::Open->value)
                ->count();

            if ($openBills > 0) {
                return back()->with('error', "ปิดไม่ได้ — {$branch->name} ยังมีบิลเปิดค้างอยู่ {$openBills} บิล ต้องปิดบิลให้หมดก่อน");
            }
        }

        $branch->update(['is_active' => $activating]);

        /*
        | ปิดสถานีที่ตัวเองกำลังเปิดดูอยู่ ต้องย้าย session ไปสถานีอื่นทันที
        |
        | เพราะ accessibleBranchIds() คืนเฉพาะสถานีที่ is_active
        | request ถัดไป ResolveCurrentBranch จะเห็นว่า branch_id ใน session ไม่อยู่ในรายการ
        | แล้วเด้ง 403 — เจ้าของระบบจะเข้าหลังบ้านไม่ได้เลยจนกว่าจะล้าง session
        */
        if (! $activating && (int) $request->session()->get('branch_id') === $branch->id) {
            $fallback = Branch::where('is_active', true)->orderBy('id')->value('id');
            $request->session()->put('branch_id', $fallback);
        }

        return back()->with('success', $activating
            ? "เปิดใช้งาน{$branch->name}แล้ว"
            : "ปิดใช้งาน{$branch->name}แล้ว ข้อมูลเก่ายังอยู่ครบและเปิดกลับมาได้ตลอด");
    }

    public function storeImage(Request $request, Branch $branch, ImageService $images): RedirectResponse
    {
        $data = $request->validate([
            'image' => ImageService::rules('gallery', required: true),
            'caption' => ['nullable', 'string', 'max:120'],
        ]);

        if ($branch->images()->count() >= self::MAX_IMAGES) {
            return back()->with('error', 'รูปบรรยากาศเก็บได้สูงสุด '.self::MAX_IMAGES.' รูปต่อสถานี');
        }

        BranchImage::create([
            'branch_id' => $branch->id,
            'path' => $images->store($request->file('image'), 'gallery'),
            'caption' => $data['caption'] ?? null,
            // ต่อท้ายเสมอ ไม่แทรกกลาง — คนอัปโหลดคาดว่ารูปใหม่จะอยู่ท้ายแถว
            'sort_order' => (int) $branch->images()->max('sort_order') + 1,
        ]);

        return back()->with('success', 'เพิ่มรูปแล้ว');
    }

    public function destroyImage(Branch $branch, BranchImage $image, ImageService $images): RedirectResponse
    {
        abort_unless($image->branch_id === $branch->id, 403, 'รูปนี้ไม่ใช่ของสถานีนี้');

        $images->delete($image->path, 'gallery');
        $image->delete();

        return back()->with('success', 'ลบรูปแล้ว');
    }

    public function reorderImages(Request $request, Branch $branch): RedirectResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'max:'.self::MAX_IMAGES],
            'order.*' => ['integer'],
        ]);

        // อ่าน id ที่เป็นของสถานีนี้จริงมาก่อน แล้วค่อยเรียงตามที่ส่งมา
        // ถ้าเชื่อ id ที่ส่งมาตรง ๆ จะย้ายรูปของสถานีอื่นได้
        $owned = $branch->images()->pluck('id')->all();

        foreach ($data['order'] as $position => $id) {
            if (in_array((int) $id, $owned, true)) {
                BranchImage::where('id', $id)->update(['sort_order' => $position]);
            }
        }

        return back()->with('success', 'จัดลำดับรูปแล้ว');
    }

    /** ช่องข้อมูลแบรนด์ที่ใช้ร่วมกันระหว่างตอนสร้างและตอนแก้ */
    protected function brandRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'store_type' => ['nullable', Rule::enum(StoreType::class)],
            // เก็บเป็นข้อความอิสระ รับได้ทั้ง #2a78d6 และชื่อสีของ CSS
            'theme_color' => ['nullable', 'string', 'max:30'],
            'promo_title' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'intro' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    protected function payload(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'store_type' => $branch->store_type?->value,
            'store_type_label' => $branch->storeTypeLabel(),
            'theme_color' => $branch->theme_color,
            'promo_title' => $branch->promo_title,
            'phone' => $branch->phone,
            'address' => $branch->address,
            'intro' => $branch->intro,
            // ส่งเป็น string ตาม cast decimal — หน้าเว็บเอาไปใส่ช่องกรอกได้ตรง ๆ โดยไม่เพี้ยนท้ายทศนิยม
            'latitude' => $branch->latitude,
            'longitude' => $branch->longitude,
            'has_coordinates' => $branch->hasCoordinates(),
            'logo_path' => $branch->logo_path,
            'cover_path' => $branch->cover_path,
            'is_active' => (bool) $branch->is_active,
            'counts' => [
                'tables' => (int) $branch->dining_tables_count,
                'staff' => (int) $branch->users_count,
                // นับเฉพาะเมนูเฉพาะสถานี — เมนูกลางทุกสถานีเห็นเท่ากันอยู่แล้ว นับไปก็ไม่บอกอะไร
                'own_products' => (int) $branch->products_count,
                'images' => (int) $branch->images_count,
            ],
            'images' => $branch->images->map(fn (BranchImage $image) => [
                'id' => $image->id,
                'path' => $image->path,
                'caption' => $image->caption,
            ])->values(),
        ];
    }

    /** ข้อความหลังสร้างเสร็จ — บอกให้ชัดว่าคัดลอกอะไรมาให้บ้าง ไม่ใช่แค่ "สำเร็จ" */
    protected function createdMessage(Branch $branch, ?Branch $source, array $copied): string
    {
        $message = "เปิด{$branch->name} (รหัส {$branch->code}) แล้ว";

        $parts = array_filter([
            isset($copied['recipes']) ? "สูตร {$copied['recipes']} บรรทัด" : null,
            isset($copied['stock_items']) ? "ต้นทุนวัตถุดิบ {$copied['stock_items']} ชิ้น" : null,
            isset($copied['menu_overrides']) ? "ราคาเฉพาะสถานี {$copied['menu_overrides']} เมนู" : null,
            isset($copied['tables']) ? "โต๊ะ {$copied['tables']} ตัว" : null,
            isset($copied['payment_methods']) ? "ช่องทางชำระเงิน {$copied['payment_methods']} ช่องทาง" : null,
        ]);

        if ($source && $parts) {
            $message .= ' · คัดลอกจาก'.$source->name.': '.implode(' / ', $parts);
        }

        return $message.' · ยอดคงเหลือในคลังเริ่มที่ 0 ต้องรับของเข้าเอง';
    }
}
