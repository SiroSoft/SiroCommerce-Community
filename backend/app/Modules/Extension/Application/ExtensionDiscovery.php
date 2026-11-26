<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use App\Contracts\ExtensionInterface;

/**
 * Extension discovery: app/Extensions packages plus Composer packages that
 * declare `extra.siro.extension` (or `extra.siro.provider`). Deterministic and
 * best-effort; a malformed package never breaks discovery.
 */
final class ExtensionDiscovery
{
    /**
     * @return list<class-string<ExtensionInterface>>
     */
    public static function classes(string $basePath): array
    {
        $classes = [];
        $dir = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Extensions';
        if (is_dir($dir)) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $base = basename($file, '.php');
                if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $base)) continue;
                $class = 'App\\Extensions\\' . $base;
                if (self::isExtension($class)) $classes[] = $class;
            }
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'Extension.php') ?: [] as $file) {
                $vendor = basename(dirname(dirname($file)));
                $name = basename(dirname($file));
                if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $vendor) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) continue;
                $class = 'App\\Extensions\\' . $vendor . '\\' . $name . '\\Extension';
                if (self::isExtension($class)) $classes[] = $class;
            }
        }
        foreach (self::composerExtensions($basePath) as $class) {
            if (self::isExtension($class)) $classes[] = $class;
        }
        /** @var list<class-string<ExtensionInterface>> $classes */
        $classes = array_values(array_unique($classes));
        sort($classes);
        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function composerExtensions(string $basePath): array
    {
        $file = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';
        if (!is_file($file)) return [];
        $raw = @file_get_contents($file);
        if (!is_string($raw)) return [];
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data)) return [];
        $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;
        $classes = [];
        foreach ($packages as $package) {
            if (!is_array($package)) continue;
            $extra = $package['extra'] ?? null;
            if (!is_array($extra)) continue;
            $siro = $extra['siro'] ?? null;
            if (!is_array($siro)) continue;
            $value = $siro['extension'] ?? $siro['provider'] ?? null;
            if (is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $value) === 1) {
                $classes[] = $value;
            }
        }
        return $classes;
    }

    private static function isExtension(string $class): bool
    {
        try {
            return class_exists($class) && is_a($class, ExtensionInterface::class, true);
        } catch (\Throwable) {
            return false;
        }
    }
}
