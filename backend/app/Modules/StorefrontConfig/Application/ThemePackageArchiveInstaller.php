<?php

declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Application;

use App\Modules\StorefrontConfig\Domain\ThemePackage;
use App\Support\ImageDerivativeService;
use Siro\Core\Storage;
use ZipArchive;

/**
 * Imports a siro.theme/1 archive with local assets. Theme files are data only:
 * no PHP/JS/HTML, no SVG (scriptable), and every reference is rewritten to a
 * stored public URL before the config is validated.
 */
final class ThemePackageArchiveInstaller
{
    private const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_FILES = 500;
    private const ASSET_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'otf'];
    private const MIME_EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/avif' => 'avif'];

    /**
     * @return array{package: array<string, mixed>, assets: list<string>, theme_id: string}
     */
    public function install(string $zipPath): array
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
            $package = json_decode($themeRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($package)) throw new \InvalidArgumentException('theme.json must contain an object');
            /** @var array<string, mixed> $keyed */
            $keyed = [];
            foreach ($package as $name => $value) if (is_string($name)) $keyed[$name] = $value;
            $input = ThemePackage::input($keyed);
            $key = is_string($input['theme_key'] ?? null) ? $input['theme_key'] : '';
            $urls = [];
            $stored = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) continue;
                $name = str_replace('\\', '/', (string) $stat['name']);
                if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) continue;
                if ($prefix !== '' && !str_starts_with($name, $prefix)) continue;
                $relative = $prefix === '' ? $name : substr($name, strlen($prefix));
                if ($relative === 'theme.json') continue;
                if (!str_starts_with($relative, 'assets/')) {
                    throw new \InvalidArgumentException('Theme archive may only contain theme.json and assets/: ' . $relative);
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
                $storagePath = 'themes/' . $key . '/' . hash('sha256', $bytes) . '.' . $extension;
                if (!Storage::exists($storagePath) && Storage::put($storagePath, $bytes) !== true) {
                    throw new \RuntimeException('Unable to store theme asset: ' . $relative);
                }
                $url = ImageDerivativeService::publicUrl(Storage::url($storagePath));
                $urls[$relative] = $url;
                $stored[] = $url;
            }
            $keyed = $this->rewrite($keyed, $urls);
            $themeId = is_string($input['theme_key'] ?? null) ? $input['theme_key'] : '';
            return ['package' => $keyed, 'assets' => array_values(array_unique($stored)), 'theme_id' => $themeId];
        } catch (\JsonException) {
            throw new \InvalidArgumentException('theme.json is not valid JSON');
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
