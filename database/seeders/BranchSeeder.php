<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchPaymentMethod;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            [
                'code' => 'LAN',
                'name' => 'ลานตะวัน ซอย เวิร์คพอยท์ ปทุมธานี',
                'intro' => '99/9, 4, ตำบลต.สวนพริกไทย, อำเภออ.เมืองปทุมธานี, จังหวัดจ.ปทุมธานี, 12000',
                'promptpay_id' => '0931918454',
                'promptpay_name' => 'FoodPOS Lantawan',
            ],
            [
                'code' => 'HOMFA',
                'name' => 'ห่มฟ้า ลำปาง',
                'intro' => 'สาขาใหม่ ที่จอดรถกว้าง สั่งล่วงหน้ารับได้เลย',
                'promptpay_id' => '0900000000',
                'promptpay_name' => 'FoodPOS Homfa',
            ],
        ];

        foreach ($branches as $i => $data) {
            $branch = Branch::create($data + [
                'tax_id' => '0105566'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'phone' => '02-000-000'.$i,
                'address' => 'กรุงเทพมหานคร',
                'vat_rate' => 7.00,
                'vat_included' => true,
                'service_charge_rate' => 0,
                'rounding_mode' => 0,
                'business_day_start' => '05:00:00',
                'is_accepting_online_orders' => true,
                'open_time' => '09:00:00',
                'close_time' => '21:00:00',
                'prep_minutes' => 20,
                'award_points_online' => true,

                // ร้านนี้ไม่รับเงินสด
                'staff_benefit_enabled' => true,
                'staff_benefit_monthly_cap' => 1500,
                'staff_benefit_exclude_alcohol' => true,
                'restrict_alcohol_hours' => false,
            ]);

            // รูปปก/โลโก้ตั้งต้น ถ้ามีไฟล์วางไว้ให้
            $images = array_filter([
                'cover_path' => $this->seedImage('cover', $branch->code),
                'logo_path' => $this->seedImage('logo', $branch->code),
            ]);

            if ($images !== []) {
                $branch->update($images);
            }

            // ช่องทางชำระเงินตามหน้างาน: พร้อมเพย์ + โครงการรัฐ ไม่มีเงินสด
            $methods = [
                ['method' => 'promptpay', 'enabled' => true, 'storefront' => true, 'note' => null],
                ['method' => 'khon_la_khrueng', 'enabled' => true, 'storefront' => true, 'note' => 'ออก QR ของโครงการที่เคาน์เตอร์'],
                ['method' => 'thai_chuay_thai', 'enabled' => true, 'storefront' => true, 'note' => 'ออก QR ของโครงการที่เคาน์เตอร์'],
                ['method' => 'transfer', 'enabled' => true, 'storefront' => false, 'note' => 'ขอสลิปทุกครั้ง'],
                ['method' => 'cash', 'enabled' => false, 'storefront' => false, 'note' => 'ร้านนี้ไม่รับเงินสด'],
                ['method' => 'credit_card', 'enabled' => false, 'storefront' => false, 'note' => null],
                ['method' => 'ewallet', 'enabled' => false, 'storefront' => false, 'note' => null],
                ['method' => 'delivery_app', 'enabled' => false, 'storefront' => false, 'note' => null],
            ];

            foreach ($methods as $order => $config) {
                BranchPaymentMethod::create([
                    'branch_id' => $branch->id,
                    'method' => $config['method'],
                    'is_enabled' => $config['enabled'],
                    'show_on_storefront' => $config['storefront'],
                    'sort_order' => $order,
                    'note' => $config['note'],
                ]);
            }

            if ($i === 0) {
                User::create([
                    'branch_id' => $branch->id,
                    'name' => 'เจ้าของร้าน',
                    'email' => 'owner@foodpos.test',
                    'password' => 'password',
                    'pin_code' => '1234',
                    'role' => UserRole::Owner,
                    'employee_code' => 'EMP001',
                ]);
            }

            User::create([
                'branch_id' => $branch->id,
                'name' => 'ผู้จัดการ '.$branch->code,
                'email' => 'manager'.($i + 1).'@foodpos.test',
                'password' => 'password',
                'pin_code' => '1111',
                'role' => UserRole::Manager,
                'employee_code' => 'EMP1'.$i.'0',
            ]);

            foreach (['สมชาย', 'มาลี'] as $n => $name) {
                User::create([
                    'branch_id' => $branch->id,
                    'name' => $name.' ('.$branch->code.')',
                    'email' => 'cashier'.$i.$n.'@foodpos.test',
                    'password' => 'password',
                    'pin_code' => '2222',
                    'role' => UserRole::Cashier,
                    'employee_code' => 'EMP2'.$i.$n,
                ]);
            }
        }
    }

    /**
     * รูปตั้งต้นของสาขา — วางไฟล์ไว้ที่ storage/app/public/branches/seed/
     * ตั้งชื่อตามรูปแบบ cover-{รหัสสาขา}.jpg และ logo-{รหัสสาขา}.jpg
     *
     * มีไว้เพราะ migrate:fresh --seed ล้างเฉพาะฐานข้อมูล ไม่ได้ลบไฟล์รูป
     * ที่หายไปทุกครั้งคือ "path ในตาราง" ไม่ใช่ตัวรูป พอมีไฟล์ตั้งต้นวางไว้
     * รีเซ็ตฐานข้อมูลกี่รอบรูปก็กลับมาเอง ไม่ต้องไปอัปโหลดใหม่ทุกครั้ง
     *
     * ไม่มีไฟล์ก็คืน null — เครื่องที่เพิ่ง clone มาจะได้ไม่มี path เสีย ๆ ค้างในฐานข้อมูล
     */
    protected function seedImage(string $kind, string $code): ?string
    {
        $file = "branches/seed/{$kind}-{$code}.jpg";

        return Storage::disk('public')->exists($file) ? '/storage/'.$file : null;
    }
}
