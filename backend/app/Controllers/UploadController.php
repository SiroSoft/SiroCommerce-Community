<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\ImageDerivativeService;
use Siro\Core\Controller;
use Siro\Core\Env;
use Siro\Core\Logger;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Storage;
use Siro\Core\UploadedFile;

/**
 * Console file uploads (avatars, CMS images).
 *
 * Only raster images are accepted. The core uploader allowlist is broader
 * (svg/zip/doc/...), so this controller enforces its own content-based policy
 * before anything reaches Storage.
 */
final class UploadController extends Controller
{
    /** Canonical extensions we are willing to serve back. */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    /** MIME types detected from file content (not the client-supplied type). */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    private const DEFAULT_MAX_BYTES = 8 * 1024 * 1024;

    /** @var array<string, string> */
    private const EXTENSION_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public function store(Request $request): Response
    {
        return $this->storeImage(
            $request,
            'uploads',
            ['file', 'avatar', 'image'],
            'file',
            'A file is required',
            'Uploaded'
        );
    }

    public function avatar(Request $request): Response
    {
        return $this->storeImage(
            $request,
            'avatars',
            ['avatar', 'file', 'image'],
            'avatar',
            'An avatar file is required',
            'Avatar uploaded'
        );
    }

    /** @param array<int, string> $keys */
    private function storeImage(
        Request $request,
        string $directory,
        array $keys,
        string $field,
        string $missingMessage,
        string $successMessage
    ): Response {
        $file = null;
        foreach ($keys as $key) {
            $file = $request->file($key);
            if ($file !== null) {
                break;
            }
        }

        if ($file === null) {
            return $this->error($missingMessage, 422, [$field => [$missingMessage]]);
        }

        $maxBytes = $this->maxBytes();
        $rejection = $this->inspectImage($file, $maxBytes);
        if ($rejection !== null) {
            $this->logRejection($request, $file, $rejection);
            return $this->error($rejection['message'], 422, [$field => [$rejection['message']]]);
        }

        $path = $file->getPathname();
        $mime = $this->detectMime($path);
        $extension = self::MIME_EXTENSIONS[$mime];
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $storagePath = $directory . '/' . $filename;
        $derivatives = null;

        try {
            $content = @file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException('Unable to read uploaded file.');
            }
            if (Storage::put($storagePath, $content) !== true) {
                throw new \RuntimeException('Unable to persist uploaded file.');
            }
            $derivatives = (new ImageDerivativeService())->generate($path, $storagePath);
        } catch (\Throwable) {
            Logger::security('upload.failed', [
                'reason' => 'storage_failed',
                'mime' => $mime,
                'size_bytes' => $this->actualSize($path),
                'ip' => $request->ip(),
            ]);
            return $this->error('Upload failed', 422, [$field => ['Upload failed']]);
        } finally {
            // The temp upload is never served and never needs to linger.
            if (is_file($path)) {
                @unlink($path);
            }
        }

        Logger::security('upload.accepted', [
            'mime' => $mime,
            'size_bytes' => strlen($content),
            'ip' => $request->ip(),
        ]);

        $url = ImageDerivativeService::publicUrl(Storage::url($storagePath));
        $payload = ['url' => $url, 'path' => $url];
        if ($derivatives !== null) {
            $payload['variants'] = ImageDerivativeService::publicVariants($derivatives['variants']);
            $payload['placeholder'] = $derivatives['placeholder'];
        }

        return $this->created($payload, $successMessage)
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * @return array{message: string, reason: string}|null
     */
    private function inspectImage(UploadedFile $file, int $maxBytes): ?array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ['message' => 'Upload failed', 'reason' => 'invalid_upload'];
        }

        $path = $file->getPathname();
        if ($path === '' || !is_file($path)) {
            return ['message' => 'Upload failed', 'reason' => 'invalid_upload'];
        }

        $realPath = realpath($path);
        $tmpRoot = realpath($this->uploadTmpDir());
        if ($realPath === false || $tmpRoot === false || !$this->isInside($realPath, $tmpRoot)) {
            return ['message' => 'Upload failed', 'reason' => 'outside_temp_dir'];
        }

        $size = $this->actualSize($path);
        if ($size <= 0 || $size > $maxBytes) {
            return [
                'message' => 'File is too large (max ' . $this->formatMegabytes($maxBytes) . 'MB)',
                'reason' => 'too_large',
            ];
        }

        $extension = strtolower($file->getClientOriginalExtension());
        // Explicitly documented: SVG is rejected. It is a script-capable
        // vector and the allowlist below excludes it anyway.
        if ($extension === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return [
                'message' => 'File type not allowed. Allowed image types: JPEG, PNG, WebP, AVIF.',
                'reason' => 'extension_not_allowed',
            ];
        }

        $mime = $this->detectMime($path);
        if ($mime === '' || !in_array($mime, self::ALLOWED_MIMES, true)) {
            return [
                'message' => 'File type not allowed. Allowed image types: JPEG, PNG, WebP, AVIF.',
                'reason' => 'mime_not_allowed',
            ];
        }

        if (self::EXTENSION_MIMES[$extension] !== $mime) {
            return [
                'message' => 'File content does not match its extension.',
                'reason' => 'mime_extension_mismatch',
            ];
        }

        return null;
    }

    private function detectMime(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                return strtolower($detected);
            }
        }

        $fallback = @mime_content_type($path);
        return is_string($fallback) ? strtolower($fallback) : '';
    }

    private function actualSize(string $path): int
    {
        $size = is_file($path) ? filesize($path) : false;
        return $size === false ? 0 : (int) $size;
    }

    private function maxBytes(): int
    {
        $configured = (int) (Env::get('UPLOAD_MAX_BYTES', (string) self::DEFAULT_MAX_BYTES) ?? self::DEFAULT_MAX_BYTES);
        return $configured > 0 ? $configured : self::DEFAULT_MAX_BYTES;
    }

    private function uploadTmpDir(): string
    {
        $configured = trim((string) ini_get('upload_tmp_dir'));
        return $configured !== '' ? $configured : sys_get_temp_dir();
    }

    private function isInside(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function formatMegabytes(int $bytes): string
    {
        $mega = $bytes / 1024 / 1024;
        return $mega === floor($mega) ? (string) (int) $mega : number_format($mega, 1, '.', '');
    }

    /** @param array{message: string, reason: string} $rejection */
    private function logRejection(Request $request, UploadedFile $file, array $rejection): void
    {
        // Never log file contents or the original (user-controlled) name.
        Logger::security('upload.rejected', [
            'reason' => $rejection['reason'],
            'mime' => $this->detectMime($file->getPathname()),
            'size_bytes' => $this->actualSize($file->getPathname()),
            'ip' => $request->ip(),
        ]);
    }
}
