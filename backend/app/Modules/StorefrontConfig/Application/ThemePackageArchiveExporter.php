<?php

declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Application;

use Siro\Core\Env;
use Siro\Core\Storage;
use ZipArchive;

/**
 * P2: export a siro.theme/1 JSON package plus local /storage assets as a zip.
 * External URLs are left untouched; only local storage files are bundled.
 *
 * checksums.json is always written. When EXTENSION_SIGNING_PRIVATE_KEY is
 * configured a signature.json is added using the same Ed25519 convention as
 * extension packages (see docs/contracts/THEME_PACKAGE_SPEC.md).
 */
final class ThemePackageArchiveExporter
{
    private const ASSET_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'otf'];

    /**
     * @param array<string, mixed> $package
     * @return array{content: string, files: int, signed: bool}
     */
    public function export(array $package): array
    {
        $assets = [];
        $this->collect($package, $assets);
        ksort($assets);
        $theme = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $checksums = [];
        foreach ($assets as $path => $bytes) $checksums[$path] = hash('sha256', $bytes);
        ksort($checksums);
        $checksumsRaw = json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = $this->signature($theme, $checksumsRaw);
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-theme-export-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Could not create theme archive');
        try {
            $zip->addFromString('theme.json', $theme);
            $zip->addFromString('checksums.json', $checksumsRaw);
            foreach ($assets as $path => $bytes) $zip->addFromString($path, $bytes);
            if ($signature !== null) {
                $zip->addFromString('signature.json', json_encode($signature, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
        } finally {
            $zip->close();
        }
        $content = file_get_contents($zipPath);
        @unlink($zipPath);
        if (!is_string($content)) throw new \RuntimeException('Could not read theme archive');
        return ['content' => $content, 'files' => count($assets) + 2 + ($signature === null ? 0 : 1), 'signed' => $signature !== null];
    }

    /**
     * @return array{algorithm: string, key_id: string, signature: string}|null
     */
    private function signature(string $themeRaw, string $checksumsRaw): ?array
    {
        $private = Env::get('EXTENSION_SIGNING_PRIVATE_KEY');
        if (!is_string($private) || $private === '') return null;
        if (!function_exists('sodium_crypto_sign_detached')) throw new \RuntimeException('Ed25519 signing is unavailable');
        $key = base64_decode($private, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new \RuntimeException('EXTENSION_SIGNING_PRIVATE_KEY is invalid');
        $keyId = Env::get('EXTENSION_SIGNING_KEY_ID');
        $payload = hash('sha256', $themeRaw) . "\n" . hash('sha256', $checksumsRaw);

        return [
            'algorithm' => 'ed25519',
            'key_id' => is_string($keyId) && $keyId !== '' ? $keyId : 'default',
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $key)),
        ];
    }

    /**
     * @param array<mixed, mixed> $package
     * @param array<string, string> $assets
     */
    private function collect(array &$package, array &$assets): void
    {
        foreach ($package as $key => &$value) {
            if (is_array($value)) {
                $this->collect($value, $assets);
                continue;
            }
            if (!is_string($value)) continue;
            $position = strpos($value, '/storage/');
            if ($position === false) continue;
            $relative = ltrim(substr($value, $position + strlen('/storage/')), '/');
            if ($relative === '' || str_contains($relative, '..') || !preg_match('#^[A-Za-z0-9_./-]+$#', $relative)) continue;
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ASSET_EXTENSIONS, true)) continue;
            $bytes = Storage::get($relative);
            if (!is_string($bytes)) continue;
            $assetPath = 'assets/' . hash('sha256', $relative) . '.' . $extension;
            $assets[$assetPath] = $bytes;
            $value = $assetPath;
        }
        unset($value);
    }
}
