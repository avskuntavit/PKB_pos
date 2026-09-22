<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Services\TableSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * จำว่าลูกค้าคนนี้กำลังสั่งจากร้านไหน และนั่งโต๊ะไหน
 *
 * ลูกค้าไม่ได้ล็อกอิน จึงไม่มี user ให้ผูกข้อมูล — ใช้ session กับ cookie แทน
 * session อยู่แค่รอบเบราว์เซอร์ ส่วน cookie อยู่ 90 วัน เพื่อให้ลูกค้าประจำ
 * เปิดเว็บครั้งหน้าแล้วเข้าเมนูร้านเดิมได้เลยโดยไม่ต้องเลือกซ้ำ
 *
 * ลำดับการตัดสินร้าน — รหัสใน URL ต้องชนะทุกอย่างเสมอ
 * เพราะคนที่ยืนสแกน QR อยู่หน้าร้าน B ต้องได้ร้าน B แม้ cookie จะจำร้าน A ไว้
 */
class StorefrontSession
{
    public const STATION_COOKIE = 'station';

    protected const STATION_KEY = 'storefront.station_id';

    protected const TABLE_KEY = 'storefront.table';

    /** โต๊ะที่สแกนมาแล้วแต่ยังเปิดไม่ได้ — รอพนักงานเปิดให้ */
    protected const PENDING_TABLE_KEY = 'storefront.table_pending';

    /** ชื่อเล่นที่ลูกค้าพิมพ์ไว้ ใช้บอกว่าจานไหนใครสั่ง */
    protected const GUEST_NAME_KEY = 'storefront.guest_name';

    protected const COOKIE_DAYS = 90;

    public function __construct(protected TableSessionService $sessions) {}

    /* ---------- ร้าน / สถานี ---------- */

    /**
     * ร้านที่ควรใช้กับ request นี้
     *
     * คืน null เมื่อตัดสินไม่ได้ = ต้องให้ลูกค้าเลือกเอง
     */
    public function resolveStation(Request $request, ?string $code = null): ?Branch
    {
        // 1. รหัสร้านใน URL — QR หน้าร้าน / ลิงก์ที่ร้านส่งให้
        if (filled($code)) {
            $branch = $this->findByCode($code);

            return $branch ? $this->rememberStation($branch) : null;
        }

        // 2. เลือกไว้แล้วในรอบนี้
        $id = $request->session()->get(self::STATION_KEY);

        if ($id && $branch = Branch::where('id', $id)->where('is_active', true)->first()) {
            CurrentBranch::set($branch);

            return $branch;
        }

        // 3. เคยสั่งจากเครื่องนี้ภายใน 90 วัน
        $cookie = $request->cookie(self::STATION_COOKIE);

        if (filled($cookie) && $branch = $this->findByCode((string) $cookie)) {
            return $this->rememberStation($branch);
        }

        // 4. ร้านเดียวก็ไม่ต้องถามให้เสียเวลา
        $open = $this->activeStations();

        return $open->count() === 1 ? $this->rememberStation($open->first()) : null;
    }

    public function rememberStation(Branch $branch): Branch
    {
        session([self::STATION_KEY => $branch->id]);
        Cookie::queue(self::STATION_COOKIE, $branch->code, 60 * 24 * self::COOKIE_DAYS);

        CurrentBranch::set($branch);

        return $branch;
    }

    /** รายการร้านสำหรับหน้าเลือกร้าน */
    public function options(): array
    {
        return $this->activeStations()
            ->map(fn (Branch $b) => [
                'code' => $b->code,
                'name' => $b->name,
                'intro' => $b->intro,
                'address' => $b->address,
                'logo_path' => $b->logo_path,
                'cover_path' => $b->cover_path,
                'is_open_now' => $b->isOpenNow(),
                'is_taking_orders' => $b->isTakingOnlineOrders(),
                'open_time' => substr((string) $b->open_time, 0, 5),
                'close_time' => substr((string) $b->close_time, 0, 5),
            ])
            ->all();
    }

    /* ---------- โต๊ะ ---------- */

    /**
     * ผูกลูกค้ากับโต๊ะที่เพิ่งสแกน QR
     *
     * เก็บใน session ฝั่งเซิร์ฟเวอร์เท่านั้น ไม่ส่งเลขโต๊ะให้ client เป็นตัวตัดสิน
     * เพราะถ้าเชื่อค่าที่ client ส่งมา ใครก็ยิงบิลเข้าโต๊ะคนอื่นได้
     */
    public function rememberTable(Request $request, DiningTable $table): void
    {
        $request->session()->put(self::TABLE_KEY, [
            'id' => $table->id,
            'branch_id' => $table->branch_id,
        ]);
    }

    /** โต๊ะที่ลูกค้าคนนี้นั่งอยู่ (ถ้ามี) — ต้องเป็นโต๊ะของร้านที่กำลังดูอยู่เท่านั้น */
    public function table(Request $request, Branch $branch): ?DiningTable
    {
        $table = $this->boundTable($request, $branch, self::TABLE_KEY);

        // รอบการนั่งจบแล้ว — ปิดบิลไปแล้ว หรือพนักงานเคลียร์โต๊ะ
        // ปล่อยให้สั่งต่อไม่ได้ ไม่งั้นคนที่เก็บลิงก์ไว้จะยิงบิลเข้าโต๊ะของลูกค้ากลุ่มถัดไป
        if ($table && ! $this->sessions->isOpenForGuests($table)) {
            $table = null;
        }

        // โต๊ะถูกปิด ลบ หรือหมดรอบไปแล้ว อย่าค้างไว้ให้สั่งเข้าโต๊ะที่ไม่มีอยู่
        // ล้างเฉพาะโต๊ะที่ผูกไว้ ห้ามแตะโต๊ะที่รอพนักงานเปิด ไม่งั้น seating() จะอ่านไม่เจอ
        if (! $table) {
            $request->session()->forget(self::TABLE_KEY);
        }

        return $table;
    }

    /**
     * สรุปให้หน้าจอในครั้งเดียวว่า "นั่งโต๊ะไหน" หรือ "รอพนักงานเปิดโต๊ะไหน"
     *
     * พอพนักงานเปิดโต๊ะให้ ลูกค้าแค่รีเฟรชก็ผูกโต๊ะเอง ไม่ต้องสแกนซ้ำ
     * ถ้าให้สแกนใหม่ ลูกค้าจะคิดว่าระบบพัง ทั้งที่แค่รอพนักงานอยู่
     *
     * @return array{table: ?DiningTable, pendingTable: ?string}
     */
    public function seating(Request $request, Branch $branch): array
    {
        if ($table = $this->table($request, $branch)) {
            return ['table' => $table, 'pendingTable' => null];
        }

        $pending = $this->boundTable($request, $branch, self::PENDING_TABLE_KEY);

        if (! $pending) {
            $request->session()->forget(self::PENDING_TABLE_KEY);

            return ['table' => null, 'pendingTable' => null];
        }

        if (! $this->sessions->isOpenForGuests($pending)) {
            return ['table' => null, 'pendingTable' => $pending->name];
        }

        $this->sessions->resolve($pending, $request->ip());
        $this->rememberTable($request, $pending);
        $request->session()->forget(self::PENDING_TABLE_KEY);

        return ['table' => $pending, 'pendingTable' => null];
    }

    /**
     * โต๊ะที่รอพนักงานเปิดให้ — คืนตัวโต๊ะจริง ไม่ใช่แค่ชื่อแบบที่ seating() คืน
     *
     * หน้าที่ต้องสั่งงานกับโต๊ะนั้น (เช่นยิงคำขอเปิดโต๊ะ) ต้องใช้ตัวโต๊ะจริง
     * และต้องอ่านจาก session เท่านั้น ห้ามรับเลขโต๊ะจาก request
     * ไม่งั้นใครก็ยิงคำขอเปิดโต๊ะของคนอื่นรัว ๆ ได้
     */
    public function pendingTable(Request $request, Branch $branch): ?DiningTable
    {
        return $this->boundTable($request, $branch, self::PENDING_TABLE_KEY);
    }

    /** จำไว้ว่าลูกค้าสแกน QR โต๊ะไหนมา ทั้งที่โต๊ะนั้นยังเปิดไม่ได้ */
    public function rememberPendingTable(Request $request, DiningTable $table): void
    {
        $request->session()->put(self::PENDING_TABLE_KEY, [
            'id' => $table->id,
            'branch_id' => $table->branch_id,
        ]);
    }

    public function forgetTable(Request $request): void
    {
        $request->session()->forget([self::TABLE_KEY, self::PENDING_TABLE_KEY]);
    }

    /* ---------- ชื่อเล่นผู้สั่ง ---------- */

    /**
     * ชื่อเล่นของคนที่ถือเครื่องนี้
     *
     * เก็บฝั่งเซิร์ฟเวอร์เหมือนเลขโต๊ะ เพราะถ้าเก็บที่เบราว์เซอร์แล้วส่งมาตอนสั่ง
     * ใครก็ใส่ชื่อคนอื่นลงจานที่ตัวเองสั่งได้ ซึ่งจะพังตอนแบ่งบิลรายคน
     *
     * ไม่ใช่การยืนยันตัวตน เป็นแค่ป้ายชื่อให้คนที่โต๊ะเดียวกันคุยกันรู้เรื่อง
     */
    public function guestName(Request $request): ?string
    {
        $name = $request->session()->get(self::GUEST_NAME_KEY);

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function rememberGuestName(Request $request, ?string $name): void
    {
        $name = trim((string) $name);

        if ($name === '') {
            $request->session()->forget(self::GUEST_NAME_KEY);

            return;
        }

        // ตัดให้พอดีคอลัมน์ตั้งแต่ตรงนี้ จะได้ไม่ไปพังตอน insert
        $request->session()->put(self::GUEST_NAME_KEY, mb_substr($name, 0, 30));
    }

    /* ---------- ภายใน ---------- */

    /**
     * อ่านโต๊ะที่เก็บไว้ใน session ช่องที่ระบุ
     *
     * ต้องเป็นโต๊ะของร้านที่กำลังดูอยู่เท่านั้น — ลูกค้าเปลี่ยนร้านแล้วโต๊ะเก่าต้องใช้ไม่ได้
     */
    protected function boundTable(Request $request, Branch $branch, string $key): ?DiningTable
    {
        $bound = $request->session()->get($key);

        if (! is_array($bound) || (int) ($bound['branch_id'] ?? 0) !== $branch->id) {
            return null;
        }

        return DiningTable::where('id', $bound['id'] ?? 0)
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->first();
    }

    protected function findByCode(string $code): ?Branch
    {
        return Branch::where('code', $code)->where('is_active', true)->first();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Branch> */
    protected function activeStations()
    {
        return Branch::where('is_active', true)->orderBy('name')->get();
    }
}
