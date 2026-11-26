<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

/**
 * Deterministic, non-executing scan of a Bagisto extension/theme package.
 * Produces a compatibility report and a Siro manifest draft. See
 * docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md section 11.
 */
final class BagistoCompatibilityAnalyzer
{
    /**
     * @return array{
     *   source: array{name: string, version: string, kind: string, path: string},
     *   level: string,
     *   score: int,
     *   supported: list<string>,
     *   port_required: list<string>,
     *   unsupported: list<string>,
     *   migrations: array{tables: list<string>, action: string},
     *   file_count: int,
     *   theme: array{assets: int, colors: list<string>, fonts: list<string>, blade_templates: int},
     *   siro_manifest: array<string, mixed>
     * }
     */
    public function analyze(string $packagePath): array
    {
        $path = rtrim($packagePath, DIRECTORY_SEPARATOR);
        if (!is_dir($path)) throw new \InvalidArgumentException('Bagisto package directory does not exist');
        $composer = $this->composer($path);
        $files = $this->files($path);
        $has = static function (string $needle) use ($files): bool {
            foreach (array_keys($files) as $file) if (str_contains($file, $needle)) return true;
            return false;
        };
        $count = static function (string $suffix) use ($files): int {
            $total = 0;
            foreach (array_keys($files) as $file) if (str_ends_with($file, $suffix)) $total++;
            return $total;
        };
        $kind = $this->kind($composer, $has);
        $supported = [];
        $portRequired = [];
        $unsupported = [];
        if ($has('Config/menu.php') || $has('Config/admin-menu.php')) $supported[] = 'admin menu metadata';
        if ($has('Config/acl.php')) $supported[] = 'ACL permission keys';
        if ($has('Config/system.php')) $supported[] = 'channel-aware system configuration';
        if ($has('Config/payment-methods.php') || $has('Payment/')) $supported[] = 'payment provider capability';
        if ($has('Config/carriers.php') || $has('Shipping/')) $supported[] = 'shipping provider capability';
        if ($has('Providers/')) $portRequired[] = 'Laravel service provider bootstrap';
        if ($has('Http/Controllers/')) $portRequired[] = 'Laravel controllers';
        if ($has('Models/')) $portRequired[] = 'Eloquent models';
        if ($has('Repositories/')) $portRequired[] = 'repositories';
        if ($has('Routes/')) $portRequired[] = 'Laravel route files';
        if ($has('Database/Migrations/')) $portRequired[] = 'migrations (rename ext_* + store/channel scope)';
        if ($count('.blade.php') > 0) $unsupported[] = 'Blade views (' . $count('.blade.php') . ' templates)';
        if ($has('DataGrids/')) $unsupported[] = 'admin DataGrids';
        if ($has('Contracts/') || $has('Concord/')) $unsupported[] = 'Concord model bindings';
        foreach (['laravel/framework', 'bagisto/bagisto', 'webkul/core'] as $dependency) {
            $requires = is_array($composer['require'] ?? null) ? $composer['require'] : [];
            if (isset($requires[$dependency])) $unsupported[] = 'Laravel runtime dependency: ' . $dependency;
        }
        $migrations = $this->migrationTables($path, $files);
        $score = 100;
        $score -= 15 * count(array_filter($supported, static fn (string $item): bool => str_contains($item, 'provider')));
        $score -= 12 * count($portRequired);
        $score -= 10 * count($unsupported);
        $score = max(0, min(100, $score));
        $level = $score >= 80 ? 'packaging-compatible' : ($score >= 35 ? 'port-required' : 'runtime-incompatible');
        $name = is_string($composer['name'] ?? null) ? $composer['name'] : 'vendor/' . basename($path);
        $version = is_string($composer['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $composer['version']) === 1 ? $composer['version'] : '0.1.0';
        $id = $this->normalizeId($name);
        $capabilities = [];
        if ($has('Config/payment-methods.php') || $has('Payment/')) $capabilities[] = 'payment.provider';
        if ($has('Config/carriers.php') || $has('Shipping/')) $capabilities[] = 'shipping.provider';
        if ($has('Config/menu.php') || $has('Config/admin-menu.php')) $capabilities[] = 'admin.panel';
        $manifest = [
            'schema' => 'siro.extension/2',
            'id' => $id,
            'type' => $kind === 'theme' ? 'theme' : 'module',
            'name' => $this->packageName($name),
            'description' => 'Ported from ' . $name . '; complete the compatibility report before publishing.',
            'version' => $version,
            'requires' => ['core' => '^1.0', 'commerce' => '^0.2'],
            'runtime' => ['php' => '>=8.2'],
            'provider' => 'App\\Extensions\\' . $this->namespaceSuffix($id) . '\\Extension',
            'capabilities' => $capabilities,
            'permissions' => $this->permissions($path, $files),
            'migrations' => [],
            'routes' => [],
            'slots' => ['storefront' => $kind === 'theme' ? ['theme'] : []],
            'bagisto' => ['compatible' => $level, 'source' => $name, 'kind' => $kind],
        ];
        return [
            'source' => ['name' => $name, 'version' => $version, 'kind' => $kind, 'path' => $path],
            'level' => $level,
            'score' => $score,
            'supported' => array_values(array_unique($supported)),
            'port_required' => array_values(array_unique($portRequired)),
            'unsupported' => array_values(array_unique($unsupported)),
            'migrations' => $migrations,
            'file_count' => count($files),
            'theme' => $this->theme($path, $files, $kind),
            'siro_manifest' => $manifest,
        ];
    }

    /**
     * @param array<string, int> $files
     * @return array{assets: int, colors: list<string>, fonts: list<string>, blade_templates: int}
     */
    private function theme(string $path, array $files, string $kind): array
    {
        $report = ['assets' => 0, 'colors' => [], 'fonts' => [], 'blade_templates' => 0];
        if ($kind !== 'theme') return $report;
        $scanned = 0;
        foreach (array_keys($files) as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, ['css', 'scss', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'woff', 'woff2', 'ttf'], true)) $report['assets']++;
            if (str_ends_with($file, '.blade.php')) $report['blade_templates']++;
            if ($extension !== 'css' || $scanned >= 8) continue;
            $raw = file_get_contents($path . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
            if (!is_string($raw)) continue;
            $scanned++;
            if (preg_match_all('/#[0-9a-fA-F]{6}/', substr($raw, 0, 200000), $matches) > 0) {
                foreach ($matches[0] as $color) {
                    $color = strtolower($color);
                    if (!in_array($color, $report['colors'], true)) $report['colors'][] = $color;
                }
            }
            if (preg_match_all('/font-family\s*:\s*([^;}{]+)/i', substr($raw, 0, 200000), $matches) > 0) {
                foreach ($matches[1] as $font) {
                    $font = substr(trim($font), 0, 120);
                    if ($font !== '' && !in_array($font, $report['fonts'], true)) $report['fonts'][] = $font;
                }
            }
        }
        $report['colors'] = array_slice($report['colors'], 0, 12);
        $report['fonts'] = array_slice($report['fonts'], 0, 8);
        return $report;
    }

    /** @return array<string, mixed> */
    private function composer(string $path): array
    {
        $file = $path . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($file)) return [];
        $raw = file_get_contents($file);
        if (!is_string($raw)) return [];
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data)) return [];
        $result = [];
        foreach ($data as $key => $value) if (is_string($key)) $result[$key] = $value;
        return $result;
    }

    /** @param array<string, mixed> $composer
     * @param callable(string): bool $has */
    private function kind(array $composer, callable $has): string
    {
        $type = is_string($composer['type'] ?? null) ? $composer['type'] : '';
        if (str_contains($type, 'theme') || ($has('Resources/views/') && !$has('src/Providers/'))) return 'theme';
        return 'extension';
    }

    /** @return array<string, int> */
    private function files(string $path): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) continue;
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($path) + 1));
            if (str_starts_with($relative, 'vendor/') || str_starts_with($relative, 'node_modules/') || str_starts_with($relative, '.git/')) continue;
            $files[$relative] = (int) $item->getSize();
            if (count($files) >= 20000) break;
        }
        ksort($files);
        return $files;
    }

    /**
     * @param array<string, int> $files
     * @return array{tables: list<string>, action: string}
     */
    private function migrationTables(string $path, array $files): array
    {
        $tables = [];
        foreach (array_keys($files) as $file) {
            if (!str_contains($file, 'Database/Migrations/') || !str_ends_with($file, '.php')) continue;
            $raw = file_get_contents($path . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
            if (!is_string($raw)) continue;
            if (preg_match_all("/Schema::(?:create|table)\\s*\\(\\s*['\"]([^'\"]+)['\"]/", $raw, $matches) > 0) {
                foreach ($matches[1] as $table) if (!in_array($table, $tables, true)) $tables[] = $table;
            }
        }
        sort($tables);
        return ['tables' => $tables, 'action' => 'Rename every table to ext_* and add store_id/channel_id before porting.'];
    }

    /**
     * @param array<string, int> $files
     * @return list<string>
     */
    private function permissions(string $path, array $files): array
    {
        $permissions = [];
        foreach (array_keys($files) as $file) {
            if (!str_ends_with($file, 'Config/acl.php')) continue;
            $raw = file_get_contents($path . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
            if (!is_string($raw)) continue;
            if (preg_match_all("/'key'\\s*=>\\s*'([^']+)'/", $raw, $matches) > 0) {
                foreach ($matches[1] as $key) if (preg_match('/^[a-z0-9][a-z0-9._:-]*$/', $key) === 1 && !in_array($key, $permissions, true)) $permissions[] = $key;
            }
        }
        sort($permissions);
        return array_slice($permissions, 0, 50);
    }

    private function normalizeId(string $name): string
    {
        $parts = explode('/', strtolower($name), 2);
        $clean = static function (string $value): string {
            $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? $value;
            $value = trim($value, '.-_');
            return $value === '' ? 'ported-extension' : $value;
        };
        $vendor = $clean($parts[0]);
        $rest = $clean($parts[1] ?? 'ported-extension');
        return $vendor . '/' . $rest;
    }

    private function namespaceSuffix(string $id): string
    {
        [$vendor, $name] = explode('/', $id, 2);
        $clean = static function (string $value): string {
            $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
            $value = ucfirst($value);
            return preg_match('/^[A-Z][A-Za-z0-9]*$/', $value) === 1 ? $value : 'Extension';
        };
        return $clean($vendor) . '\\' . $clean($name);
    }

    private function packageName(string $name): string
    {
        $part = str_contains($name, '/') ? substr($name, strpos($name, '/') + 1) : $name;
        return ucwords(str_replace(['-', '_', '.'], ' ', $part));
    }
}
