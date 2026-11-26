<?php

declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Application;

use Siro\Core\Storage;
use ZipArchive;

/**
 * P2: export a siro.theme/1 JSON package plus local /storage assets as a zip.
 * External URLs are left untouched; only local storage files are bundled.
 */
final class ThemePackageArchiveExporter
{
    private const ASSET_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'otf'];

    /**
     * @param array<string, mixed> $package
     * @return array{content: string, files: int}
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
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-theme-export-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Could not create theme archive');
        try {
            $zip->addFromString('theme.json', $theme);
            $zip->addFromString('checksums.json', json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            foreach ($assets as $path => $bytes) $zip->addFromString($path, $bytes);
        } finally {
            $zip->close();
        }
        $content = file_get_contents($zipPath);
        @unlink($zipPath);
        if (!is_string($content)) throw new \RuntimeException('Could not read theme archive');
        return ['content' => $content, 'files' => count($assets) + 2];
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
