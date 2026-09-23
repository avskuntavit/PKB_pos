<?php

namespace App\Services;

use App\Enums\BackupKind;
use App\Models\BackupRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * สำรองข้อมูล
 *
 * ── สองอย่างที่ต้องแยกกันให้ชัด ─────────────────────────────────────────
 * ฐานข้อมูล : แอป **สั่ง** ให้ SQL Server สำรอง แล้ว SQL Server เขียนไฟล์ลงดิสก์ของมันเอง
 *             แอปไม่เคยเห็นไฟล์นั้น จึงอ่านขนาดไฟล์ผ่าน RESTORE HEADERONLY แทนการ stat
 * ไฟล์      : แอป zip เองจากโฟลเดอร์ใน storage ของตัวเอง
 *
 * ── ทำไมไม่ตั้งชื่อไฟล์ตามวันที่ ───────────────────────────────────────
 * ตั้งชื่อตามวันที่ = ไฟล์เพิ่มทุกวันจนดิสก์เต็ม แล้วต้องมีคนคอยลบของเก่า
 * แต่แอปลบไฟล์บนเครื่อง SQL Server ไม่ได้ (คนละเครื่อง) และ xp_delete_file
 * ก็เป็นคำสั่งที่ไม่มีเอกสารรองรับ พึ่งไม่ได้
 *
 * จึงใช้ "ช่องเก็บ" แบบหมุนทับแทน — รายวัน 7 ช่อง (mon..sun) รายเดือน 12 ช่อง (m01..m12)
 * WITH INIT สั่งให้เขียนทับไฟล์เดิมในช่องนั้น ระบบจึงมีไฟล์มากที่สุด 19 ไฟล์ตลอดกาล
 * ย้อนได้ 7 วันล่าสุด และสิ้นเดือนย้อนหลัง 12 เดือน โดยไม่ต้องลบอะไรเลยสักครั้ง
 */
class BackupService
{
    /** ISO day (1 = จันทร์) -> ชื่อช่อง — ไม่ใช้ format('D') เพราะไม่อยากพึ่งค่า locale */
    protected const DAY_SLOTS = [
        1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun',
    ];

    /** ข้อความ error ที่เก็บลงตาราง — ยาวกว่านี้ไม่ช่วยให้แก้ง่ายขึ้น */
    protected const MAX_ERROR_CHARS = 1000;

    /**
     * สำรองตามที่ตั้งค่าไว้
     *
     * @param  array<int, BackupKind>|null  $kinds  ไม่ระบุ = ทุกอย่างที่เปิดใช้งาน
     * @return array<int, BackupRun>
     */
    public function run(?array $kinds = null, ?int $userId = null, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();
        $kinds ??= $this->enabledKinds();

        $runs = [];

        foreach ($kinds as $kind) {
            if ($kind === BackupKind::Database) {
                $runs[] = $this->backupDatabase($this->dailySlot($now), $userId, $now);

                /*
                | ช่องรายเดือนเขียนเมื่อเดือนนี้ยังไม่มีสำเร็จสักครั้ง
                |
                | ไม่ผูกกับ "วันที่ 1" เพราะถ้าคืนวันที่ 1 ไฟดับหรือเครื่องปิด
                | เดือนนั้นจะไม่มีไฟล์รายเดือนเลย แล้วไม่มีใครรู้จนถึงตอนที่ต้องใช้
                */
                if ($this->needsMonthly($now)) {
                    $runs[] = $this->backupDatabase($this->monthlySlot($now), $userId, $now);
                }

                continue;
            }

            $runs[] = $this->backupFiles($kind, $this->dailySlot($now), $userId, $now);
        }

        return $runs;
    }

    /** @return array<int, BackupKind> */
    public function enabledKinds(): array
    {
        $kinds = [BackupKind::Database];

        if (config('monitoring.backup.uploads')) {
            $kinds[] = BackupKind::Uploads;
        }

        if (config('monitoring.backup.exports')) {
            $kinds[] = BackupKind::Exports;
        }

        return $kinds;
    }

    /*
    |--------------------------------------------------------------------------
    | ฐานข้อมูล
    |--------------------------------------------------------------------------
    */

    public function backupDatabase(string $slot, ?int $userId = null, ?CarbonInterface $now = null): BackupRun
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();

        /*
        | สร้างแถวเป็น "ล้มเหลว" ไว้ก่อน แล้วค่อยพลิกเป็นสำเร็จตอนจบ
        |
        | ถ้าเครื่องดับหรือ process ถูกฆ่ากลางทาง แถวจะค้างเป็นล้มเหลว ซึ่งถูกต้อง
        | ตรงข้ามกับการสร้างเป็น "กำลังทำ" แล้วไม่มีใครมาปิด — ซึ่งอ่านแล้วแยกไม่ออก
        | ว่ากำลังทำอยู่จริงหรือค้างมาตั้งแต่เดือนที่แล้ว
        */
        $run = BackupRun::create([
            'kind' => BackupKind::Database->value,
            'slot' => $slot,
            'status' => BackupRun::FAILED,
            'started_at' => $now,
            'triggered_by' => $userId,
        ]);

        $startedAt = microtime(true);

        try {
            $connection = DB::connection();

            if ($connection->getDriverName() !== 'sqlsrv') {
                throw new RuntimeException(
                    'คำสั่งสำรองฐานข้อมูลนี้เขียนไว้สำหรับ SQL Server เท่านั้น '
                    .'(ตอนนี้ต่ออยู่กับ '.$connection->getDriverName().') '
                    .'ฐานข้อมูลชนิดอื่นต้องสำรองด้วยเครื่องมือของตัวเอง'
                );
            }

            $database = (string) $connection->getDatabaseName();
            $file = $this->sqlFilePath($database, $slot);

            $connection->unprepared($this->backupStatement($database, $file, $slot));

            $size = $this->backupSize($connection, $file);

            $verifiedAt = null;

            if (config('monitoring.backup.verify')) {
                /*
                | อ่านไฟล์กลับมาทั้งก้อนแล้วตรวจ checksum
                |
                | "สำรองเสร็จ" กับ "ไฟล์กู้ได้" คนละเรื่องกัน ดิสก์ที่กำลังจะพัง
                | เขียนจนจบได้ตามปกติ แล้วไปพังตอนอ่าน ซึ่งคือตอนที่เราต้องใช้พอดี
                */
                $connection->unprepared(
                    'RESTORE VERIFYONLY FROM DISK = N'.$this->quote($file).' WITH CHECKSUM'
                );

                $verifiedAt = Carbon::now();
            }

            $run->update([
                'status' => BackupRun::SUCCESS,
                'path' => $file,
                'size_bytes' => $size ?: null,
                'verified_at' => $verifiedAt,
                'duration_ms' => $this->elapsed($startedAt),
                'finished_at' => Carbon::now(),
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->fail($run, $e, $startedAt);
        }

        return $run->refresh();
    }

    protected function backupStatement(string $database, string $file, string $slot): string
    {
        /*
        | ต่อสตริงเอง ไม่ใช้ binding
        |
        | BACKUP / RESTORE เป็นคำสั่งที่รันผ่าน prepared statement ไม่ได้ในบางเวอร์ชัน
        | ของไดรเวอร์ ค่าที่ใส่มาจาก config ไม่ใช่จากผู้ใช้ และ escape ทั้งคู่แล้ว
        |   ชื่อฐานข้อมูล : ]] ตามกติกา delimited identifier
        |   path         : '' ตามกติกา string literal
        */
        $options = ['FORMAT', 'INIT', 'CHECKSUM', 'STATS = 0'];

        // ต้องมาก่อน NAME ไม่ได้ แต่ลำดับใน WITH ไม่สำคัญ วางไว้ตรงนี้เพื่ออ่านง่าย
        if (config('monitoring.backup.compression')) {
            $options[] = 'COMPRESSION';
        }

        $options[] = 'NAME = N'.$this->quote('PKB POS '.$slot);

        return 'BACKUP DATABASE ['.str_replace(']', ']]', $database).'] '
            .'TO DISK = N'.$this->quote($file).' '
            .'WITH '.implode(', ', $options);
    }

    /**
     * ขนาดไฟล์สำรอง
     *
     * แอปอยู่คนละเครื่องกับไฟล์ จึง stat ไม่ได้ ต้องถาม SQL Server เอา
     * ถ้าอ่านไม่ได้ก็ไม่ถือว่าการสำรองล้มเหลว — แค่ไม่รู้ขนาด
     */
    protected function backupSize(Connection $connection, string $file): int
    {
        try {
            $sql = 'RESTORE HEADERONLY FROM DISK = N'.$this->quote($file);

            // ใช้ PDO ตรง ๆ ด้วยเหตุผลเดียวกับ backupStatement()
            $row = $connection->getPdo()->query($sql)?->fetch(\PDO::FETCH_ASSOC) ?: [];

            $size = config('monitoring.backup.compression')
                ? ($row['CompressedBackupSize'] ?? $row['BackupSize'] ?? 0)
                : ($row['BackupSize'] ?? 0);

            return (int) $size;
        } catch (\Throwable $e) {
            Log::warning('อ่านขนาดไฟล์สำรองไม่ได้', ['file' => $file, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    public function sqlFilePath(string $database, string $slot): string
    {
        $dir = rtrim((string) config('monitoring.backup.sql_path'), " \t/\\");

        if ($dir === '') {
            throw new RuntimeException('ยังไม่ได้ตั้ง BACKUP_SQL_PATH — ต้องเป็นโฟลเดอร์บนเครื่อง SQL Server ที่ service account ของมันเขียนได้');
        }

        return $dir.$this->separatorFor($dir).$this->safeName($database).'-'.$slot.'.bak';
    }

    /** \\nas\share หรือ C:\... = วินโดวส์ · /var/opt/... = ลินุกซ์ */
    protected function separatorFor(string $dir): string
    {
        return str_contains($dir, '\\') || preg_match('/^[A-Za-z]:/', $dir) === 1 ? '\\' : '/';
    }

    /*
    |--------------------------------------------------------------------------
    | ไฟล์ (รูปเมนู สลิป ไฟล์ส่งบัญชี)
    |--------------------------------------------------------------------------
    */

    public function backupFiles(BackupKind $kind, string $slot, ?int $userId = null, ?CarbonInterface $now = null): BackupRun
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();

        $run = BackupRun::create([
            'kind' => $kind->value,
            'slot' => $slot,
            'status' => BackupRun::FAILED,
            'started_at' => $now,
            'triggered_by' => $userId,
        ]);

        $startedAt = microtime(true);

        try {
            if (! class_exists(ZipArchive::class)) {
                throw new RuntimeException('ไม่มี PHP extension "zip" บนเครื่องนี้ — ติดตั้งก่อนถึงจะสำรองไฟล์ได้');
            }

            $source = $kind->sourcePath();

            if (! $source || ! is_dir($source)) {
                // ยังไม่เคยมีไฟล์ในหมวดนี้เลย เช่นร้านที่ยังไม่เคยส่งข้อมูลให้บัญชี — ไม่ใช่ความผิดพลาด
                $run->update([
                    'status' => BackupRun::SKIPPED,
                    'error' => 'ยังไม่มีโฟลเดอร์ '.($source ?: '-').' จึงยังไม่มีอะไรให้สำรอง',
                    'duration_ms' => $this->elapsed($startedAt),
                    'finished_at' => Carbon::now(),
                ]);

                return $run->refresh();
            }

            $target = $this->filesTargetPath($kind, $slot);
            $files = $this->collectFiles($source, dirname($target));

            if ($files === []) {
                $run->update([
                    'status' => BackupRun::SKIPPED,
                    'error' => 'โฟลเดอร์ว่าง ไม่มีไฟล์ให้สำรอง',
                    'duration_ms' => $this->elapsed($startedAt),
                    'finished_at' => Carbon::now(),
                ]);

                return $run->refresh();
            }

            $this->guardSize($files);

            /*
            | เขียนลงไฟล์ .tmp ก่อนแล้วค่อยเปลี่ยนชื่อทับของเดิม
            |
            | ถ้าเขียนทับตรง ๆ แล้วดับกลางคัน ไฟล์สำรองของสัปดาห์ที่แล้วจะหายไปด้วย
            | เหลือไฟล์ครึ่ง ๆ กลาง ๆ ที่แตกไม่ได้ — เสียของเก่าไปแลกกับของใหม่ที่ใช้ไม่ได้
            */
            $temp = $target.'.tmp';
            $this->writeZip($temp, $source, $files);

            if (! @rename($temp, $target)) {
                @unlink($temp);
                throw new RuntimeException('ย้ายไฟล์ zip ทับของเดิมไม่สำเร็จ: '.$target);
            }

            $run->update([
                'status' => BackupRun::SUCCESS,
                'path' => $target,
                'size_bytes' => (int) (@filesize($target) ?: 0) ?: null,
                'duration_ms' => $this->elapsed($startedAt),
                'finished_at' => Carbon::now(),
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->fail($run, $e, $startedAt);
        }

        return $run->refresh();
    }

    public function filesTargetPath(BackupKind $kind, string $slot): string
    {
        $dir = rtrim((string) config('monitoring.backup.files_path'), '/\\');

        if ($dir === '') {
            throw new RuntimeException('ยังไม่ได้ตั้ง BACKUP_FILES_PATH');
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('สร้างโฟลเดอร์เก็บไฟล์สำรองไม่ได้: '.$dir);
        }

        return $dir.DIRECTORY_SEPARATOR.$kind->value.'-'.$slot.'.zip';
    }

    /**
     * ไฟล์ทั้งหมดใต้ $source
     *
     * @return array<int, string> path เต็มของแต่ละไฟล์
     */
    protected function collectFiles(string $source, string $excludeDir): array
    {
        $source = rtrim($source, '/\\');
        $excludeDir = rtrim($excludeDir, '/\\');
        $windows = DIRECTORY_SEPARATOR === '\\';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files = [];

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (! $item->isFile()) {
                continue;
            }

            $path = $item->getPathname();

            /*
            | ห้าม zip ตัวเองเข้าไปในตัวเอง
            | ถ้ามีคนตั้ง BACKUP_FILES_PATH ไว้ใต้ storage/app/public เพื่อให้โหลดผ่านเว็บได้
            | รอบถัดไปจะ zip ไฟล์สำรองรอบก่อนเข้าไปด้วย แล้วไฟล์จะโตเป็นทวีคูณทุกคืน
            */
            if (self::pathIsUnder($path, $excludeDir, $windows)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /** @param  array<int, string>  $files */
    protected function guardSize(array $files): void
    {
        $limit = (int) config('monitoring.backup.max_files_mb') * 1048576;

        if ($limit <= 0) {
            return;
        }

        $total = 0;

        foreach ($files as $file) {
            $total += (int) (@filesize($file) ?: 0);
        }

        if ($total > $limit) {
            throw new RuntimeException(
                'ไฟล์ที่จะสำรองรวม '.round($total / 1048576).' MB เกินเพดาน '
                .round($limit / 1048576).' MB ที่ตั้งไว้ — ย้ายไฟล์เก่าออกก่อน '
                .'หรือขยายค่า BACKUP_MAX_FILES_MB ถ้าตั้งใจให้ใหญ่ขนาดนี้'
            );
        }
    }

    /** @param  array<int, string>  $files */
    protected function writeZip(string $temp, string $source, array $files): void
    {
        @unlink($temp);

        $zip = new ZipArchive;
        $opened = $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException('เปิดไฟล์ zip เพื่อเขียนไม่ได้ (รหัส '.$opened.'): '.$temp);
        }

        $windows = DIRECTORY_SEPARATOR === '\\';

        foreach ($files as $file) {
            // ชื่อใน zip ใช้ / เสมอ เพื่อให้ไฟล์ที่ zip บนวินโดวส์แตกบนลินุกซ์แล้วโครงสร้างไม่เพี้ยน
            $zip->addFile($file, self::relativeTo($file, $source, $windows));
        }

        if (! $zip->close()) {
            throw new RuntimeException('ปิดไฟล์ zip ไม่สำเร็จ — ดิสก์อาจเต็ม: '.$temp);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | สรุปสถานะให้หน้าเฝ้าดู
    |--------------------------------------------------------------------------
    */

    /** @return array<int, array<string, mixed>> */
    public function summary(): array
    {
        $staleHours = (int) config('monitoring.backup.stale_hours');
        $out = [];

        foreach ($this->enabledKinds() as $kind) {
            $last = BackupRun::where('kind', $kind->value)
                ->whereIn('status', [BackupRun::SUCCESS, BackupRun::SKIPPED])
                ->orderByDesc('finished_at')
                ->first();

            $lastFailure = BackupRun::where('kind', $kind->value)
                ->where('status', BackupRun::FAILED)
                ->orderByDesc('id')
                ->first();

            $ageHours = $last?->finished_at
                ? abs((float) Carbon::now()->diffInHours($last->finished_at))
                : null;

            $out[] = [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
                'last_at' => $last?->finished_at?->toIso8601String(),
                'last_status' => $last?->status,
                'age_hours' => $ageHours === null ? null : round($ageHours, 1),
                'size' => $last?->sizeLabel(),
                'duration' => $last?->durationLabel(),
                'verified' => (bool) $last?->verified_at,
                'path' => $last?->path,
                // ยังไม่เคยสำรองเลย ก็ถือว่าขาดเหมือนกัน ไม่ใช่ "ยังไม่มีข้อมูล"
                'is_stale' => $ageHours === null || $ageHours > $staleHours,
                'last_error' => $lastFailure && (! $last || $lastFailure->id > $last->id)
                    ? $lastFailure->error
                    : null,
            ];
        }

        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | ตัวช่วย
    |--------------------------------------------------------------------------
    */

    public function dailySlot(CarbonInterface $now): string
    {
        return self::DAY_SLOTS[$now->dayOfWeekIso] ?? 'mon';
    }

    public function monthlySlot(CarbonInterface $now): string
    {
        return 'm'.str_pad((string) $now->month, 2, '0', STR_PAD_LEFT);
    }

    public function needsMonthly(CarbonInterface $now): bool
    {
        return ! BackupRun::where('kind', BackupKind::Database->value)
            ->where('slot', $this->monthlySlot($now))
            ->where('status', BackupRun::SUCCESS)
            ->where('finished_at', '>=', Carbon::parse($now)->startOfMonth())
            ->exists();
    }

    protected function fail(BackupRun $run, \Throwable $e, float $startedAt): void
    {
        $run->update([
            'status' => BackupRun::FAILED,
            'error' => $this->shorten($e->getMessage()),
            'duration_ms' => $this->elapsed($startedAt),
            'finished_at' => Carbon::now(),
        ]);

        Log::error('สำรองข้อมูลไม่สำเร็จ', [
            'kind' => $run->kind instanceof BackupKind ? $run->kind->value : $run->kind,
            'slot' => $run->slot,
            'error' => $e->getMessage(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | การเทียบ path ข้ามระบบปฏิบัติการ
    |--------------------------------------------------------------------------
    |
    | ── ทำไมต้องมีสองเมธอดนี้ แทนที่จะใช้ str_starts_with เฉย ๆ ──────────
    | เคสนี้ไม่ใช่ทฤษฎี — เทสต์บนวินโดวส์จับได้ว่าไฟล์สำรองรอบก่อนถูก zip เข้าไป
    | ในรอบถัดไป ทั้งที่รันบนลินุกซ์แล้วผ่านหมด
    |
    | สาเหตุคือ path ก้อนเดียวกันปนสองเครื่องหมายได้ง่ายมากบนวินโดวส์:
    | ค่าใน config เขียนด้วย / แต่ RecursiveDirectoryIterator คืน \
    | เทียบตรง ๆ จึงไม่ตรงกันทั้งที่เป็นโฟลเดอร์เดียวกัน แล้วด่านกันก็หลุด
    | ซ้ำร้ายวินโดวส์ยังไม่สนตัวพิมพ์เล็กใหญ่ของ path อีก
    |
    | บนลินุกซ์ **ไม่** แปลง \ เป็น / เพราะ \ เป็นอักขระที่ใช้ตั้งชื่อไฟล์ได้จริง
    | แปลงทิ้งจะทำให้ไฟล์ชื่อแปลก ๆ ถูกกันออกจากการสำรองโดยไม่มีใครรู้
    |
    | รับ $windows เป็นพารามิเตอร์ ไม่อ่าน DIRECTORY_SEPARATOR ข้างใน
    | เพื่อให้เทสต์ตรวจพฤติกรรมฝั่งวินโดวส์ได้จากเครื่องลินุกซ์ด้วย
    */

    /** $path อยู่ใต้โฟลเดอร์ $dir หรือไม่ */
    public static function pathIsUnder(string $path, string $dir, bool $windows): bool
    {
        $dir = self::comparable($dir, $windows);

        if ($dir === '') {
            return false;
        }

        $path = self::comparable($path, $windows);
        $prefix = $dir.'/';

        return $windows
            ? stripos($path, $prefix) === 0
            : str_starts_with($path, $prefix);
    }

    /** ชื่อที่จะใช้ใน zip — path ของไฟล์เทียบกับโฟลเดอร์ต้นทาง คั่นด้วย / เสมอ */
    public static function relativeTo(string $file, string $source, bool $windows): string
    {
        $prefix = self::comparable($source, $windows).'/';
        $path = self::comparable($file, $windows);

        $inside = $windows ? stripos($path, $prefix) === 0 : str_starts_with($path, $prefix);

        if ($inside) {
            return substr($path, strlen($prefix));
        }

        /*
        | ไม่ควรเกิด เพราะ collectFiles() คืนเฉพาะไฟล์ใต้ source อยู่แล้ว
        | แต่ถ้าเกิด ให้เหลือแค่ชื่อไฟล์ ดีกว่าเอา path เต็มของเครื่องไปใส่ใน zip
        |
        | ตัดเอง ไม่ใช้ basename() เพราะ basename บนลินุกซ์ไม่รู้จัก \ เป็นตัวคั่น
        | ส่ง path แบบวินโดวส์เข้าไปจะได้ทั้งก้อนกลับมาเป็น "ชื่อไฟล์"
        */
        $cut = strrpos($path, '/');

        return $cut === false ? $path : substr($path, $cut + 1);
    }

    protected static function comparable(string $path, bool $windows): string
    {
        return rtrim($windows ? str_replace('\\', '/', $path) : $path, '/');
    }

    protected function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    protected function shorten(string $message): string
    {
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        return mb_substr($message, 0, self::MAX_ERROR_CHARS);
    }

    protected function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    protected function safeName(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $value) ?? '';

        return trim($safe, '_') ?: 'database';
    }
}
