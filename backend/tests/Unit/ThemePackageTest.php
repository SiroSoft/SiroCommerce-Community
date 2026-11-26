<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults;
use App\Modules\StorefrontConfig\Domain\ThemePackage;
use PHPUnit\Framework\TestCase;

final class ThemePackageTest extends TestCase
{
    /** @return array<string, mixed> */
    private function package(array $overrides = []): array
    {
        return array_replace([
            'schema' => 'siro.theme/1',
            'id' => 'acme/editorial-pharmacy',
            'name' => 'Editorial Pharmacy',
            'version' => '1.0.0',
            'extends' => 'general',
            'vertical' => 'pharmacy',
            'tokens' => ['colors' => ['ink' => '#111111', 'muted' => '#222222', 'paper' => '#333333', 'surface' => '#444444', 'accent' => '#555555', 'accent_dark' => '#666666']],
            'brand' => ['name' => 'Northfield Pharmacy'],
            'homepage' => ['sections' => [['id' => 'campaign', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => []]]],
        ], $overrides);
    }

    public function testMapsAPackageToPortableConfigInput(): void
    {
        $input = ThemePackage::input($this->package());
        self::assertSame('editorial-pharmacy', $input['theme_key']);
        self::assertSame('general', $input['theme_extends']);
        self::assertSame('pharmacy', $input['vertical_key']);
        self::assertSame('Northfield Pharmacy', $input['brand']['name']);
        self::assertArrayNotHasKey('scope', $input);
        self::assertArrayNotHasKey('version', $input);
    }

    public function testRejectsInvalidPackages(): void
    {
        foreach ([
            $this->package(['schema' => 'siro.theme/2']),
            $this->package(['id' => 'Invalid ID']),
            $this->package(['name' => '']),
            $this->package(['version' => '1.0']),
            $this->package(['homepage' => ['sections' => []]]),
        ] as $package) {
            try {
                ThemePackage::input($package);
                self::fail('Package should have been rejected');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFreePackageDefaultsToNoLicense(): void
    {
        self::assertSame(['required' => false, 'sku' => null], ThemePackage::license($this->package()));
        self::assertSame(
            ['required' => false, 'sku' => null],
            ThemePackage::license($this->package(['license' => ['required' => false, 'sku' => null]])),
        );
    }

    public function testLicensedPackageRequiresAValidSku(): void
    {
        self::assertSame(
            ['required' => true, 'sku' => 'siro-theme-atelier-noir'],
            ThemePackage::license($this->package(['license' => ['required' => true, 'sku' => 'siro-theme-atelier-noir']])),
        );

        foreach ([
            $this->package(['license' => ['required' => true]]),
            $this->package(['license' => ['required' => true, 'sku' => '']]),
            $this->package(['license' => ['required' => true, 'sku' => 'Bad SKU']]),
            $this->package(['license' => ['required' => 'yes', 'sku' => 'siro-theme-atelier-noir']]),
            $this->package(['license' => ['required' => false, 'sku' => 'siro-theme-atelier-noir']]),
            $this->package(['license' => 'required']),
        ] as $package) {
            try {
                ThemePackage::license($package);
                self::fail('Invalid license block should have been rejected');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLicenseBlockIsMetadataAndNotConfigInput(): void
    {
        $input = ThemePackage::input($this->package([
            'license' => ['required' => true, 'sku' => 'siro-theme-atelier-noir'],
        ]));

        self::assertArrayNotHasKey('license', $input);
        self::assertSame('editorial-pharmacy', $input['theme_key']);
    }

    public function testBuiltInCommercialThemesResolveLicenseRequirements(): void
    {
        self::assertSame(
            ['required' => true, 'sku' => 'siro-theme-atelier-noir'],
            ThemePackage::licenseForThemeKey('atelier-noir'),
        );
        self::assertSame(
            ['required' => true, 'sku' => 'siro-theme-apothecary-calm'],
            ThemePackage::licenseForThemeKey('apothecary-calm'),
        );
        self::assertSame(['required' => false, 'sku' => null], ThemePackage::licenseForThemeKey('fashion'));
    }

    public function testExportingACommercialThemeRetainsItsLicense(): void
    {
        $config = StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        $config['theme']['key'] = 'atelier-noir';
        $package = ThemePackage::export($config);

        self::assertSame(['required' => true, 'sku' => 'siro-theme-atelier-noir'], $package['license']);
        self::assertSame('atelier-noir', ThemePackage::input($package)['theme_key']);
    }

    public function testExportCanBeImportedAgainWithoutScope(): void
    {
        $config = StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        $config['theme']['key'] = 'custom-pharmacy';
        $config['theme']['extends'] = 'pharmacy';
        $config['brand']['name'] = 'Northfield';
        $package = ThemePackage::export($config);
        self::assertSame('siro.theme/1', $package['schema']);
        self::assertSame('siro/custom-pharmacy', $package['id']);
        self::assertArrayNotHasKey('scope', $package);
        $input = ThemePackage::input($package);
        self::assertSame('custom-pharmacy', $input['theme_key']);
        self::assertSame('pharmacy', $input['theme_extends']);
    }
}
