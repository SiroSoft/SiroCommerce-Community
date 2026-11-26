<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults;
use App\Modules\StorefrontConfig\Domain\ThemePackage;
use PHPUnit\Framework\TestCase;

final class ExampleThemesTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function packages(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/examples/themes/*/theme.json');
        if ($files === false) {
            self::fail('Unable to glob example theme packages');
        }

        $packages = [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw, 'Unable to read ' . $file);
            $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($package)) {
                self::fail('Theme package must decode to an object: ' . $file);
            }
            $packages[basename(dirname($file))] = $package;
        }

        return $packages;
    }

    public function testShipsExampleThemes(): void
    {
        self::assertCount(3, $this->packages());
    }

    public function testEveryExampleImportsAsValidStorefrontInput(): void
    {
        $allowedSections = StorefrontConfigDefaults::allowedSections();

        foreach ($this->packages() as $folder => $package) {
            self::assertSame('siro.theme/1', $package['schema'] ?? null, $folder);
            self::assertSame('siro/' . $folder, $package['id'] ?? null, $folder);
            self::assertSame($folder, $package['key'] ?? null, $folder);
            self::assertSame('general', $package['extends'] ?? null, $folder);
            self::assertSame('1.0.0', $package['version'] ?? null, $folder);
            self::assertIsString($package['vertical'] ?? null, $folder);
            self::assertNotSame('', $package['vertical'], $folder);

            $input = ThemePackage::input($package);
            self::assertSame($folder, $input['theme_key'], $folder);
            self::assertSame('general', $input['theme_extends'], $folder);
            self::assertSame($package['vertical'], $input['vertical_key'], $folder);
            self::assertArrayNotHasKey('scope', $input, $folder);

            $sections = $package['homepage']['sections'] ?? null;
            self::assertIsArray($sections, $folder);
            self::assertGreaterThanOrEqual(4, count($sections), $folder);

            $ids = [];
            foreach ($sections as $section) {
                self::assertIsArray($section, $folder);
                $id = $section['id'] ?? null;
                $type = $section['type'] ?? null;
                self::assertIsString($id, $folder);
                self::assertIsString($type, $folder);
                self::assertNotContains($id, $ids, $folder . ' duplicate section id ' . $id);
                self::assertContains($type, $allowedSections, $folder . ' invalid section type ' . $type);
                self::assertIsInt($section['order'] ?? null, $folder);
                $ids[] = $id;
            }
        }
    }

    public function testExampleThemeKeysAreDistinctAndVerticalsAreKnown(): void
    {
        $packages = array_values($this->packages());
        $keys = array_map(static fn (array $package): string => (string) ($package['key'] ?? ''), $packages);
        $verticals = array_map(static fn (array $package): string => (string) ($package['vertical'] ?? ''), $packages);

        self::assertCount(3, array_unique($keys));
        foreach (['fashion', 'pharmacy', 'fnb'] as $vertical) {
            self::assertContains($vertical, $verticals);
        }
    }

    public function testEveryReferencedAssetExistsAndIsASafePublicImage(): void
    {
        $themesRoot = dirname(__DIR__, 3) . '/examples/themes';

        foreach ($this->packages() as $folder => $package) {
            $base = $themesRoot . '/' . $folder;
            $refs = array_values(array_unique($this->assetRefs($package)));

            if ($refs === []) {
                $logo = $package['brand']['logo_url'] ?? null;
                self::assertIsString($logo, $folder);
                self::assertMatchesRegularExpression('#^/(?!/)#', $logo, $folder . ' logo must be a safe root-relative URL');
                self::assertStringNotContainsString('..', $logo, $folder . ' logo path traversal ' . $logo);

                $hero = null;
                foreach ($package['homepage']['sections'] ?? [] as $section) {
                    if (is_array($section) && ($section['type'] ?? null) === 'campaign_hero') {
                        $hero = $section['props']['image'] ?? null;
                        break;
                    }
                }
                self::assertIsString($hero, $folder);
                self::assertMatchesRegularExpression('#^/(?!/)#', $hero, $folder . ' hero must be a safe root-relative URL');
                self::assertStringNotContainsString('..', $hero, $folder . ' hero path traversal ' . $hero);
                continue;
            }

            self::assertGreaterThanOrEqual(2, count($refs), $folder . ' must reference at least a logo and a hero asset');

            foreach ($refs as $ref) {
                self::assertMatchesRegularExpression('#^assets/[A-Za-z0-9][A-Za-z0-9_./-]*$#', $ref, $folder . ' unsafe asset reference ' . $ref);
                self::assertStringNotContainsString('..', $ref, $folder . ' asset path traversal ' . $ref);
                self::assertStringNotContainsString('\\', $ref, $folder . ' backslash asset path ' . $ref);

                $extension = strtolower(pathinfo($ref, PATHINFO_EXTENSION));
                self::assertContains($extension, ['png', 'jpg', 'jpeg', 'webp'], $folder . ' unsupported asset extension ' . $ref);

                $file = $base . '/' . $ref;
                self::assertFileExists($file, $folder . ' missing asset ' . $ref);
                self::assertLessThanOrEqual(300 * 1024, (int) filesize($file), $folder . ' asset exceeds 300 KB ' . $ref);

                $info = getimagesize($file);
                self::assertIsArray($info, $folder . ' asset is not a readable image ' . $ref);
                self::assertContains($info[2] ?? null, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], $folder . ' asset image type mismatch ' . $ref);
            }

            $logo = $package['brand']['logo_url'] ?? null;
            self::assertIsString($logo, $folder);
            self::assertStringStartsWith('assets/', $logo, $folder . ' logo must be a local theme asset');
            self::assertFileExists($base . '/' . $logo, $folder . ' missing logo ' . $logo);

            $hero = null;
            foreach ($package['homepage']['sections'] ?? [] as $section) {
                if (is_array($section) && ($section['type'] ?? null) === 'campaign_hero') {
                    $hero = $section['props']['image'] ?? null;
                    break;
                }
            }
            self::assertIsString($hero, $folder);
            self::assertStringStartsWith('assets/', $hero, $folder . ' hero must be a local theme asset');
            self::assertFileExists($base . '/' . $hero, $folder . ' missing hero ' . $hero);
        }
    }

    /**
     * Collect every local `assets/...` reference from the decoded package.
     * @return list<string>
     */
    private function assetRefs(mixed $value): array
    {
        if (is_array($value)) {
            $refs = [];
            foreach ($value as $item) {
                $refs = array_merge($refs, $this->assetRefs($item));
            }
            return $refs;
        }

        return is_string($value) && str_starts_with($value, 'assets/') ? [$value] : [];
    }
}
