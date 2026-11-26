<?php

declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Application;

use App\Modules\Extension\Application\ExtensionLicenseService;

/**
 * Server-side license gate for theme package import and publish.
 *
 * Free themes (license.required false) always pass. Licensed themes require a
 * valid or in-grace Pro/Enterprise license that lists the theme SKU (or a `*`
 * wildcard). Failure messages never include the raw license document, file
 * path, signature or key material.
 */
final class ThemeLicenseGate
{
    private const LICENSED_EDITIONS = ['pro', 'enterprise'];

    public function __construct(private readonly ?ExtensionLicenseService $licenses = null) {}

    /**
     * @param array{required: bool, sku: string|null} $license
     * @throws \InvalidArgumentException when a licensed theme is not entitled
     */
    public function assertEntitled(array $license): void
    {
        if ($license['required'] !== true) {
            return;
        }

        $sku = $license['sku'];
        if ($sku === null || $sku === '') {
            throw new \InvalidArgumentException('Theme license requires a valid SKU before it can be imported or published.');
        }

        $status = ($this->licenses ?? new ExtensionLicenseService())->status();
        $edition = $status['edition'];
        $extensions = $status['extensions'];
        $listed = in_array('*', $extensions, true) || in_array($sku, $extensions, true);

        if ($status['ok'] !== true || !in_array($edition, self::LICENSED_EDITIONS, true) || !$listed) {
            $reason = preg_match('/^[a-z_]+$/', $status['reason']) === 1 ? $status['reason'] : 'invalid';
            throw new \InvalidArgumentException(
                'Theme license required for SKU ' . $sku . ' (' . $reason . '). Activate a valid Pro license before importing or publishing this theme.'
            );
        }
    }
}
