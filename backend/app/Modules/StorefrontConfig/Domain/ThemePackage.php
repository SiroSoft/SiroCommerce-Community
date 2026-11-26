<?php

declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Domain;

/**
 * Converts a portable siro.theme/1 package into storefront config input and
 * back. Scope is intentionally never read from or written to a theme file.
 * See docs/contracts/THEME_PACKAGE_SPEC.md.
 */
final class ThemePackage
{
    private const CONFIG_FIELDS = ['tokens', 'brand', 'navigation', 'header', 'footer', 'homepage', 'presentation', 'purchase', 'policies', 'experiments'];

    /**
     * Built-in commercial theme keys mapped to their license SKU. The draft
     * storefront config does not persist the package license block, so publish
     * gating resolves built-in Pro themes through this server-side registry.
     *
     * @var array<string, string>
     */
    private const COMMERCIAL_LICENSES = [
        'atelier-noir' => 'siro-theme-atelier-noir',
        'apothecary-calm' => 'siro-theme-apothecary-calm',
    ];

    /**
     * @param array<string, mixed> $package
     * @return array<string, mixed> storefront config input for StorefrontConfigService::update
     */
    public static function input(array $package): array
    {
        if (($package['schema'] ?? null) !== 'siro.theme/1') throw new \InvalidArgumentException('Theme package schema must be siro.theme/1');
        self::license($package);
        $id = $package['id'] ?? null;
        if (!is_string($id) || preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#', $id) !== 1) {
            throw new \InvalidArgumentException('Theme package id must be a lowercase vendor/key identifier');
        }
        $key = $package['key'] ?? substr($id, strpos($id, '/') + 1);
        if (!is_string($key) || preg_match('/^[a-z][a-z0-9_-]{2,39}$/', $key) !== 1) {
            throw new \InvalidArgumentException('Theme package key is invalid (3-40 lowercase characters)');
        }
        $name = $package['name'] ?? null;
        if (!is_string($name) || mb_strlen(trim($name)) < 2 || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Theme package name is required (2-120 characters)');
        }
        $version = $package['version'] ?? null;
        if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new \InvalidArgumentException('Theme package version must be major.minor.patch');
        }
        $extends = $package['extends'] ?? 'general';
        if (!is_string($extends) || preg_match('/^[a-z][a-z0-9_-]{2,39}$/', $extends) !== 1) {
            throw new \InvalidArgumentException('Theme package extends must be a valid theme key');
        }
        $vertical = $package['vertical'] ?? 'general';
        if (!is_string($vertical) || $vertical === '') throw new \InvalidArgumentException('Theme package vertical is invalid');
        $homepage = $package['homepage'] ?? null;
        $sections = is_array($homepage) ? ($homepage['sections'] ?? null) : null;
        if (!is_array($sections) || $sections === []) throw new \InvalidArgumentException('Theme package homepage.sections must contain at least one section');
        foreach (['tokens', 'brand', 'navigation', 'header', 'footer', 'homepage', 'presentation', 'purchase', 'policies', 'experiments'] as $field) {
            if (array_key_exists($field, $package) && !is_array($package[$field])) {
                throw new \InvalidArgumentException('Theme package field must be an object: ' . $field);
            }
        }
        $input = ['theme_key' => $key, 'theme_extends' => $extends, 'vertical_key' => $vertical];
        foreach (self::CONFIG_FIELDS as $field) if (array_key_exists($field, $package)) $input[$field] = $package[$field];
        return $input;
    }

    /**
     * Validate and normalize the optional `license` block.
     *
     * Free themes omit the block (or set `required` false) and must not carry a
     * SKU. Licensed themes require `required: true` plus a non-empty SKU that is
     * checked against the active extension license at import/publish time.
     *
     * @param array<string, mixed> $package
     * @return array{required: bool, sku: string|null}
     */
    public static function license(array $package): array
    {
        $value = $package['license'] ?? null;
        if ($value === null) {
            return ['required' => false, 'sku' => null];
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Theme package license must be an object');
        }
        $required = $value['required'] ?? false;
        if (!is_bool($required)) {
            throw new \InvalidArgumentException('Theme package license.required must be a boolean');
        }
        $sku = $value['sku'] ?? null;
        if ($sku !== null && !is_string($sku)) {
            throw new \InvalidArgumentException('Theme package license.sku must be a string');
        }
        if (!$required) {
            if (is_string($sku) && trim($sku) !== '') {
                throw new \InvalidArgumentException('Theme package license.sku must be null when the license is not required');
            }
            return ['required' => false, 'sku' => null];
        }
        if (!is_string($sku) || preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $sku) !== 1) {
            throw new \InvalidArgumentException('Theme package license.sku is required for licensed themes');
        }
        return ['required' => true, 'sku' => $sku];
    }

    /**
     * Resolve the license requirement for a built-in theme key. Used when
     * publishing an existing draft that no longer carries the package block.
     *
     * @return array{required: bool, sku: string|null}
     */
    public static function licenseForThemeKey(string $key): array
    {
        $sku = self::COMMERCIAL_LICENSES[$key] ?? null;

        return $sku === null ? ['required' => false, 'sku' => null] : ['required' => true, 'sku' => $sku];
    }

    /**
     * @param array<string, mixed> $config storefront config
     * @param array{name?: string, version?: string} $meta
     * @return array<string, mixed> siro.theme/1 package
     */
    public static function export(array $config, array $meta = []): array
    {
        $theme = is_array($config['theme'] ?? null) ? $config['theme'] : [];
        $brand = is_array($config['brand'] ?? null) ? $config['brand'] : [];
        $key = is_string($theme['key'] ?? null) && preg_match('/^[a-z][a-z0-9_-]{2,39}$/', $theme['key']) === 1 ? $theme['key'] : 'exported-theme';
        $package = [
            'schema' => 'siro.theme/1',
            'id' => 'siro/' . $key,
            'key' => $key,
            'name' => is_string($meta['name'] ?? null) && $meta['name'] !== '' ? $meta['name'] : (is_string($brand['name'] ?? null) && $brand['name'] !== '' ? $brand['name'] : 'Siro theme'),
            'version' => is_string($meta['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $meta['version']) === 1 ? $meta['version'] : '1.0.0',
            'description' => 'Exported from SiroCommerce theme builder.',
            'extends' => is_string($theme['extends'] ?? null) ? $theme['extends'] : 'general',
            'vertical' => is_array($config['vertical'] ?? null) && is_string($config['vertical']['key'] ?? null) ? $config['vertical']['key'] : 'general',
            'exported_at' => date('c'),
        ];
        foreach (self::CONFIG_FIELDS as $field) {
            $value = $field === 'tokens' ? ($theme['tokens'] ?? null) : ($config[$field] ?? null);
            if (is_array($value)) $package[$field] = $value;
        }
        $license = self::licenseForThemeKey($key);
        if ($license['required']) $package['license'] = $license;
        return $package;
    }
}
