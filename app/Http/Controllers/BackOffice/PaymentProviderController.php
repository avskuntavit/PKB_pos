<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\PaymentCharge;
use App\Models\PaymentProviderAccount;
use App\Services\ActivityLogger;
use App\Services\PaymentChargeService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ตั้งค่าบัญชีผู้ให้บริการชำระเงินของสาขา
 *
 * ── กฎเหล็กของหน้านี้ ────────────────────────────────────────────────────
 * **ค่าจริงของกุญแจไม่เคยถูกส่งออกไปที่เบราว์เซอร์** ส่งแค่สี่ตัวท้ายกับธงว่ามีค่าอยู่
 * กุญแจที่หลุดไปอยู่ในซอร์สของหน้า ใน history ของเบราว์เซอร์ และใน log ของ proxy
 * ทุกตัวที่อยู่ระหว่างทาง เรียกคืนไม่ได้ ต้องไปขอออกใหม่จากเกตเวย์
 *
 * ── ช่องว่างตอนบันทึก = ไม่แก้ ไม่ใช่ลบ ─────────────────────────────────
 * หน้าจอไม่มีค่าเดิมให้แสดง จึงส่งช่องว่างมาทุกครั้งที่ไม่ได้พิมพ์อะไรใหม่
 * ถ้าตีความว่า "ลบ" การกดบันทึกเพื่อแก้แค่หมายเหตุจะล้างกุญแจทั้งชุด
 * การลบต้องกดปุ่มลบที่แยกไว้ชัดเจน
 *
 * ── สิทธิ์ ───────────────────────────────────────────────────────────────
 * `branch.settings` ชุดเดียวกับตั้งค่าสาขา ซึ่งมีแต่เจ้าของร้าน
 * (`Permission::defaultsFor` ไม่ได้ให้ผู้จัดการ) — กุญแจรับเงินไม่ใช่ของที่ผู้จัดการต้องแตะ
 */
class PaymentProviderController extends Controller
{
    public function __construct(
        protected PaymentChargeService $charges,
        protected ActivityLogger $logger,
    ) {}

    public function index(): Response
    {
        $branch = CurrentBranch::getOrFail();

        $accounts = PaymentProviderAccount::where('branch_id', $branch->id)
            ->get()
            ->keyBy(fn (PaymentProviderAccount $a) => $a->provider->value);

        return Inertia::render('BackOffice/PaymentProviders/Index', [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],

            // เจ้าที่ตั้งบัญชีได้ — ไม่รวม static เพราะมันไม่มีกุญแจให้ตั้ง
            'providers' => collect(PaymentProvider::options())
                ->filter(fn (array $p) => $p['is_gateway'])
                ->map(fn (array $p) => $p + [
                    'has_connector' => $this->hasConnector(PaymentProvider::from($p['value'])),
                ])
                ->values()
                ->all(),

            'accounts' => $accounts->map(fn (PaymentProviderAccount $a) => $this->row($a))->values()->all(),

            /*
            | เจ้าที่ใบใหม่จะใช้ตอนนี้ — คำตอบจาก PaymentChargeService ตัวจริง
            | ไม่ใช่ให้หน้าจอเดาจากแถวที่ is_active เพราะกฎการเลือกอยู่ที่นั้น
            */
            'activeProvider' => $this->charges->providerFor($branch)->value,
            'activeProviderLabel' => $this->charges->providerFor($branch)->label(),
        ]);
    }

    /**
     * บันทึกบัญชีของเจ้าหนึ่ง — สร้างใหม่ถ้ายังไม่มี
     *
     * ช่องกุญแจที่ส่งมาว่างเปล่า = คงค่าเดิมไว้ (ดูคำอธิบายหัวคลาส)
     */
    public function save(Request $request, PaymentProvider $provider): RedirectResponse
    {
        abort_if(! $provider->isGateway(), 404);

        $branch = CurrentBranch::getOrFail();

        $rules = [
            'mode' => ['required', Rule::in(['test', 'live'])],
            'note' => ['nullable', 'string', 'max:255'],
        ];

        foreach ($provider->credentialFields() as $field) {
            // nullable ทุกช่อง เพราะช่องว่างหมายถึง "ไม่แก้" ความครบถ้วนตรวจตอนเปิดใช้งาน
            $rules['credentials.'.$field['key']] = ['nullable', 'string', 'max:500'];
        }

        $data = $request->validate($rules);

        $account = PaymentProviderAccount::firstOrNew([
            'branch_id' => $branch->id,
            'provider' => $provider->value,
        ]);

        $credentials = $account->credentials ?? [];
        $changed = [];

        foreach ($provider->credentialFields() as $field) {
            $value = trim((string) ($data['credentials'][$field['key']] ?? ''));

            if ($value === '') {
                continue;
            }

            $credentials[$field['key']] = $value;
            $changed[] = $field['key'];
        }

        $account->fill([
            'mode' => $data['mode'],
            'note' => $data['note'] ?? null,
            'credentials' => $credentials ?: null,
        ]);

        $account->is_active ??= false;
        $account->save();

        /*
        | บันทึกว่า "แก้ช่องไหน" ไม่ใช่ "แก้เป็นอะไร"
        | log ที่มีกุญแจอยู่ข้างในคือกุญแจที่หลุดไปอีกที่หนึ่ง
        */
        $this->logger->log('payment_account.update', $account, [
            'provider' => $provider->value,
            'mode' => $account->mode,
            'changed_keys' => $changed,
        ], $branch);

        return back()->with('success', 'บันทึกบัญชี '.$provider->label().' แล้ว');
    }

    /**
     * เปิดใช้งานเจ้านี้ — ปิดเจ้าอื่นให้อัตโนมัติ
     *
     * ── ทำไมเปิดได้เจ้าเดียว ─────────────────────────────────────────
     * `providerFor()` คืนเจ้าเดียว ถ้าเปิดไว้สองเจ้าแล้วมันเลือกตามเวลาที่เปิด
     * คนตั้งจะเดาไม่ได้ว่า QR ใบถัดไปจะออกจากเจ้าไหน — และนี่คือเรื่องเงิน
     * ไม่ใช่เรื่องที่ควรเดา
     *
     * บัญชีที่ถูกปิดยังเก็บกุญแจไว้ เพราะ QR ของเจ้าเดิมที่ค้างอยู่ต้องไล่ถามต่อจนจบ
     * (`accountFor()` จึงไม่กรอง is_active)
     */
    public function activate(PaymentProvider $provider): RedirectResponse
    {
        abort_if(! $provider->isGateway(), 404);

        $branch = CurrentBranch::getOrFail();

        $account = PaymentProviderAccount::where('branch_id', $branch->id)
            ->where('provider', $provider->value)
            ->first();

        if (! $account) {
            return back()->with('error', 'ยังไม่ได้บันทึกบัญชีของเจ้านี้');
        }

        if ($missing = $account->missingKeys()) {
            return back()->with('error', 'ยังกรอกไม่ครบ: '.implode(', ', $missing));
        }

        if (! $this->hasConnector($provider)) {
            return back()->with('error',
                'ยังไม่มีตัวเชื่อมของ '.$provider->label().' — เปิดใช้งานแล้วจะออก QR ไม่ได้เลย');
        }

        PaymentProviderAccount::where('branch_id', $branch->id)
            ->where('id', '!=', $account->id)
            ->update(['is_active' => false]);

        $account->update(['is_active' => true, 'activated_at' => now()]);

        $this->logger->log('payment_account.update', $account, [
            'provider' => $provider->value,
            'action' => 'activate',
            'mode' => $account->mode,
        ], $branch);

        return back()->with('success', 'เปิดใช้งาน '.$provider->label().' แล้ว · QR ใบใหม่จะออกจากเจ้านี้');
    }

    /** ปิดใช้งาน — ใบใหม่ถอยไปใช้ QR ของร้าน */
    public function deactivate(PaymentProvider $provider): RedirectResponse
    {
        $branch = CurrentBranch::getOrFail();

        $account = PaymentProviderAccount::where('branch_id', $branch->id)
            ->where('provider', $provider->value)
            ->firstOrFail();

        $account->update(['is_active' => false]);

        $this->logger->log('payment_account.update', $account, [
            'provider' => $provider->value,
            'action' => 'deactivate',
        ], $branch);

        $open = PaymentCharge::open()
            ->where('branch_id', $branch->id)
            ->where('provider', $provider->value)
            ->count();

        return back()->with('success', $open > 0
            ? 'ปิดใช้งานแล้ว · QR ที่ค้างอยู่ '.$open.' ใบยังถูกไล่ถามต่อจนจบ'
            : 'ปิดใช้งาน '.$provider->label().' แล้ว');
    }

    /**
     * ลบกุญแจทั้งชุด
     *
     * แยกเป็นปุ่มของตัวเอง ไม่ใช่ผลข้างเคียงของการกดบันทึกด้วยช่องว่าง
     * และปิดใช้งานไปด้วย เพราะบัญชีที่ไม่มีกุญแจเปิดไว้ก็ออก QR ไม่ได้อยู่ดี
     */
    public function clearCredentials(PaymentProvider $provider): RedirectResponse
    {
        $branch = CurrentBranch::getOrFail();

        $account = PaymentProviderAccount::where('branch_id', $branch->id)
            ->where('provider', $provider->value)
            ->firstOrFail();

        $account->update(['credentials' => null, 'is_active' => false]);

        $this->logger->log('payment_account.update', $account, [
            'provider' => $provider->value,
            'action' => 'clear_credentials',
        ], $branch);

        return back()->with('success', 'ลบกุญแจของ '.$provider->label().' แล้ว');
    }

    /* ---------- ภายใน ---------- */

    /**
     * เขียน driver ของเจ้านี้แล้วหรือยัง
     *
     * ถามด้วยการลองหยิบจริง ไม่ใช่เก็บรายชื่อไว้อีกที่ — รายชื่อสองชุด
     * มีวันที่ไม่ตรงกัน แล้วหน้าจอจะบอกว่าพร้อมทั้งที่ยังออก QR ไม่ได้
     */
    protected function hasConnector(PaymentProvider $provider): bool
    {
        try {
            $this->charges->gateway($provider);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    protected function row(PaymentProviderAccount $account): array
    {
        return [
            'provider' => $account->provider->value,
            'mode' => $account->mode,
            'is_active' => (bool) $account->is_active,
            'activated_at' => $account->activated_at?->toIso8601String(),
            'note' => $account->note,
            // ค่าจริงไม่เคยออกไปจากเซิร์ฟเวอร์ — ดูคำอธิบายหัวคลาส
            'credentials' => $account->maskedCredentials(),
            'missing_keys' => $account->missingKeys(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
