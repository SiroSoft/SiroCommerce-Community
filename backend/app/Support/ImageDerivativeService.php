<?php

declare(strict_types=1);

namespace App\Support;

use Siro\Core\Env;
use Siro\Core\Storage;

/**
 * GD-based responsive image derivatives for merchant uploads.
 *
 * Produces 320/640/960 WebP renditions (quality 82), matching AVIF renditions
 * (quality 60) when the GD build supports AVIF, plus a 32px WebP LQIP data URI.
 * Files are stored beside the original using the SHA-256 of the source bytes,
 * so re-uploading the same file reuses existing renditions per format. Any
 * processing failure returns null instead of touching the original; an AVIF
 * failure only omits AVIF and keeps the WebP fallback.
 */
final class ImageDerivativeService
{
    /** @var array<int, int> */
    public const WIDTHS = [320, 640, 960];

    public const FORMAT_WEBP = 'webp';

    public const FORMAT_AVIF = 'avif';

    public const FORMAT = self::FORMAT_WEBP;

    public const QUALITY = 82;

    public const AVIF_QUALITY = 60;

    public const LQIP_WIDTH = 32;

    public const MAX_DIMENSION = 2000;

    /** Immutable cache policy for content-hashed derivative responses. */
    public const IMMUTABLE_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /**
     * @return array{variants: array<int, array{w: int, url: string, format: string}>, placeholder: string}|null
     */
    public function generate(string $sourcePath, string $storagePath): ?array
    {
        try {
            if (!function_exists('imagewebp')) {
                return null;
            }
            if (!is_file($sourcePath) || !is_readable($sourcePath)) {
                return null;
            }

            $info = @getimagesize($sourcePath);
            if (!is_array($info)) {
                return null;
            }
            $sourceWidth = (int) $info[0];
            $sourceHeight = (int) $info[1];
            $type = (int) $info[2];
            if ($sourceWidth < 1 || $sourceHeight < 1) {
                return null;
            }
            if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_AVIF], true)) {
                return null;
            }

            $hash = @hash_file('sha256', $sourcePath);
            if (!is_string($hash) || strlen($hash) !== 64) {
                return null;
            }

            $source = $this->load($sourcePath, $type);
            if ($source === null) {
                return null;
            }

            $supportsAvif = self::supportsAvif();

            try {
                $largest = max($sourceWidth, $sourceHeight);
                if ($largest > self::MAX_DIMENSION) {
                    $scale = self::MAX_DIMENSION / $largest;
                    $boundedWidth = max(1, (int) round($sourceWidth * $scale));
                    $boundedHeight = max(1, (int) round($sourceHeight * $scale));
                    $bounded = $this->resize($source, $boundedWidth, $boundedHeight);
                    if ($bounded === null) {
                        return null;
                    }
                    imagedestroy($source);
                    $source = $bounded;
                    $sourceWidth = $boundedWidth;
                    $sourceHeight = $boundedHeight;
                }

                $directory = $this->directory($storagePath);
                $variants = [];
                foreach (self::WIDTHS as $width) {
                    $height = max(1, (int) round($width * $sourceHeight / $sourceWidth));
                    $webpPath = $this->join($directory, $hash . '_' . $width . '.webp');
                    $avifPath = $this->join($directory, $hash . '_' . $width . '.avif');

                    $needsWebp = !Storage::exists($webpPath);
                    $needsAvif = $supportsAvif && !Storage::exists($avifPath);
                    $rendition = null;
                    if ($needsWebp || $needsAvif) {
                        $rendition = $this->resize($source, $width, $height);
                        if ($rendition === null) {
                            return null;
                        }
                    }

                    try {
                        if ($needsWebp && $rendition !== null) {
                            $encoded = $this->encode($rendition, self::FORMAT_WEBP);
                            if ($encoded === null || !Storage::put($webpPath, $encoded)) {
                                return null;
                            }
                        }
                        if ($needsAvif && $rendition !== null) {
                            // AVIF is additive: a failed encode keeps WebP only.
                            $encoded = $this->encode($rendition, self::FORMAT_AVIF);
                            if ($encoded !== null) {
                                Storage::put($avifPath, $encoded);
                            }
                        }
                    } finally {
                        if ($rendition !== null) {
                            imagedestroy($rendition);
                        }
                    }

                    $variants[] = ['w' => $width, 'url' => Storage::url($webpPath), 'format' => self::FORMAT_WEBP];
                    if ($supportsAvif && Storage::exists($avifPath)) {
                        $variants[] = ['w' => $width, 'url' => Storage::url($avifPath), 'format' => self::FORMAT_AVIF];
                    }
                }

                $lqipHeight = max(1, (int) round(self::LQIP_WIDTH * $sourceHeight / $sourceWidth));
                $lqip = $this->resize($source, self::LQIP_WIDTH, $lqipHeight);
                if ($lqip === null) {
                    return null;
                }
                try {
                    $lqipBytes = $this->encode($lqip, self::FORMAT_WEBP);
                } finally {
                    imagedestroy($lqip);
                }
                if ($lqipBytes === null) {
                    return null;
                }

                return [
                    'variants' => $variants,
                    'placeholder' => 'data:image/webp;base64,' . base64_encode($lqipBytes),
                ];
            } finally {
                imagedestroy($source);
            }
        } catch (\Throwable $error) {
            \Siro\Core\Logger::warning('media.derivative.failed: ' . $error->getMessage());
            return null;
        }
    }

    /** True when this GD build can encode AVIF derivatives. */
    public static function supportsAvif(): bool
    {
        if (!function_exists('imageavif') || !defined('IMG_AVIF')) {
            return false;
        }
        return (imagetypes() & (int) constant('IMG_AVIF')) !== 0;
    }

    /**
     * Prefix a same-origin `/storage/...` URL with `MEDIA_CDN_URL` when set.
     * Absolute URLs (including an already-CDN URL) are never rewritten.
     */
    public static function publicUrl(string $url): string
    {
        if (!str_starts_with($url, '/storage/')) {
            return $url;
        }
        $cdn = trim((string) Env::get('MEDIA_CDN_URL', ''));
        if ($cdn === '' || preg_match('#^https?://#i', $cdn) !== 1) {
            return $url;
        }
        return rtrim($cdn, '/') . $url;
    }

    /**
     * @param array<int, array{w: int, url: string, format: string}> $variants
     * @return array<int, array{w: int, url: string, format: string}>
     */
    public static function publicVariants(array $variants): array
    {
        foreach ($variants as $index => $variant) {
            $variants[$index]['url'] = self::publicUrl($variant['url']);
        }
        return $variants;
    }

    /** @return array<string, string> */
    public static function immutableCacheHeaders(): array
    {
        return ['Cache-Control' => self::IMMUTABLE_CACHE_CONTROL];
    }

    private function load(string $path, int $type): ?\GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            IMAGETYPE_AVIF => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default => false,
        };
        return $image instanceof \GdImage ? $image : null;
    }

    private function resize(\GdImage $source, int $width, int $height): ?\GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($width < 1 || $height < 1) {
            return null;
        }

        $target = imagecreatetruecolor($width, $height);
        if (!$target instanceof \GdImage) {
            return null;
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        return $target;
    }

    private function encode(\GdImage $image, string $format): ?string
    {
        ob_start();
        if ($format === self::FORMAT_AVIF) {
            if (!function_exists('imageavif')) {
                ob_end_clean();
                return null;
            }
            $ok = imageavif($image, null, self::AVIF_QUALITY);
        } else {
            $ok = imagewebp($image, null, self::QUALITY);
        }
        $bytes = ob_get_clean();
        if (!$ok || !is_string($bytes) || $bytes === '') {
            return null;
        }
        return $bytes;
    }

    private function directory(string $storagePath): string
    {
        $normalized = str_replace('\\', '/', trim($storagePath));
        $directory = dirname($normalized);
        return $directory === '.' || $directory === '/' ? '' : trim($directory, '/');
    }

    private function join(string $directory, string $filename): string
    {
        return $directory === '' ? $filename : $directory . '/' . $filename;
    }
}
