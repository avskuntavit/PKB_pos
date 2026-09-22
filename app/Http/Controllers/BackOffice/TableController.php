<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\FloorPlanObject;
use App\Models\Zone;
use App\Services\ActivityLogger;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TableController extends Controller
{
    public function index(): Response
    {
        $branchId = CurrentBranch::id();

        return Inertia::render('BackOffice/Tables/Index', [
            'zones' => Zone::where('branch_id', $branchId)->orderBy('sort_order')->get(),
            'tables' => DiningTable::with('zone:id,name')
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->get(),
            'layoutObjects' => FloorPlanObject::with('zone:id,name')
                ->where('branch_id', $branchId)
                ->orderBy('id')
                ->get(),
        ]);
    }

    /** หน้าสำหรับพิมพ์ QR ตั้งโต๊ะ — QR สร้างฝั่งเบราว์เซอร์ ไม่ต้องลง package เพิ่มในฝั่ง PHP */
    public function qrCodes(): Response
    {
        $branch = CurrentBranch::getOrFail();
        $branchId = $branch->id;

        return Inertia::render('BackOffice/Tables/QrCodes', [
            'branch' => $branch->only(['id', 'name', 'code']),
            // ลิงก์เมนูของร้านนี้ตรง ๆ — สแกนแล้วระบบเลือกร้านให้เองโดยไม่ต้องถาม
            'stationUrl' => route('storefront.menu.branch', $branch->code),
            'tables' => DiningTable::with('zone:id,name')
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (DiningTable $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'zone' => $t->zone?->name,
                    'seats' => $t->seats,
                    'url' => $t->qrUrl(),
                ]),
        ]);
    }

    /** ออก token ใหม่ให้โต๊ะ — ใช้เมื่อ QR เดิมหลุดออกไปข้างนอก */
    public function regenerateQr(DiningTable $table, ActivityLogger $logger): RedirectResponse
    {
        abort_unless($table->branch_id === CurrentBranch::id(), 403);

        $table->update(['qr_token' => \Illuminate\Support\Str::random(40)]);
        $logger->log('table.update', $table, ['mode' => 'regenerate_qr']);

        return back()->with('success', 'ออก QR ใหม่ให้โต๊ะ '.$table->name.' แล้ว — อย่าลืมพิมพ์ไปเปลี่ยนที่โต๊ะ');
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $branchId = CurrentBranch::id();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:20',
                Rule::unique('dining_tables', 'name')->where('branch_id', $branchId),
            ],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'seats' => ['required', 'integer', 'min:1', 'max:50'],
            'pos_x' => ['nullable', 'integer', 'min:0'],
            'pos_y' => ['nullable', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:40', 'max:800'],
            'height' => ['nullable', 'integer', 'min:40', 'max:800'],
            'shape' => ['nullable', 'in:square,rectangle,circle'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['width'] = $data['width'] ?? 80;
        $data['height'] = $data['height'] ?? 80;
        $data['shape'] = $data['shape'] ?? 'square';

        $table = DiningTable::create($data + ['branch_id' => $branchId]);
        $logger->log('table.update', $table, ['mode' => 'create']);

        return back()->with('success', 'เพิ่มโต๊ะ '.$table->name.' แล้ว');
    }

    public function update(Request $request, DiningTable $table, ActivityLogger $logger): RedirectResponse
    {
        abort_unless((int) $table->branch_id === (int) CurrentBranch::id(), 403);
        $branchId = CurrentBranch::id();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:20',
                Rule::unique('dining_tables', 'name')
                    ->where('branch_id', $branchId)
                    ->ignore($table->id),
            ],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'seats' => ['required', 'integer', 'min:1', 'max:50'],
            'width' => ['nullable', 'integer', 'min:40', 'max:800'],
            'height' => ['nullable', 'integer', 'min:40', 'max:800'],
            'shape' => ['nullable', 'in:square,rectangle,circle'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $table->update($data);
        $logger->log('table.update', $table, ['mode' => 'update']);

        return back()->with('success', 'บันทึกการแก้ไขโต๊ะ '.$table->name.' แล้ว');
    }

    public function destroy(DiningTable $table, ActivityLogger $logger): RedirectResponse
    {
        abort_unless((int) $table->branch_id === (int) CurrentBranch::id(), 403);

        if ($table->openOrder()->exists()) {
            return back()->with('error', 'ลบโต๊ะ '.$table->name.' ไม่ได้ เนื่องจากยังมีบิลเปิดค้างอยู่');
        }

        $tableName = $table->name;
        $table->delete();
        $logger->log('table.update', $table, ['mode' => 'delete']);

        return back()->with('success', 'ลบโต๊ะ '.$tableName.' แล้ว');
    }

    /* ---------- อ็อบเจ็กต์ผังร้าน (เคาน์เตอร์, บาร์, แคชเชียร์ ฯลฯ) ---------- */

    public function storeObject(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $branchId = CurrentBranch::id();

        $data = $request->validate([
            'type' => ['required', 'string', 'in:cashier,bar,kitchen,entrance,restroom,pillar,wall,custom'],
            'name' => ['required', 'string', 'max:60'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'pos_x' => ['nullable', 'integer', 'min:0'],
            'pos_y' => ['nullable', 'integer', 'min:0'],
            'width' => ['required', 'integer', 'min:20', 'max:1200'],
            'height' => ['required', 'integer', 'min:20', 'max:1200'],
            'color' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:40'],
        ]);

        $object = FloorPlanObject::create($data + ['branch_id' => $branchId]);
        $logger->log('floor_plan.object', $object, ['mode' => 'create']);

        return back()->with('success', 'เพิ่ม '.$object->name.' ในผังร้านแล้ว');
    }

    public function updateObject(Request $request, FloorPlanObject $object, ActivityLogger $logger): RedirectResponse
    {
        abort_unless((int) $object->branch_id === (int) CurrentBranch::id(), 403);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:cashier,bar,kitchen,entrance,restroom,pillar,wall,custom'],
            'name' => ['required', 'string', 'max:60'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'width' => ['required', 'integer', 'min:20', 'max:1200'],
            'height' => ['required', 'integer', 'min:20', 'max:1200'],
            'color' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $object->update($data);
        $logger->log('floor_plan.object', $object, ['mode' => 'update']);

        return back()->with('success', 'บันทึกการแก้ไข '.$object->name.' แล้ว');
    }

    public function destroyObject(FloorPlanObject $object, ActivityLogger $logger): RedirectResponse
    {
        abort_unless((int) $object->branch_id === (int) CurrentBranch::id(), 403);

        $name = $object->name;
        $object->delete();
        $logger->log('floor_plan.object', $object, ['mode' => 'delete']);

        return back()->with('success', 'ลบ '.$name.' ออกจากผังร้านแล้ว');
    }

    /* ---------- โซน ---------- */

    public function storeZone(Request $request): RedirectResponse
    {
        $branchId = CurrentBranch::id();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        $maxSort = Zone::where('branch_id', $branchId)->max('sort_order') ?? 0;
        Zone::create($data + ['branch_id' => $branchId, 'sort_order' => $maxSort + 1]);

        return back()->with('success', 'เพิ่มโซน '.$data['name'].' แล้ว');
    }

    /* ---------- บันทึกผังรวม ---------- */

    /** ลากวางผังโต๊ะและอ็อบเจ็กต์แล้วบันทึกพิกัดและขนาดทีเดียว */
    public function updateLayout(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'tables' => ['nullable', 'array'],
            'tables.*.id' => ['required', 'exists:dining_tables,id'],
            'tables.*.pos_x' => ['required', 'integer', 'min:0'],
            'tables.*.pos_y' => ['required', 'integer', 'min:0'],
            'tables.*.width' => ['nullable', 'integer', 'min:40', 'max:800'],
            'tables.*.height' => ['nullable', 'integer', 'min:40', 'max:800'],
            'tables.*.shape' => ['nullable', 'in:square,rectangle,circle'],
            'objects' => ['nullable', 'array'],
            'objects.*.id' => ['required', 'exists:floor_plan_objects,id'],
            'objects.*.pos_x' => ['required', 'integer', 'min:0'],
            'objects.*.pos_y' => ['required', 'integer', 'min:0'],
            'objects.*.width' => ['nullable', 'integer', 'min:20', 'max:1200'],
            'objects.*.height' => ['nullable', 'integer', 'min:20', 'max:1200'],
        ]);

        $branchId = CurrentBranch::id();

        if (! empty($data['tables'])) {
            foreach ($data['tables'] as $row) {
                $payload = ['pos_x' => $row['pos_x'], 'pos_y' => $row['pos_y']];
                if (isset($row['width'])) {
                    $payload['width'] = $row['width'];
                }
                if (isset($row['height'])) {
                    $payload['height'] = $row['height'];
                }
                if (isset($row['shape'])) {
                    $payload['shape'] = $row['shape'];
                }

                DiningTable::where('id', $row['id'])
                    ->where('branch_id', $branchId)
                    ->update($payload);
            }
        }

        if (! empty($data['objects'])) {
            foreach ($data['objects'] as $row) {
                $payload = ['pos_x' => $row['pos_x'], 'pos_y' => $row['pos_y']];
                if (isset($row['width'])) {
                    $payload['width'] = $row['width'];
                }
                if (isset($row['height'])) {
                    $payload['height'] = $row['height'];
                }

                FloorPlanObject::where('id', $row['id'])
                    ->where('branch_id', $branchId)
                    ->update($payload);
            }
        }

        $logger->log('table.update', null, [
            'mode' => 'layout',
            'tables_count' => count($data['tables'] ?? []),
            'objects_count' => count($data['objects'] ?? []),
        ]);

        return back()->with('success', 'บันทึกผังร้านเรียบร้อยแล้ว');
    }
}
