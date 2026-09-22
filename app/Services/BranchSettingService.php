<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\BranchPaymentMethod;
use Illuminate\Support\Collection;

/**
 * ช่องทางชำระเงินที่แต่ละสาขาเปิดรับ
 *
 * สาขาที่ยังไม่เคยตั้งค่า จะถือว่าเปิดทุกช่องทาง
 * ตั้งใจให้เป็นแบบนี้เพื่อไม่ให้ร้านที่เพิ่งติดตั้งรับเงินไม่ได้เพราะลืมตั้งค่า
 * แต่ Seeder จะตั้งค่าเริ่มต้นให้ตรงกับหน้างานจริง (ร้านนี้ไม่รับเงินสด)
 */
class BranchSettingService
{
    /** @return Collection<int, array> ช่องทางทั้งหมดพร้อมสถานะเปิด/ปิด สำหรับหน้าตั้งค่า */
    public function allMethodsFor(Branch $branch): Collection
    {
        $configured = BranchPaymentMethod::where('branch_id', $branch->id)
            ->get()
            ->keyBy(fn (BranchPaymentMethod $m) => $m->method->value);

        $hasAnyConfig = $configured->isNotEmpty();

        return collect(PaymentMethod::cases())->map(function (PaymentMethod $method) use ($configured, $hasAnyConfig) {
            $row = $configured->get($method->value);

            return [
                'method' => $method->value,
                'label' => $method->label(),
                'is_enabled' => $row ? (bool) $row->is_enabled : ! $hasAnyConfig,
                'show_on_storefront' => $row ? (bool) $row->show_on_storefront : ! $hasAnyConfig,
                'sort_order' => $row?->sort_order ?? 0,
                'label_override' => $row?->label_override,
                'note' => $row?->note,
                'is_government_scheme' => $method->isGovernmentScheme(),
            ];
        })->sortBy('sort_order')->values();
    }

    /** ช่องทางที่ใช้ได้จริงตอนรับเงินที่หน้าร้าน */
    public function enabledMethods(Branch $branch): array
    {
        return $this->allMethodsFor($branch)
            ->filter(fn (array $m) => $m['is_enabled'])
            ->map(fn (array $m) => ['value' => $m['method'], 'label' => $m['label_override'] ?: $m['label'], 'note' => $m['note']])
            ->values()
            ->all();
    }

    /** ช่องทางที่ให้ลูกค้าเลือกได้ตอนสั่งล่วงหน้า */
    public function storefrontMethods(Branch $branch): array
    {
        return $this->allMethodsFor($branch)
            ->filter(fn (array $m) => $m['is_enabled'] && $m['show_on_storefront'])
            ->map(fn (array $m) => $m['method'])
            ->all();
    }

    public function isMethodAllowed(Branch $branch, PaymentMethod $method): bool
    {
        return collect($this->enabledMethods($branch))
            ->contains(fn (array $m) => $m['value'] === $method->value);
    }

    /**
     * บันทึกการตั้งค่าช่องทางทั้งชุด
     *
     * @param  array<int, array{method: string, is_enabled: bool, show_on_storefront: bool, sort_order?: int, label_override?: string|null, note?: string|null}>  $rows
     */
    public function saveMethods(Branch $branch, array $rows): void
    {
        foreach ($rows as $index => $row) {
            $method = PaymentMethod::tryFrom($row['method']);

            if (! $method) {
                continue;
            }

            BranchPaymentMethod::updateOrCreate(
                ['branch_id' => $branch->id, 'method' => $method->value],
                [
                    'is_enabled' => (bool) ($row['is_enabled'] ?? false),
                    'show_on_storefront' => (bool) ($row['show_on_storefront'] ?? false),
                    'sort_order' => (int) ($row['sort_order'] ?? $index),
                    'label_override' => $row['label_override'] ?? null,
                    'note' => $row['note'] ?? null,
                ]
            );
        }
    }
}
