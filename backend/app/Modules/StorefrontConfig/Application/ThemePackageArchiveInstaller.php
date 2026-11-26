<?php

declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Application;

use App\Modules\StorefrontConfig\Domain\ThemePackage;
use App\Support\ImageDerivativeService;
use Siro\Core\Env;
use Siro\Core\Storage;
use ZipArchive;

/**
 * Imports a siro.theme/1 archive with local assets. Theme files are data only:
 * no PHP/JS/HTML, no SVG (scriptable), and every reference is rewritten to a
 * stored public URL before the config is validated.
 *
 * Optional integrity envelope (same Ed25519 convention as extension packages):
 *   checksums.json  {"assets/x.png":"<sha256>"}
 *   signature.json  {"algorithm":"ed25519","key_id":"...","signature":"<base64>"}
 * The signature covers sha256(theme.json bytes) + "\n" + sha256(checksums.json bytes).
 * Verification happens before any asset is written. Licensed theme archives must
 * be signed when EXTENSION_REQUIRE_SIGNATURE=1.
 */
final class ThemePackageArchiveInstaller
{
    private const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_FILES = 500;
    private const ASSET_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'otf'];
    private const MIME_EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/avif' => 'avif'];
    private const METADATA = ['checksums.json', 'signature.json'];

    /**
     * Verify an archive without writing anything.
     *
     * @return array{package: array<string, mixed>, license: array{required: bool, sku: string|null}, prefix: string, theme_id: string, signed: bool}
     */
    public function verify(string $zipPath, bool $requireSignature = false): array
    {
        if (!is_file($zipPath) || filesize($zipPath) === false || filesize($zipPath) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Theme archive is missing or exceeds 20 MB');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new \InvalidArgumentException('Theme archive cannot be opened');
        try {
            if ($zip->numFiles > self::MAX_FILES) throw new \InvalidArgumentException('Theme archive contains too many files');
            $prefix = $this->prefix($zip);
            $themeRaw = $zip->getFromName($prefix . 'theme.json');
            if (!is_string($themeRaw)) throw new \InvalidArgumentException('theme.json is required at the archive root');
            try {
                $package = json_decode($themeRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \InvalidArgumentException('theme.json is not valid JSON');
            }
            if (!is_array($package)) throw new \InvalidArgumentException('theme.json must contain an object');
            /** @var array<string, mixed> $keyed */
            $keyed = [];
            foreach ($package as $name => $value) if (is_string($name)) $keyed[$name] = $value;

            $input = ThemePackage::input($keyed);
            $license = ThemePackage::license($keyed);
            $assets = $this->assets($zip, $prefix);

            $checksumsRaw = $zip->getFromName($prefix . 'checksums.json');
            if (is_string($checksumsRaw)) {
                $this->checksums($zip, $prefix, $assets, $checksumsRaw);
            }
            $signatureRaw = $zip->getFromName($prefix . 'signature.json');
            $signed = is_string($signatureRaw);
            if ($signed) {
                if (!is_string($checksumsRaw)) throw new \InvalidArgumentException('signature.json requires checksums.json');
                $this->signature($signatureRaw, $themeRaw, $checksumsRaw);
            }
            if ($requireSignature && !$signed) {
                throw new \InvalidArgumentException('Theme archive signature is required');
            }
            if ($license['required'] && Env::bool('EXTENSION_REQUIRE_SIGNATURE') && !$signed) {
                throw new \InvalidArgumentException('Licensed theme archives require a valid signature');
            }

            $themeId = is_string($input['theme_key'] ?? null) ? $input['theme_key'] : '';

            return ['package' => $keyed, 'license' => $license, 'prefix' => $prefix, 'theme_id' => $themeId, 'signed' => $signed];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{package: array<string, mixed>, assets: list<string>, theme_id: string, signed: bool}
     */
    public function install(string $zipPath, bool $requireSignature = false): array
    {
        $report = $this->verify($zipPath, $requireSignature);
        $package = $report['package'];
        $prefix = $report['prefix'];
        $key = $report['theme_id'];

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new \InvalidArgumentException('Theme archive cannot be opened');
        try {
            $urls = [];
            $stored = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) continue;
                $name = str_replace('\\', '/', (string) $stat['name']);
                if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) continue;
                if ($prefix !== '' && !str_starts_with($name, $prefix)) continue;
                $relative = $prefix === '' ? $name : substr($name, strlen($prefix));
                if ($relative === 'theme.json' || in_array($relative, self::METADATA, true)) continue;
                $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                $bytes = $zip->getFromName($name);
                if (!is_string($bytes) || strlen($bytes) > 5 * 1024 * 1024) throw new \InvalidArgumentException('Theme asset is too large: ' . $relative);
                $storagePath = 'themes/' . $key . '/' . hash('sha256', $bytes) . '.' . $extension;
                if (!Storage::exists($storagePath) && Storage::put($storagePath, $bytes) !== true) {
                    throw new \RuntimeException('Unable to store theme asset: ' . $relative);
                }
                $url = ImageDerivativeService::publicUrl(Storage::url($storagePath));
                $urls[$relative] = $url;
                $stored[] = $url;
            }
            $keyed = $this->rewrite($package, $urls);

            return ['package' => $keyed, 'assets' => array_values(array_unique($stored)), 'theme_id' => $key, 'signed' => $report['signed']];
        } finally {
            $zip->close();
        }
    }

    private function prefix(ZipArchive $zip): string
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) continue;
            $name = str_replace('\\', '/', (string) $stat['name']);
            if ($name === 'theme.json') return '';
            if (str_ends_with($name, '/theme.json')) return substr($name, 0, -strlen('theme.json'));
        }
        return '';
    }

    /**
     * Validate the archive layout before any bytes are written.
     *
     * @return array<string, string> relative asset path => archive entry name
     */
    private function assets(ZipArchive $zip, string $prefix): array
    {
        $assets = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) continue;
            $name = str_replace('\\', '/', (string) $stat['name']);
            if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) continue;
            if ($prefix !== '' && !str_starts_with($name, $prefix)) continue;
            $relative = $prefix === '' ? $name : substr($name, strlen($prefix));
            if ($relative === 'theme.json' || in_array($relative, self::METADATA, true)) continue;
            if (!str_starts_with($relative, 'assets/')) {
                throw new \InvalidArgumentException('Theme archive may only contain theme.json, checksums.json, signature.json and assets/: ' . $relative);
            }
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ASSET_EXTENSIONS, true)) {
                throw new \InvalidArgumentException('Unsupported theme asset type: ' . $relative);
            }
            if (!preg_match('#^assets/[A-Za-z0-9_./-]+$#', $relative) || str_contains($relative, '..')) {
                throw new \InvalidArgumentException('Unsafe theme asset path: ' . $relative);
            }
            $bytes = $zip->getFromName($name);
            if (!is_string($bytes) || strlen($bytes) > 5 * 1024 * 1024) throw new \InvalidArgumentException('Theme asset is too large: ' . $relative);
            if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'avif'], true)) {
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                $expected = self::MIME_EXTENSIONS[is_string($mime) ? $mime : ''] ?? null;
                if ($expected === null || !in_array($extension, [$expected, 'jpg', 'jpeg'], true)) {
                    throw new \InvalidArgumentException('Theme image content does not match its extension: ' . $relative);
                }
            }
            $assets[$relative] = $name;
        }
        return $assets;
    }

    /** @param array<string, string> $assets */
    private function checksums(ZipArchive $zip, string $prefix, array $assets, string $raw): void
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('checksums.json is not valid JSON');
        }
        if (!is_array($data)) throw new \InvalidArgumentException('checksums.json must contain an object');
        $listed = [];
        foreach ($data as $path => $hash) {
            if (!is_string($path) || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || str_contains($path, '..')) {
                throw new \InvalidArgumentException('checksums.json contains an invalid entry');
            }
            if (in_array($path, self::METADATA, true) || $path === 'theme.json') continue;
            $bytes = $zip->getFromName($prefix . $path);
            if (!is_string($bytes) || hash('sha256', $bytes) !== $hash) {
                throw new \InvalidArgumentException('Theme asset checksum mismatch: ' . $path);
            }
            $listed[$path] = true;
        }
        foreach (array_keys($assets) as $path) {
            if (!isset($listed[$path])) throw new \InvalidArgumentException('Theme asset is missing from checksums.json: ' . $path);
        }
    }

    private function signature(string $raw, string $themeRaw, string $checksumsRaw): void
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('signature.json is not valid JSON');
        }
        if (!is_array($data) || ($data['algorithm'] ?? null) !== 'ed25519' || !is_string($data['key_id'] ?? null) || !is_string($data['signature'] ?? null)) {
            throw new \InvalidArgumentException('signature.json is invalid');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \InvalidArgumentException('Ed25519 verification is unavailable');
        }
        $keys = $this->publicKeys();
        $key = $keys[$data['key_id']] ?? null;
        if ($key === null) throw new \InvalidArgumentException('Theme signing key is not configured for this archive');
        $payload = hash('sha256', $themeRaw) . "\n" . hash('sha256', $checksumsRaw);
        $signature = base64_decode($data['signature'], true);
        $decodedKey = base64_decode($key, true);
        if (!is_string($signature) || $signature === ''
            || !is_string($decodedKey) || strlen($decodedKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($signature, $payload, $decodedKey)) {
            throw new \InvalidArgumentException('Theme archive signature is invalid');
        }
    }

    /** @return array<string, string> */
    private function publicKeys(): array
    {
        $keys = [];
        $raw = Env::get('EXTENSION_SIGNING_PUBLIC_KEYS');
        if (is_string($raw) && $raw !== '') {
            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data)) foreach ($data as $id => $key) if (is_string($id) && is_string($key)) $keys[$id] = $key;
            } catch (\JsonException) {
                // Invalid key configuration fails closed at verification time.
            }
        }
        $single = Env::get('EXTENSION_SIGNING_PUBLIC_KEY');
        if ($keys === [] && is_string($single) && $single !== '') $keys['default'] = $single;

        return $keys;
    }

    /** @param array<string, mixed> $package
     * @param array<string, string> $urls
     * @return array<string, mixed> */
    private function rewrite(array $package, array $urls): array
    {
        foreach ($package as $key => $value) {
            $package[$key] = $this->rewriteValue($value, $urls);
        }
        return $package;
    }

    /** @param array<string, string> $urls */
    private function rewriteValue(mixed $value, array $urls): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) $value[$key] = $this->rewriteValue($item, $urls);
            return $value;
        }
        return is_string($value) && isset($urls[$value]) ? $urls[$value] : $value;
    }
}
