<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\PaymentProvider;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\PaymentCharge;
use App\Models\PaymentProviderAccount;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentChargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * หน้าตั้งค่าบัญชีรับเงินผ่าน QR
 *
 * ── ทำไมชุดนี้เน้นเรื่องกุญแจมากกว่าเรื่องหน้าตา ─────────────────────────
 * นี่คือหน้าเดียวในระบบที่เก็บกุญแจของเกตเวย์ กุญแจที่หลุดออกไปเรียกคืนไม่ได้
 * ต้องไปขอออกใหม่ และระหว่างนั้นใครที่ถือกุญแจอยู่รับเงินในชื่อร้านได้
 *
 * ── สี่ข้อที่ชุดนี้คุมเป็นหลัก ───────────────────────────────────────────
 * 1. ค่าจริงของกุญแจต้องไม่ถูกส่งออกไปที่เบราว์เซอร์เลย
 * 2. ช่องว่างตอนบันทึก = ไม่แก้ ไม่ใช่ลบ
 * 3. เปิดใช้งานได้เจ้าเดียว และเปิดไม่ได้ถ้ายังไม่มีตัวเชื่อม
 * 4. ปิดเจ้าเดิมแล้ว QR ที่ค้างของเจ้านั้นต้องยังไล่ถามต่อได้
 */
class PaymentProviderSettingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = $this->makeBranch('PP1');
        $this->owner = $this->makeUser(UserRole::Owner, 'pp-owner@test.local');

        $this->actingAs($this->owner);
    }

    /* ---------- สิทธิ์ ---------- */

    public function test_only_the_owner_can_open_this_page(): void
    {
        // กุญแจรับเงินไม่ใช่ของที่ผู้จัดการต้องแตะ — branch.settings มีแต่เจ้าของร้าน
        $this->actingAs($this->makeUser(UserRole::Manager, 'pp-manager@test.local'))
            ->get('/backoffice/settings/payment-providers')
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->get('/backoffice/settings/payment-providers')
            ->assertOk();
    }

    public function test_a_cashier_cannot_save_a_gateway_account(): void
    {
        $this->actingAs($this->makeUser(UserRole::Cashier, 'pp-cashier@test.local'))
            ->put('/backoffice/settings/payment-providers/beam', [
                'mode' => 'test',
                'credentials' => ['merchant_id' => 'm', 'api_key' => 'k'],
            ])
            ->assertForbidden();

        $this->assertSame(0, PaymentProviderAccount::count());
    }

    /* ---------- กุญแจต้องไม่หลุดออกไปที่หน้าจอ ---------- */

    public function test_the_page_never_sends_the_real_keys_to_the_browser(): void
    {
        /*
        | ด่านที่สำคัญที่สุดของหน้านี้
        |
        | กุญแจที่ถูกส่งไปแสดงบนหน้าเว็บจะไปอยู่ในซอร์สของหน้า ใน history
        | ของเบราว์เซอร์ และใน log ของ proxy ทุกตัวที่อยู่ระหว่างทาง
        */
        /*
        | ค่ากุญแจในเทสต์นี้เป็น ASCII ทั้งหมด **โดยตั้งใจ**
        |
        | Inertia ฝังข้อมูลหน้าเป็น JSON ใน data-page ด้วย json_encode ที่ escape
        | อักษรไทยเป็น \uXXXX ถ้าใช้ค่าภาษาไทย assertDontSee จะผ่านทุกครั้ง
        | แม้ค่าจริงจะถูกส่งไปแล้ว — ด่านจะกลายเป็นด่านที่ปิดอยู่
        */
        $this->account(PaymentProvider::Beam, credentials: [
            'merchant_id' => 'MERCHANT-MUST-NOT-LEAK-0001',
            'api_key' => 'sk_live_MUST_NOT_LEAK_1234',
        ]);

        $response = $this->get('/backoffice/settings/payment-providers')->assertOk();

        $response->assertDontSee('MERCHANT-MUST-NOT-LEAK-0001', false);
        $response->assertDontSee('sk_live_MUST_NOT_LEAK_1234', false);

        // แต่ต้องเห็นสี่ตัวท้าย เพื่อให้คนดูยืนยันได้ว่าใส่ตัวไหนไว้
        $response->assertSee('1234', false);
    }

    public function test_the_masked_hint_shows_only_the_last_four_characters(): void
    {
        $account = $this->account(PaymentProvider::Beam, credentials: [
            'merchant_id' => 'm1',
            'api_key' => 'abcdefghijklmnop',
        ]);

        $hint = (string) $account->maskedCredentials()['api_key']['hint'];

        $this->assertStringEndsWith('mnop', $hint);
        $this->assertStringNotContainsString('abcdefghijkl', $hint);
        $this->assertTrue($account->maskedCredentials()['api_key']['filled']);
    }

    public function test_the_keys_are_encrypted_in_the_table(): void
    {
        // ค่า ASCII ด้วยเหตุผลเดียวกับด่านบน — cast 'encrypted:array' เรียก json_encode
        // ที่ escape อักษรไทย ถ้าใช้ค่าไทย ด่านนี้จะผ่านแม้คอลัมน์จะไม่ถูกเข้ารหัสเลย
        $this->account(PaymentProvider::Beam, credentials: ['merchant_id' => 'm', 'api_key' => 'TOP_SECRET_VALUE_9999']);

        $raw = (string) DB::table('payment_provider_accounts')->value('credentials');

        $this->assertStringNotContainsString('TOP_SECRET_VALUE_9999', $raw);
    }

    public function test_the_activity_log_records_which_field_changed_not_what_it_became(): void
    {
        // log ที่มีกุญแจอยู่ข้างในคือกุญแจที่หลุดไปอีกที่หนึ่ง
        $this->put('/backoffice/settings/payment-providers/beam', [
            'mode' => 'test',
            'credentials' => ['merchant_id' => 'm1', 'api_key' => 'ห้ามโผล่ใน log'],
        ])->assertRedirect();

        $log = ActivityLog::where('action', 'payment_account.update')->firstOrFail();

        $this->assertSame(['merchant_id', 'api_key'], $log->meta['changed_keys']);
        $this->assertStringNotContainsString('ห้ามโผล่ใน log', json_encode($log->meta, JSON_UNESCAPED_UNICODE));
    }

    /* ---------- ช่องว่าง = ไม่แก้ ---------- */

    public function test_saving_with_an_empty_field_keeps_the_key_that_was_there(): void
    {
        /*
        | หน้าจอไม่มีค่าเดิมให้แสดง จึงส่งช่องว่างมาทุกครั้งที่ไม่ได้พิมพ์อะไรใหม่
        | ถ้าตีความว่า "ลบ" การกดบันทึกเพื่อแก้แค่หมายเหตุจะล้างกุญแจทั้งชุด
        */
        $this->account(PaymentProvider::Beam, credentials: ['merchant_id' => 'm1', 'api_key' => 'k1']);

        $this->put('/backoffice/settings/payment-providers/beam', [
            'mode' => 'test',
            'note' => 'แก้แค่หมายเหตุ',
            'credentials' => ['merchant_id' => '', 'api_key' => ''],
        ])->assertRedirect();

        $account = PaymentProviderAccount::firstOrFail();

        $this->assertSame('k1', $account->secret('api_key'), 'กุญแจต้องยังอยู่');
        $this->assertSame('แก้แค่หมายเหตุ', $account->note);
    }

    public function test_a_field_that_was_typed_in_replaces_the_old_value(): void
    {
        $this->account(PaymentProvider::Beam, credentials: ['merchant_id' => 'm1', 'api_key' => 'เก่า']);

        $this->put('/backoffice/settings/payment-providers/beam', [
            'mode' => 'live',
            'credentials' => ['merchant_id' => '', 'api_key' => 'ใหม่'],
        ])->assertRedirect();

        $account = PaymentProviderAccount::firstOrFail();

        $this->assertSame('ใหม่', $account->secret('api_key'));
        $this->assertSame('m1', $account->secret('merchant_id'), 'ช่องที่ไม่ได้แก้ต้องไม่ถูกแตะ');
        $this->assertSame('live', $account->mode);
    }

    public function test_deleting_the_keys_needs_its_own_action(): void
    {
        // ลบต้องเป็นการกระทำของตัวเอง ไม่ใช่ผลข้างเคียงของการกดบันทึก
        $account = $this->account(PaymentProvider::Beam, credentials: ['merchant_id' => 'm', 'api_key' => 'k'], active: true);

        $this->delete('/backoffice/settings/payment-providers/beam/credentials')->assertRedirect();

        $account->refresh();

        $this->assertNull($account->credentials);
        $this->assertFalse($account->is_active, 'บัญชีที่ไม่มีกุญแจเปิดไว้ก็ออก QR ไม่ได้อยู่ดี');
    }

    public function test_a_brand_new_account_starts_switched_off(): void
    {
        $this->put('/backoffice/settings/payment-providers/beam', [
            'mode' => 'test',
            'credentials' => ['merchant_id' => 'm', 'api_key' => 'k'],
        ])->assertRedirect();

        $this->assertFalse(PaymentProviderAccount::firstOrFail()->is_active, 'กรอกกุญแจแล้วยังไม่ใช่การเปิดใช้งาน');
    }

    /* ---------- เปิดใช้งาน ---------- */

    public function test_a_provider_with_no_connector_written_yet_cannot_be_switched_on(): void
    {
        /*
        | เปิดได้ = ทั้งสาขาออก QR ไม่ได้เลย เพราะ providerFor() จะคืนเจ้านั้น
        | แล้ว gateway() โยน error — ร้านจะรับโอนไม่ได้ทั้งวันโดยไม่รู้สาเหตุ
        */
        $this->account(PaymentProvider::Beam, credentials: ['merchant_id' => 'm', 'api_key' => 'k']);

        $this->post('/backoffice/settings/payment-providers/beam/activate')
            ->assertSessionHas('error');

        $this->assertFalse(PaymentProviderAccount::firstOrFail()->is_active);
    }

    public function test_an_account_with_missing_keys_cannot_be_switched_on(): void
    {
        $this->withConnector(PaymentProvider::Beam);
        $this->account(PaymentProvider::Beam, credentials: ['merchant_id' => 'm']);   // ขาด api_key

        $this->post('/backoffice/settings/payment-providers/beam/activate')
            ->assertSessionHas('error');

        $this->assertFalse(PaymentProviderAccount::firstOrFail()->is_active);
    }

    public function test_switching_one_on_switches_the_others_off(): void
    {
        /*
        | QR ใบถัดไปจะออกจากเจ้าไหนต้องเดาไม่ได้ เพราะเป็นเรื่องเงิน
        | providerFor() คืนเจ้าเดียว ถ้าเปิดสองเจ้าไว้มันเลือกตามเวลาที่เปิด
        */
        $this->withConnector(PaymentProvider::Beam);
        $this->withConnector(PaymentProvider::Stripe);

        $old = $this->account(PaymentProvider::Stripe, credentials: ['secret_key' => 'sk'], active: true);
        $this->account(PaymentProvider::Beam, credentials: ['merchant_id' => 'm', 'api_key' => 'k']);

        $this->post('/backoffice/settings/payment-providers/beam/activate')
            ->assertSessionHas('success');

        $this->assertFalse($old->fresh()->is_active, 'เจ้าเดิมต้องถูกปิดให้เอง');
        $this->assertTrue(
            PaymentProviderAccount::where('provider', PaymentProvider::Beam->value)->firstOrFail()->is_active,
        );
        $this->assertSame(
            PaymentProvider::Beam,
            app(PaymentChargeService::class)->providerFor($this->branch->fresh()),
        );
    }

    public function test_the_old_providers_keys_survive_being_switched_off(): void
    {
        /*
        | QR ของเจ้าเดิมที่ออกไปแล้วยังค้างอยู่และต้องไล่ถามต่อจนจบ
        | ถ้าลบกุญแจหรือมองไม่เห็นบัญชีที่ปิดแล้ว เงินที่เข้าใบเก่าจะไม่มีใครเห็น
        */
        $this->withConnector(PaymentProvider::Beam);
        $this->withConnector(PaymentProvider::Stripe);

        $stripe = $this->account(PaymentProvider::Stripe, credentials: ['secret_key' => 'sk_เดิม'], active: true);
        $this->account(PaymentProvider::Beam, credentials: ['merchant_id' => 'm', 'api_key' => 'k']);

        $this->post('/backoffice/settings/payment-providers/beam/activate');

        $this->assertSame('sk_เดิม', $stripe->fresh()->secret('secret_key'), 'กุญแจของเจ้าเดิมต้องยังอยู่');

        // และตัวไล่ถามยังหาบัญชีของเจ้าเดิมเจอ
        $this->assertNotNull(
            app(PaymentChargeService::class)->accountFor($this->branch->fresh(), PaymentProvider::Stripe),
            'accountFor() ต้องไม่กรองเฉพาะบัญชีที่เปิดใช้งาน',
        );
    }

    public function test_switching_off_says_how_many_charges_are_still_in_flight(): void
    {
        $this->withConnector(PaymentProvider::Beam);
        $this->account(PaymentProvider::Beam, credentials: ['merchant_id' => 'm', 'api_key' => 'k'], active: true);

        $this->openCharge(PaymentProvider::Beam);

        $this->post('/backoffice/settings/payment-providers/beam/deactivate')
            ->assertSessionHas('success', fn (string $msg) => str_contains($msg, '1 ใบ'));
    }

    public function test_switching_everything_off_falls_back_to_the_shops_own_qr(): void
    {
        $this->withConnector(PaymentProvider::Beam);
        $this->account(PaymentProvider::Beam, credentials: ['merchant_id' => 'm', 'api_key' => 'k'], active: true);

        $this->post('/backoffice/settings/payment-providers/beam/deactivate')->assertRedirect();

        $this->assertSame(
            PaymentProvider::Static_,
            app(PaymentChargeService::class)->providerFor($this->branch->fresh()),
            'ร้านต้องรับเงินได้ต่อ แม้จะตรวจยอดอัตโนมัติไม่ได้',
        );
    }

    /* ---------- หน้าจอ ---------- */

    public function test_the_page_tells_the_owner_which_provider_new_charges_use(): void
    {
        $this->get('/backoffice/settings/payment-providers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('activeProvider', 'static')
                ->has('providers', 4)
                ->where('providers.0.is_gateway', true));
    }

    public function test_the_page_does_not_offer_the_shops_own_qr_as_something_to_configure(): void
    {
        // QR ของร้านไม่มีกุญแจให้ตั้ง — ตั้งพร้อมเพย์ที่หน้าตั้งค่าสาขาแทน
        $this->get('/backoffice/settings/payment-providers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('providers', fn ($providers) => ! collect($providers)->contains('value', 'static')));
    }

    public function test_the_page_flags_providers_whose_field_names_are_not_confirmed_yet(): void
    {
        /*
        | Stripe ยืนยันแล้ว (sk_ / pk_ / whsec_ เป็นมาตรฐานของเขา)
        | เจ้าอื่นเป็นการคาดไว้ก่อน จะยืนยันตอนเขียนตัวเชื่อม
        | ถ้าไม่บอก ร้านจะกรอกไปแล้วมารู้ทีหลังว่าชื่อช่องผิด
        */
        $this->assertTrue(PaymentProvider::Stripe->fieldsConfirmed());
        $this->assertFalse(PaymentProvider::Beam->fieldsConfirmed());

        $this->get('/backoffice/settings/payment-providers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('providers', fn ($providers) => collect($providers)
                    ->firstWhere('value', 'stripe')['fields_confirmed'] === true));
    }

    public function test_an_unknown_provider_in_the_url_is_a_404(): void
    {
        $this->put('/backoffice/settings/payment-providers/ไม่มีเจ้านี้', ['mode' => 'test'])
            ->assertNotFound();
    }

    public function test_the_shops_own_qr_cannot_be_given_keys(): void
    {
        $this->put('/backoffice/settings/payment-providers/static', ['mode' => 'test'])
            ->assertNotFound();
    }

    /* ---------- ตัวช่วย ---------- */

    protected function account(PaymentProvider $provider, array $credentials, bool $active = false): PaymentProviderAccount
    {
        return PaymentProviderAccount::create([
            'branch_id' => $this->branch->id,
            'provider' => $provider,
            'mode' => 'test',
            'is_active' => $active,
            'credentials' => $credentials,
            'activated_at' => $active ? now() : null,
        ]);
    }

    /** ผูกตัวเชื่อมปลอมของเจ้านี้ เพื่อให้ hasConnector() ตอบว่ามี */
    protected function withConnector(PaymentProvider $provider): void
    {
        app()->instance('payment.gateway.'.$provider->value, new SettingsFakeGateway);
    }

    /** QR ที่ยังค้างอยู่หนึ่งใบ — ใช้ OrderService เปิดบิลเพื่อไม่ต้องเดาคอลัมน์ที่จำเป็น */
    protected function openCharge(PaymentProvider $provider): PaymentCharge
    {
        $order = app(OrderService::class)->open($this->branch);

        return PaymentCharge::create([
            'branch_id' => $this->branch->id,
            'order_id' => $order->id,
            'provider' => $provider,
            'amount' => 100,
            'status' => ChargeStatus::Pending,
            'provider_charge_id' => 'x'.uniqid(),
        ]);
    }

    protected function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'branch_id' => $this->branch->id,
            'name' => 'ผู้ใช้ทดสอบ',
            'email' => $email,
            'password' => 'password',
            'role' => $role,
        ]);
    }

    protected function makeBranch(string $code): Branch
    {
        return Branch::create([
            'code' => $code,
            'name' => 'สาขา '.$code,
            'vat_rate' => 0,
            'vat_included' => true,
            'service_charge_rate' => 0,
            'rounding_mode' => 0,
            'business_day_start' => '05:00:00',
            'promptpay_id' => '0812345678',
        ]);
    }
}

/**
 * ตัวเชื่อมปลอม — มีไว้ให้ hasConnector() ตอบว่า "มีตัวเชื่อมของเจ้านี้แล้ว"
 *
 * ชุดนี้ไม่ได้ทดสอบเส้นทางเงิน (นั่นเป็นงานของ PaymentChargeTest)
 * จึงตอบเท่าที่สัญญาบังคับ ไม่ต้องมีสวิตช์ให้สั่งว่าจะจ่ายหรือไม่จ่าย
 *
 * ชื่อคลาสต่างจาก FakeGateway ของ PaymentChargeTest โดยตั้งใจ —
 * ทั้งสองไฟล์อยู่ namespace เดียวกัน ชื่อซ้ำกันจะโหลดพร้อมกันไม่ได้
 */
class SettingsFakeGateway implements \App\Payments\PaymentGateway
{
    public function name(): string
    {
        return 'settings-fake';
    }

    public function expiryWindow(): \App\Payments\ExpiryWindow
    {
        return \App\Payments\ExpiryWindow::never();
    }

    public function createCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): \App\Payments\ChargeResult
    {
        return new \App\Payments\ChargeResult(
            providerChargeId: 'settings_'.$charge->uuid,
            qrPayload: '00020101021229370016A000000677010111',
            expiresAt: $charge->expires_at,
        );
    }

    public function pollCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): \App\Payments\ChargeStatusResult
    {
        return \App\Payments\ChargeStatusResult::pending('ยังไม่จ่าย');
    }

    public function cancelCharge(PaymentCharge $charge, ?PaymentProviderAccount $account): void
    {
        // ชุดนี้ไม่แตะเส้นทางยกเลิก
    }
}
