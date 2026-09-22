<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * รูปทั้งหมดที่ร้านอัปโหลด — รูปเมนู / รูปปก / โลโก้
 *
 * เก็บลง disk public แล้วคืน path (/storage/...) ที่เอาไปใส่ <img src> ได้เลย
 * ไม่เก็บ URL เต็ม เพราะถ้าเปลี่ยนโดเมนหรือสลับ localhost กับ 127.0.0.1 รูปจะพัง
 *
 * ต้องรัน php artisan storage:link ครั้งเดียวก่อนใช้งาน
 */
class ImageService
{
    /**
     * สเปกของรูปแต่ละชนิด
     *
     * width/height  ขนาดที่แนะนำให้อัปโหลด
     * store_width   ความกว้างสูงสุดหลังระบบย่อ
     * ratio         อัตราส่วนที่หน้าบ้านใช้จริง อัปโหลดผิดส่วนจะโดนครอบตัด
     */
    public const PRESETS = [
        'product' => [
            'dir' => 'products',
            'label' => 'รูปเมนู',
            'width' => 800,
            'height' => 800,
            'ratio' => '1:1',
            'store_width' => 1200,
            'max_kb' => 4096,
        ],
        'cover' => [
            'dir' => 'branches',
            'label' => 'รูปปกร้าน',
            'width' => 1600,
            'height' => 900,
            'ratio' => '16:9',
            'store_width' => 1600,
            'max_kb' => 4096,
        ],
        'logo' => [
            'dir' => 'branches',
            'label' => 'โลโก้ร้าน',
            'width' => 512,
            'height' => 512,
            'ratio' => '1:1',
            'store_width' => 512,
            'max_kb' => 2048,
        ],
    ];

    protected const QUALITY = 82;

    /** ส่งให้หน้าเว็บเอาไปเขียนข้อความกำกับใต้ปุ่มอัปโหลด */
    public static function spec(string $preset): array
    {
        $p = self::PRESETS[$preset];

        return [
            'label' => $p['label'],
            'width' => $p['width'],
            'height' => $p['height'],
            'ratio' => $p['ratio'],
            'max_mb' => round($p['max_kb'] / 1024, $p['max_kb'] % 1024 === 0 ? 0 : 1),
            'formats' => 'jpg / png / webp',
            'hint' => sprintf(
                'แนะนำ %d × %d px (อัตราส่วน %s) · jpg / png / webp · ไม่เกิน %s MB',
                $p['width'],
                $p['height'],
                $p['ratio'],
                rtrim(rtrim(number_format($p['max_kb'] / 1024, 1), '0'), '.'),
            ),
        ];
    }

    /** กฎ validate ของช่องอัปโหลด ให้ตรงกับสเปกเดียวกัน */
    public static function rules(string $preset): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PRESETS[$preset]['max_kb']];
    }

    /**
     * เก็บรูปใหม่ ถ้าส่ง $replacing มาด้วยจะลบรูปเดิมทิ้งให้
     *
     * @return string path ที่เก็บลงคอลัมน์ เช่น /storage/products/xxx.jpg
     */
    public function store(UploadedFile $file, string $preset, ?string $replacing = null): string
    {
        $spec = self::PRESETS[$preset];
        $binary = $this->downscale($file, $spec['store_width']);

        if ($binary === null) {
            // GD ใช้ไม่ได้บนเครื่องนี้ — เก็บไฟล์เดิมไปก่อน ดีกว่าอัปโหลดไม่ได้เลย
            $path = $file->store($spec['dir'], 'public');
        } else {
            $path = $spec['dir'].'/'.Str::random(32).'.jpg';
            Storage::disk('public')->put($path, $binary);
        }

        // ลบรูปเดิมหลังเก็บรูปใหม่สำเร็จแล้ว ถ้าพังกลางทางจะได้ยังมีรูปเดิมอยู่
        $this->delete($replacing, $preset);

        return $this->publicPath($path);
    }

    public function delete(?string $url, string $preset): void
    {
        $path = $this->pathFromUrl($url, $preset);

        if ($path !== null && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Storage::url() คืน URL เต็มรวมโดเมนตาม APP_URL
     * เก็บเฉพาะส่วน path จะได้ไม่ผูกกับโดเมน
     */
    protected function publicPath(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    /**
     * /storage/products/x.jpg  ->  products/x.jpg
     *
     * คืน null ถ้าไม่ใช่รูปในโฟลเดอร์ของ preset นั้น (เช่นลิงก์เว็บอื่น) จะได้ไม่ลบมั่ว
     */
    protected function pathFromUrl(?string $url, string $preset): ?string
    {
        if (blank($url)) {
            return null;
        }

        $dir = self::PRESETS[$preset]['dir'];
        $prefix = $this->publicPath($dir).'/';

        if (! str_starts_with($url, $prefix)) {
            return null;
        }

        $name = substr($url, strlen($prefix));

        // กัน ../ ที่จะพาไปลบไฟล์นอกโฟลเดอร์รูป
        if ($name === '' || str_contains($name, '/') || str_contains($name, '..')) {
            return null;
        }

        return $dir.'/'.$name;
    }

    /**
     * ย่อรูปและแปลงเป็น jpg
     *
     * คืน null เมื่อ GD ไม่มีหรืออ่านไฟล์ไม่ออก ให้ผู้เรียกไปใช้ทางสำรอง
     */
    protected function downscale(UploadedFile $file, int $maxWidth): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $raw = @file_get_contents($file->getRealPath());

        if ($raw === false) {
            return null;
        }

        $source = @imagecreatefromstring($raw);

        if ($source === false) {
            return null;
        }

        $source = $this->applyExifRotation($source, $file);

        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1, $maxWidth / max(1, $w));

        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($tw, $th);

        // png/webp ที่พื้นหลังโปร่งใส พอบันทึกเป็น jpg พื้นจะกลายเป็นดำ ทาขาวไว้ก่อน
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $tw, $th, $w, $h);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $binary = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        return $binary === '' ? null : $binary;
    }

    /**
     * รูปถ่ายแนวตั้งจากมือถือเก็บเป็นแนวนอน + ธง EXIF บอกให้หมุน
     * ถ้าไม่หมุนตามก่อนย่อ รูปที่ได้จะตะแคง
     */
    protected function applyExifRotation(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || $file->getMimeType() !== 'image/jpeg') {
            return $image;
        }

        $exif = @exif_read_data($file->getRealPath());
        $orientation = (int) ($exif['Orientation'] ?? 0);

        $degrees = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }
}
