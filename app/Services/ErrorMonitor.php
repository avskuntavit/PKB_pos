<?php

namespace App\Services;

use App\Models\ErrorEvent;
use App\Support\CurrentBranch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * เก็บ error ของระบบไว้ในฐานข้อมูล เพื่อให้มีคนเห็นโดยไม่ต้องรอลูกค้าโทรมาบอก
 *
 * ── ทำไมไม่ใช้ log ไฟล์อย่างเดียว ────────────────────────────────────────
 * log ยังเขียนอยู่ตามเดิม ตัวนี้ไม่ได้มาแทน แต่ log อยู่ในคอนเทนเนอร์ที่ร้าน
 * ไม่มีใครเปิดดู และไฟล์เดียวมี error ปนกับ debug หลายหมื่นบรรทัด
 * ตารางนี้ตอบคำถามเดียวคือ "ตอนนี้มีอะไรพังอยู่บ้าง" แล้วเอาไปขึ้นหน้าเว็บได้
 *
 * ── สิ่งที่ไม่เก็บ ────────────────────────────────────────────────────────
 * ความผิดพลาดของ "ผู้ใช้" ไม่ใช่ของ "ระบบ" — กรอกฟอร์มไม่ครบ ไม่มีสิทธิ์ กดหน้าที่ไม่มี
 * ของพวกนี้เกิดทุกวันเป็นเรื่องปกติ ถ้าเก็บด้วยหน้าเฝ้าดูจะเต็มไปด้วยเรื่องที่ไม่ต้องทำอะไร
 * แล้วของจริงจะจมหายไป — หน้าเฝ้าดูที่มีของไม่สำคัญเยอะ ๆ เท่ากับไม่มีหน้าเฝ้าดู
 */
class ErrorMonitor
{
    /**
     * กันบันทึกซ้อนตัวเอง
     *
     * ถ้าฐานข้อมูลล่ม ทุก request จะโยน QueryException → เรียกตัวนี้ → เขียนลงฐานข้อมูล
     * → โยน QueryException อีก ถ้าไม่มีธงนี้จะวนจนหน่วยความจำหมด
     */
    protected static bool $recording = false;

    /** ข้อยกเว้นที่ถือว่าเป็นเรื่องปกติของการใช้งาน ไม่ใช่ระบบพัง */
    protected const IGNORED = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        TokenMismatchException::class,
        ModelNotFoundException::class,
    ];

    public function record(Throwable $e): ?ErrorEvent
    {
        if (! config('monitoring.errors.enabled') || self::$recording || $this->shouldIgnore($e)) {
            return null;
        }

        self::$recording = true;

        try {
            return $this->store($e);
        } catch (Throwable $inner) {
            /*
            | ตัวบันทึกพังเอง ต้องไม่กลืน error ตัวจริง
            | คืน null เฉย ๆ แล้วปล่อยให้ Laravel เขียน log ตามปกติต่อไป
            */
            Log::warning('บันทึก error ลงตารางไม่สำเร็จ', ['reason' => $inner->getMessage()]);

            return null;
        } finally {
            self::$recording = false;
        }
    }

    public function shouldIgnore(Throwable $e): bool
    {
        foreach (self::IGNORED as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        /*
        | 4xx = ผู้ใช้ขออะไรที่ให้ไม่ได้ (404 หน้าไม่มี, 403 ไม่มีสิทธิ์, 429 กดรัว)
        | 5xx = ระบบให้ไม่ได้ทั้งที่ควรให้ได้ — อันนี้ต้องเก็บ
        |
        | abort(500) ที่เราเขียนเองก็เข้าทางนี้ และควรเข้า เพราะแปลว่ามีเงื่อนไข
        | ที่เราคิดว่า "ไม่น่าเกิด" เกิดขึ้นจริง
        */
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() < 500;
        }

        return false;
    }

    protected function store(Throwable $e): ?ErrorEvent
    {
        $fingerprint = $this->fingerprint($e);
        $now = Carbon::now();

        $payload = array_merge($this->context(), [
            'level' => $e instanceof \Error ? 'critical' : 'error',
            'exception_class' => mb_substr(get_class($e), 0, 255),
            'message' => $this->shorten($e->getMessage(), 2000) ?: '(ไม่มีข้อความ)',
            'file' => mb_substr((string) $e->getFile(), 0, 500),
            'line' => (int) $e->getLine(),
            'trace' => $this->trace($e),
            'last_seen_at' => $now,
        ]);

        $existing = ErrorEvent::where('fingerprint', $fingerprint)->first();

        if ($existing) {
            /*
            | เกิดซ้ำ = นับเพิ่มในแถวเดิม และ **เปิดเคสกลับมาใหม่**
            |
            | ถ้าใครกดปิดไปแล้วแต่มันยังเกิดอยู่ แปลว่ายังไม่ได้แก้จริง
            | ไม่ปลุกกลับมาก็เท่ากับซ่อนบั๊กที่ยังทำงานผิดอยู่ตลอดไป
            |
            | ใช้ update ตรง ๆ กับ occurrences + 1 ในฐานข้อมูล ไม่อ่านมาบวกในโค้ด
            | เพราะหลาย request อาจเจอ error เดียวกันพร้อมกัน แล้วนับตกหาย
            */
            ErrorEvent::whereKey($existing->getKey())->update(array_merge($payload, [
                'occurrences' => DB::raw('occurrences + 1'),
                'resolved_at' => null,
                'resolved_by' => null,
            ]));

            return $existing->refresh();
        }

        if ($this->isFull()) {
            Log::warning('มีบั๊กที่ยังไม่ปิดเต็มเพดานแล้ว จึงไม่เปิดเคสใหม่', [
                'fingerprint' => $fingerprint,
                'class' => get_class($e),
            ]);

            return null;
        }

        return ErrorEvent::create(array_merge($payload, [
            'fingerprint' => $fingerprint,
            'occurrences' => 1,
            'first_seen_at' => $now,
        ]));
    }

    /**
     * ลายนิ้วมือของบั๊กหนึ่งตัว
     *
     * ถอดตัวเลขออกจากข้อความก่อน เพราะ error เดียวกันมักพก id หรือจำนวนติดมาด้วย
     * ("ไม่พบสินค้า 4821" กับ "ไม่พบสินค้า 4822" คือบั๊กตัวเดียวกัน)
     * ถ้าไม่ถอด จะได้เคสใหม่ทุกครั้งที่เกิด แล้วหน้าเฝ้าดูก็ใช้ไม่ได้
     */
    public function fingerprint(Throwable $e): string
    {
        return hash('sha256', implode('|', [
            get_class($e),
            $e->getFile(),
            $e->getLine(),
            $this->normalise($e->getMessage()),
        ]));
    }

    protected function normalise(string $message): string
    {
        $message = preg_replace('/\d+/', '#', $message) ?? $message;
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;

        return mb_substr(trim($message), 0, 200);
    }

    /** @return array<string, mixed> */
    protected function context(): array
    {
        $context = ['url' => null, 'method' => null, 'user_id' => null, 'branch_id' => null];

        try {
            if (app()->runningInConsole()) {
                $argv = $_SERVER['argv'] ?? [];
                $context['url'] = mb_substr('console: '.implode(' ', array_slice($argv, 1)), 0, 500);

                return $context;
            }

            $request = request();

            $context['url'] = mb_substr((string) $request->fullUrl(), 0, 500);
            $context['method'] = mb_substr($request->method(), 0, 10);
            // เก็บเฉพาะพนักงาน ไม่เก็บ id ลูกค้า — คนละ guard คนละชุดเลข ปนกันแล้วอ่านผิด
            $context['user_id'] = $request->user()?->getKey();
            $context['branch_id'] = CurrentBranch::get()?->id;
        } catch (Throwable) {
            // อ่านบริบทไม่ได้ก็ไม่เป็นไร ตัว error สำคัญกว่าบริบทของมัน
        }

        return $context;
    }

    protected function trace(Throwable $e): string
    {
        return $this->shorten($e->getTraceAsString(), (int) config('monitoring.errors.trace_chars', 4000), false);
    }

    protected function isFull(): bool
    {
        $max = (int) config('monitoring.errors.max_open');

        return $max > 0 && ErrorEvent::open()->count() >= $max;
    }

    protected function shorten(string $value, int $limit, bool $collapse = true): string
    {
        if ($collapse) {
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        }

        return mb_substr(trim($value), 0, $limit);
    }

    /** ลบเคสที่ปิดไปแล้วและเก่าเกินกำหนด — เรียกจากตารางเวลาวันละครั้ง */
    public function prune(?Carbon $now = null): int
    {
        $days = (int) config('monitoring.errors.prune_days');

        if ($days <= 0) {
            return 0;
        }

        return ErrorEvent::whereNotNull('resolved_at')
            ->where('resolved_at', '<', ($now ?? Carbon::now())->copy()->subDays($days))
            ->delete();
    }
}
