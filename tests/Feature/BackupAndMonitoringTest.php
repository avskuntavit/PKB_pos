<?php

namespace Tests\Feature;

use App\Enums\BackupKind;
use App\Models\BackupRun;
use App\Models\Branch;
use App\Models\ErrorEvent;
use App\Models\User;
use App\Services\BackupService;
use App\Services\ErrorMonitor;
use App\Services\SystemHealthService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;
use ZipArchive;

/**
 * การสำรองข้อมูลและการเฝ้าดู error
 *
 * ── สิ่งที่เทสต์ชุดนี้คุมเป็นหลัก ───────────────────────────────────────
 * 1. ตัวเฝ้าดู error ต้อง "เงียบเมื่อไม่มีอะไร" — ถ้ามันเก็บความผิดพลาดของผู้ใช้ด้วย
 *    หน้าเฝ้าดูจะเต็มจนของจริงจม แล้วเท่ากับไม่มีหน้าเฝ้าดู
 * 2. ตัวเฝ้าดูต้องไม่พังซ้ำเวลาระบบพัง — เวลาฐานข้อมูลล่ม มันคือโค้ดที่ถูกเรียก
 *    ทุก request ถ้ามันโยน exception ออกมา ทุกอย่างจะพังหนักกว่าเดิม
 * 3. การสำรองที่ล้มเหลวต้องบอกสาเหตุเป็นภาษาที่คนแก้ได้ ไม่ใช่เงียบแล้วดูเหมือนสำเร็จ
 * 4. ไฟล์สำรองต้องหมุนทับช่องเดิม ไม่โตไม่รู้จบ เพราะไม่มีใครมาลบให้
 */
class BackupAndMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = $this->makeBranch('AA', 'สาขาหนึ่ง');

        // ทำงานในโฟลเดอร์ชั่วคราวเสมอ ไม่แตะ storage จริงของโปรเจกต์
        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'foodpos-monitoring-'.bin2hex(random_bytes(6));

        mkdir($this->tmp.'/uploads', 0775, true);
        mkdir($this->tmp.'/private', 0775, true);

        config([
            'monitoring.backup.files_path' => $this->tmp.'/backups',
            'monitoring.backup.sources.uploads' => $this->tmp.'/uploads',
            'monitoring.backup.sources.exports' => $this->tmp.'/private',
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tmp);

        parent::tearDown();
    }

    /* ---------- ตัวเฝ้าดู error: อะไรเก็บ อะไรไม่เก็บ ---------- */

    public function test_a_system_error_is_recorded(): void
    {
        $event = $this->monitor()->record(new \RuntimeException('ต่อเครื่องพิมพ์ไม่ได้'));

        $this->assertNotNull($event);
        $this->assertSame(1, $event->occurrences);
        $this->assertSame('RuntimeException', $event->shortClass());
        $this->assertNull($event->resolved_at);
        $this->assertSame(1, ErrorEvent::count());
    }

    public function test_mistakes_made_by_people_are_not_recorded(): void
    {
        /*
        | ของสี่อย่างนี้เกิดทุกวันเป็นเรื่องปกติของการใช้งาน
        | กรอกฟอร์มไม่ครบ · พิมพ์ URL ผิด · กดของที่ไม่มีสิทธิ์ · โดนจำกัดจำนวนครั้ง
        | ถ้าเก็บด้วย หน้าเฝ้าดูจะมีแต่เรื่องที่ไม่ต้องทำอะไร แล้วของจริงจะจมหาย
        */
        $monitor = $this->monitor();

        $monitor->record(ValidationException::withMessages(['name' => 'ต้องกรอกชื่อ']));
        $monitor->record(new NotFoundHttpException('ไม่พบหน้านี้'));
        $monitor->record(new AuthorizationException('ไม่มีสิทธิ์'));
        $monitor->record(new HttpException(429, 'กดถี่เกินไป'));

        $this->assertSame(0, ErrorEvent::count());
    }

    public function test_server_side_http_errors_are_recorded(): void
    {
        // abort(500) ที่เราเขียนเองก็ต้องเก็บ เพราะแปลว่าเงื่อนไขที่คิดว่า "ไม่น่าเกิด" เกิดแล้ว
        $this->monitor()->record(new HttpException(500, 'คำนวณยอดไม่ได้'));

        $this->assertSame(1, ErrorEvent::count());
    }

    public function test_the_same_bug_is_one_case_even_when_the_numbers_differ(): void
    {
        $monitor = $this->monitor();

        // error เดียวกันที่พก id ต่างกันมา ต้องไม่กลายเป็นคนละเคส
        $monitor->record($this->errorAtSameLine('ไม่พบสินค้า 4821'));
        $monitor->record($this->errorAtSameLine('ไม่พบสินค้า 4822'));
        $monitor->record($this->errorAtSameLine('ไม่พบสินค้า 9999'));

        $this->assertSame(1, ErrorEvent::count());
        $this->assertSame(3, ErrorEvent::first()->occurrences);
    }

    public function test_different_bugs_stay_separate(): void
    {
        $monitor = $this->monitor();

        $monitor->record(new \RuntimeException('อย่างแรก'));
        $monitor->record(new \LogicException('อย่างแรก'));

        $this->assertSame(2, ErrorEvent::count());
    }

    public function test_a_closed_case_reopens_when_the_bug_happens_again(): void
    {
        $monitor = $this->monitor();

        $event = $monitor->record($this->errorAtSameLine('พัง'));
        $event->update(['resolved_at' => Carbon::now()]);

        $monitor->record($this->errorAtSameLine('พัง'));

        $this->assertNull($event->fresh()->resolved_at, 'บั๊กที่ยังเกิดอยู่ต้องไม่หายไปจากสายตาเพราะมีคนกดปิด');
        $this->assertSame(2, $event->fresh()->occurrences);
    }

    public function test_recording_never_throws_even_when_its_own_table_is_gone(): void
    {
        /*
        | สถานการณ์จริงที่กลัวคือฐานข้อมูลล่ม — ตอนนั้นทุก request จะโยน QueryException
        | แล้วเรียกตัวนี้ ถ้ามันโยนต่อ ระบบจะพังหนักกว่าเดิมและหาสาเหตุไม่เจอ
        | ที่นี่จำลองด้วยการลบตารางของมันเองทิ้ง ซึ่งให้ผลแบบเดียวกัน
        */
        Schema::drop('error_events');

        $this->assertNull($this->monitor()->record(new \RuntimeException('พังตอนที่ตารางหายไปแล้ว')));
    }

    public function test_pruning_only_removes_old_closed_cases(): void
    {
        $monitor = $this->monitor();

        $old = $monitor->record(new \RuntimeException('เก่าและปิดแล้ว'));
        $old->update(['resolved_at' => Carbon::now()->subDays(200)]);

        $recent = $monitor->record($this->errorAtSameLine('ปิดแล้วแต่เพิ่งปิด'));
        $recent->update(['resolved_at' => Carbon::now()->subDay()]);

        $open = $monitor->record(new \LogicException('ยังไม่ปิด'));

        $this->assertSame(1, $monitor->prune());
        $this->assertDatabaseMissing('error_events', ['id' => $old->id]);
        $this->assertDatabaseHas('error_events', ['id' => $recent->id]);
        $this->assertDatabaseHas('error_events', ['id' => $open->id]);
    }

    /* ---------- การสำรองฐานข้อมูล ---------- */

    public function test_database_backup_says_plainly_that_it_needs_sql_server(): void
    {
        // เทสต์รันบน sqlite — คำสั่ง BACKUP DATABASE ใช้ไม่ได้ ต้องฟ้องให้ชัด ไม่ใช่เงียบ
        $run = $this->backups()->backupDatabase('mon');

        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertStringContainsString('SQL Server', (string) $run->error);
        $this->assertNotNull($run->finished_at);
    }

    public function test_slots_rotate_by_weekday_and_month(): void
    {
        $backups = $this->backups();

        $this->assertSame('mon', $backups->dailySlot(Carbon::parse('2026-09-21')));  // จันทร์
        $this->assertSame('sun', $backups->dailySlot(Carbon::parse('2026-09-27')));  // อาทิตย์
        $this->assertSame('m09', $backups->monthlySlot(Carbon::parse('2026-09-27')));
    }

    public function test_the_monthly_slot_is_written_once_per_month_not_once_per_first_day(): void
    {
        $backups = $this->backups();
        $september = Carbon::parse('2026-09-15 03:30');

        $this->assertTrue($backups->needsMonthly($september), 'เดือนนี้ยังไม่มีไฟล์รายเดือน จึงต้องเขียน');

        BackupRun::create([
            'kind' => BackupKind::Database->value,
            'slot' => 'm09',
            'status' => BackupRun::SUCCESS,
            'finished_at' => Carbon::parse('2026-09-03 03:30'),
        ]);

        $this->assertFalse($backups->needsMonthly($september));

        // เดือนถัดไปต้องเขียนใหม่ ถึงแม้ช่อง m10 จะมีไฟล์ของปีที่แล้วค้างอยู่ก็ตาม
        $this->assertTrue($backups->needsMonthly(Carbon::parse('2026-10-15 03:30')));
    }

    /* ---------- การสำรองไฟล์ ---------- */

    public function test_uploads_are_zipped_into_the_slot_file(): void
    {
        file_put_contents($this->tmp.'/uploads/menu.jpg', 'รูปเมนู');
        mkdir($this->tmp.'/uploads/slips', 0775, true);
        file_put_contents($this->tmp.'/uploads/slips/2026-09.png', 'สลิป');

        $run = $this->backups()->backupFiles(BackupKind::Uploads, 'wed');

        $this->assertSame(BackupRun::SUCCESS, $run->status, (string) $run->error);
        $this->assertSame($this->tmp.'/backups'.DIRECTORY_SEPARATOR.'uploads-wed.zip', $run->path);
        $this->assertFileExists($run->path);
        $this->assertGreaterThan(0, $run->size_bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($run->path) === true);
        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('menu.jpg'));
        $this->assertNotFalse($zip->locateName('slips/2026-09.png'), 'โครงสร้างโฟลเดอร์ต้องติดไปด้วย');
        $zip->close();
    }

    public function test_running_again_reuses_the_same_slot_file_instead_of_piling_up(): void
    {
        file_put_contents($this->tmp.'/uploads/a.txt', 'หนึ่ง');

        $backups = $this->backups();
        $backups->backupFiles(BackupKind::Uploads, 'wed');

        file_put_contents($this->tmp.'/uploads/b.txt', 'สอง');
        $second = $backups->backupFiles(BackupKind::Uploads, 'wed');

        $this->assertSame(BackupRun::SUCCESS, $second->status, (string) $second->error);
        $this->assertCount(
            1,
            glob($this->tmp.'/backups/*.zip') ?: [],
            'ช่องเดิมต้องถูกเขียนทับ ไม่ใช่สร้างไฟล์ใหม่ทุกครั้งจนดิสก์เต็ม'
        );

        $zip = new ZipArchive;
        $zip->open($this->tmp.'/backups'.DIRECTORY_SEPARATOR.'uploads-wed.zip');
        $this->assertSame(2, $zip->numFiles, 'ไฟล์ล่าสุดต้องเป็นของรอบใหม่ ไม่ใช่ของค้างจากรอบก่อน');
        $zip->close();
    }

    public function test_a_missing_folder_is_skipped_not_reported_as_a_failure(): void
    {
        config(['monitoring.backup.sources.exports' => $this->tmp.'/ไม่มีโฟลเดอร์นี้']);

        $run = $this->backups()->backupFiles(BackupKind::Exports, 'thu');

        // ร้านที่ยังไม่เคยส่งข้อมูลให้บัญชีเลย ไม่ใช่ร้านที่สำรองไม่ผ่าน
        $this->assertSame(BackupRun::SKIPPED, $run->status);
    }

    public function test_an_empty_folder_is_skipped_too(): void
    {
        $run = $this->backups()->backupFiles(BackupKind::Exports, 'thu');

        $this->assertSame(BackupRun::SKIPPED, $run->status);
        $this->assertSame([], glob($this->tmp.'/backups/*.zip') ?: []);
    }

    public function test_a_folder_bigger_than_the_cap_fails_loudly(): void
    {
        config(['monitoring.backup.max_files_mb' => 1]);

        file_put_contents($this->tmp.'/uploads/big.bin', str_repeat('x', 1_500_000));

        $run = $this->backups()->backupFiles(BackupKind::Uploads, 'fri');

        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertStringContainsString('เกินเพดาน', (string) $run->error);
    }

    public function test_backups_are_never_zipped_into_the_next_backup(): void
    {
        /*
        | ถ้ามีคนตั้งโฟลเดอร์ไฟล์สำรองไว้ใต้ storage/app/public (เพื่อให้โหลดผ่านเว็บได้)
        | รอบถัดไปจะ zip ไฟล์สำรองรอบก่อนเข้าไปด้วย แล้วไฟล์จะโตเป็นทวีคูณทุกคืน
        */
        config(['monitoring.backup.files_path' => $this->tmp.'/uploads/backups']);

        file_put_contents($this->tmp.'/uploads/menu.jpg', 'รูปเมนู');

        $backups = $this->backups();
        $backups->backupFiles(BackupKind::Uploads, 'sat');
        $second = $backups->backupFiles(BackupKind::Uploads, 'sun');

        $zip = new ZipArchive;
        $zip->open($second->path);
        $this->assertSame(1, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('menu.jpg'));
        $zip->close();
    }

    /* ---------- การเทียบ path ข้ามระบบปฏิบัติการ ---------- */

    public function test_paths_that_mix_slashes_are_still_recognised_on_windows(): void
    {
        /*
        | เคสนี้เคยทำให้เทสต์ข้างบนตกจริงบนวินโดวส์ ทั้งที่บนลินุกซ์ผ่านหมด
        |
        | ค่าใน config เขียนด้วย / แต่ RecursiveDirectoryIterator คืน \
        | เทียบตรง ๆ จึงไม่ตรงกันทั้งที่เป็นโฟลเดอร์เดียวกัน ด่านกัน zip ตัวเองเลยหลุด
        | แล้วไฟล์สำรองรอบก่อนถูกใส่เข้าไปในรอบถัดไป — ไฟล์โตเป็นทวีคูณทุกคืน
        |
        | ทั้งสองเมธอดรับ $windows เป็นพารามิเตอร์ จะได้ตรวจพฤติกรรมของทั้งสองระบบ
        | ได้จากเครื่องไหนก็ได้ ไม่ต้องรอไปเจอบนเครื่องจริง
        */
        $this->assertTrue(BackupService::pathIsUnder(
            'C:\\tmp\\x/uploads\\backups\\uploads-sat.zip', 'C:\\tmp\\x/uploads/backups', true
        ));

        $this->assertFalse(BackupService::pathIsUnder(
            'C:\\tmp\\x/uploads\\menu.jpg', 'C:\\tmp\\x/uploads/backups', true
        ), 'ไฟล์ที่อยู่นอกโฟลเดอร์สำรองต้องไม่ถูกกันออก');

        // วินโดวส์ไม่สนตัวพิมพ์เล็กใหญ่ของ path
        $this->assertTrue(BackupService::pathIsUnder(
            'C:\\A\\Backups\\x.zip', 'c:\\a\\backups', true
        ));

        // โฟลเดอร์คนละอันที่ชื่อขึ้นต้นเหมือนกันต้องไม่โดนเหมารวม
        $this->assertFalse(BackupService::pathIsUnder(
            'C:\\a\\backups-old\\x.zip', 'C:\\a\\backups', true
        ));
    }

    public function test_a_backslash_in_a_linux_filename_is_left_alone(): void
    {
        // บนลินุกซ์ \ เป็นอักขระที่ใช้ตั้งชื่อไฟล์ได้จริง แปลงทิ้งจะกันไฟล์ออกโดยไม่มีใครรู้
        $this->assertFalse(BackupService::pathIsUnder(
            '/tmp/x/uploads\\backups/a.zip', '/tmp/x/uploads/backups', false
        ));

        $this->assertTrue(BackupService::pathIsUnder(
            '/tmp/x/uploads/backups/a.zip', '/tmp/x/uploads/backups', false
        ));
    }

    public function test_names_inside_the_zip_always_use_forward_slashes(): void
    {
        // zip ที่ทำบนวินโดวส์ต้องแตกบนลินุกซ์แล้วโครงสร้างโฟลเดอร์ไม่เพี้ยน
        $this->assertSame('slips/a.png', BackupService::relativeTo(
            'C:\\tmp\\up\\slips\\a.png', 'C:\\tmp\\up', true
        ));

        $this->assertSame('a.png', BackupService::relativeTo(
            'C:\\tmp\\up\\a.png', 'C:\\tmp/up/', true
        ));

        $this->assertSame('slips/a.png', BackupService::relativeTo(
            '/tmp/up/slips/a.png', '/tmp/up', false
        ));
    }

    /* ---------- สรุปสถานะ ---------- */

    public function test_never_having_backed_up_counts_as_stale(): void
    {
        foreach ($this->backups()->summary() as $row) {
            $this->assertTrue($row['is_stale'], $row['kind'].' ยังไม่เคยสำรองเลย ต้องถือว่าขาด ไม่ใช่ "ยังไม่มีข้อมูล"');
            $this->assertNull($row['last_at']);
        }
    }

    public function test_a_recent_success_clears_the_stale_flag(): void
    {
        BackupRun::create([
            'kind' => BackupKind::Database->value,
            'slot' => 'mon',
            'status' => BackupRun::SUCCESS,
            'finished_at' => Carbon::now()->subHour(),
        ]);

        $database = collect($this->backups()->summary())->firstWhere('kind', 'database');

        $this->assertFalse($database['is_stale']);
    }

    /* ---------- ตัวตั้งเวลา ---------- */

    public function test_the_scheduler_is_reported_down_only_after_it_goes_quiet(): void
    {
        $health = app(SystemHealthService::class);
        $key = (string) config('monitoring.scheduler.ping_key');

        // ยังไม่เคยเคาะเลย = เพิ่งติดตั้ง ยังไม่ถือว่าตาย ไม่งั้นจะเตือนทุกครั้งที่ deploy
        $this->assertFalse($health->schedulerIsDown());

        Cache::forever($key, Carbon::now()->subMinutes(2)->toIso8601String());
        $this->assertFalse($health->schedulerIsDown());

        Cache::forever($key, Carbon::now()->subHour()->toIso8601String());
        $this->assertTrue($health->schedulerIsDown());
    }

    /* ---------- หน้าเว็บและสิทธิ์ ---------- */

    public function test_owner_can_open_the_health_page(): void
    {
        $this->actingAs($this->makeUser('owner'));

        $this->get('/backoffice/health')->assertOk();
    }

    public function test_a_manager_cannot_open_the_health_page(): void
    {
        // หน้านี้บอก path ของไฟล์สำรองและ stack trace — ของที่ช่วยคนอยากเจาะระบบได้มาก
        $this->actingAs($this->makeUser('manager'));

        $this->get('/backoffice/health')->assertForbidden();
    }

    public function test_closing_a_case_from_the_page(): void
    {
        $owner = $this->makeUser('owner');
        $event = $this->monitor()->record(new \RuntimeException('พัง'));

        $this->actingAs($owner)
            ->post('/backoffice/health/errors/'.$event->id.'/resolve')
            ->assertRedirect();

        $fresh = $event->fresh();

        $this->assertNotNull($fresh->resolved_at);
        $this->assertSame($owner->id, $fresh->resolved_by);

        $this->actingAs($owner)
            ->post('/backoffice/health/errors/'.$event->id.'/reopen')
            ->assertRedirect();

        $this->assertNull($event->fresh()->resolved_at);
    }

    public function test_a_manager_cannot_close_a_case(): void
    {
        $event = $this->monitor()->record(new \RuntimeException('พัง'));

        $this->actingAs($this->makeUser('manager'))
            ->post('/backoffice/health/errors/'.$event->id.'/resolve')
            ->assertForbidden();

        $this->assertNull($event->fresh()->resolved_at);
    }

    public function test_pressing_backup_now_records_the_attempt_even_when_it_fails(): void
    {
        $owner = $this->makeUser('owner');

        $this->actingAs($owner)
            ->post('/backoffice/health/backup', ['only' => 'database'])
            ->assertRedirect();

        $run = BackupRun::where('kind', BackupKind::Database->value)->latest('id')->first();

        $this->assertNotNull($run, 'ความพยายามที่ล้มเหลวก็ต้องถูกบันทึก ไม่งั้นจะดูเหมือนไม่เคยมีใครลอง');
        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertSame($owner->id, $run->triggered_by);
    }

    /* ---------- ตัวช่วย ---------- */

    protected function monitor(): ErrorMonitor
    {
        return app(ErrorMonitor::class);
    }

    protected function backups(): BackupService
    {
        return app(BackupService::class);
    }

    /**
     * สร้าง exception จากบรรทัดเดียวกันทุกครั้ง
     *
     * fingerprint คิดจากไฟล์+บรรทัดด้วย ถ้า new กระจายอยู่คนละบรรทัดในเทสต์
     * มันจะกลายเป็นคนละเคสโดยที่ไม่ได้ตั้งใจ แล้วเทสต์จะบอกความจริงผิด ๆ
     */
    protected function errorAtSameLine(string $message): \RuntimeException
    {
        return new \RuntimeException($message);
    }

    protected function makeBranch(string $code, string $name): Branch
    {
        return Branch::create([
            'code' => $code,
            'name' => $name,
            'vat_rate' => 7,
            'vat_included' => true,
            'service_charge_rate' => 0,
            'rounding_mode' => 0,
            'business_day_start' => '05:00:00',
        ]);
    }

    protected function makeUser(string $role): User
    {
        return User::create([
            'branch_id' => $this->branch->id,
            'name' => "ผู้ใช้ {$role}",
            'email' => $role.'@test.local',
            'password' => 'password',
            'role' => $role,
        ]);
    }

    protected function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$entry;

            is_dir($full) ? $this->deleteTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
