<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\BranchSettingService;
use App\Services\ImageService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** ตั้งค่าสาขา — รวมทุกอย่างที่ต่างกันได้ระหว่างสาขาไว้หน้าเดียว */
class BranchSettingController extends Controller
{
    public function edit(BranchSettingService $settings): Response
    {
        $branch = CurrentBranch::getOrFail();

        return Inertia::render('BackOffice/Settings/Branch', [
            'branch' => $branch->only([
                'id', 'code', 'name', 'tax_id', 'phone', 'address', 'intro',
                'vat_rate', 'vat_included', 'service_charge_rate', 'rounding_mode',
                'business_day_start', 'open_time', 'close_time', 'prep_minutes',
                'is_accepting_online_orders', 'award_points_online', 'qr_requires_open_table',
                'promptpay_id', 'promptpay_name',
                'staff_benefit_enabled', 'staff_benefit_monthly_cap', 'staff_benefit_exclude_alcohol',
                'restrict_alcohol_hours', 'alcohol_hours',
                'cover_path', 'logo_path', 'promo_title', 'theme_color',
            ]),
            'paymentMethods' => $settings->allMethodsFor($branch),
            // สเปกรูปไว้เขียนกำกับใต้ปุ่มอัปโหลด ใช้ค่าชุดเดียวกับฝั่งเซิร์ฟเวอร์
            'imageSpecs' => [
                'cover' => ImageService::spec('cover'),
                'logo' => ImageService::spec('logo'),
            ],
        ]);
    }

    public function update(Request $request, BranchSettingService $settings, ImageService $images): RedirectResponse
    {
        $branch = CurrentBranch::getOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'intro' => ['nullable', 'string', 'max:255'],

            'vat_rate' => ['required', 'numeric', 'between:0,30'],
            'vat_included' => ['boolean'],
            'service_charge_rate' => ['required', 'numeric', 'between:0,30'],
            'rounding_mode' => ['required', 'integer', 'between:0,3'],
            'business_day_start' => ['required', 'date_format:H:i'],

            'open_time' => ['required', 'date_format:H:i'],
            'close_time' => ['required', 'date_format:H:i'],
            'prep_minutes' => ['required', 'integer', 'between:1,240'],
            'is_accepting_online_orders' => ['boolean'],
            'award_points_online' => ['boolean'],
            'qr_requires_open_table' => ['boolean'],

            'cover' => ImageService::rules('cover'),
            'logo' => ImageService::rules('logo'),
            'remove_cover' => ['boolean'],
            'remove_logo' => ['boolean'],
            'theme_color' => ['nullable', 'string', 'max:30'],

            'promptpay_id' => ['nullable', 'string', 'max:20'],
            'promptpay_name' => ['nullable', 'string', 'max:60'],

            'staff_benefit_enabled' => ['boolean'],
            'staff_benefit_monthly_cap' => ['required', 'numeric', 'min:0'],
            'staff_benefit_exclude_alcohol' => ['boolean'],

            'restrict_alcohol_hours' => ['boolean'],
            'alcohol_hours' => ['array', 'max:5'],
            'alcohol_hours.*.from' => ['required_with:alcohol_hours', 'date_format:H:i'],
            'alcohol_hours.*.to' => ['required_with:alcohol_hours', 'date_format:H:i'],

            'payment_methods' => ['array'],
            'payment_methods.*.method' => ['required', 'string', 'max:30'],
            'payment_methods.*.is_enabled' => ['boolean'],
            'payment_methods.*.show_on_storefront' => ['boolean'],
            'payment_methods.*.sort_order' => ['nullable', 'integer'],
            'payment_methods.*.label_override' => ['nullable', 'string', 'max:60'],
            'payment_methods.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $methods = $data['payment_methods'] ?? [];
        unset($data['payment_methods'], $data['cover'], $data['logo'], $data['remove_cover'], $data['remove_logo']);

        // รูปปกและโลโก้ — อัปโหลดใหม่ทับของเดิม หรือกดเอาออก
        foreach (['cover' => 'cover_path', 'logo' => 'logo_path'] as $field => $column) {
            if ($file = $request->file($field)) {
                $data[$column] = $images->store($file, $field, $branch->{$column});
            } elseif ($request->boolean('remove_'.$field)) {
                $images->delete($branch->{$column}, $field);
                $data[$column] = null;
            }
        }

        // ต้องเหลืออย่างน้อยหนึ่งช่องทาง ไม่งั้นร้านรับเงินไม่ได้เลย
        if ($methods && ! collect($methods)->contains(fn ($m) => (bool) ($m['is_enabled'] ?? false))) {
            return back()->with('error', 'ต้องเปิดช่องทางชำระเงินอย่างน้อย 1 ช่องทาง');
        }

        $branch->update($data);
        $settings->saveMethods($branch, $methods);

        return back()->with('success', 'บันทึกการตั้งค่าสาขาแล้ว');
    }
}
