<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\StorefrontConfig\Application\ThemePackageArchiveExporter;
use PHPUnit\Framework\TestCase;
use Siro\Core\Storage;
use ZipArchive;

final class ThemePackageArchiveExporterTest extends TestCase
{
    protected function setUp(): void
    {
        Storage::fake();
    }

    protected function tearDown(): void
    {
        Storage::reset();
    }

    public function testBundlesLocalStorageAssetsAndRewritesReferences(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        Storage::put('themes/demo/logo.png', $png);
        $package = [
            'schema' => 'siro.theme/1',
            'id' => 'siro/demo',
            'name' => 'Demo',
            'version' => '1.0.0',
            'brand' => ['name' => 'Demo', 'logo_url' => '/storage/themes/demo/logo.png'],
            'homepage' => ['sections' => [['id' => 'hero', 'type' => 'campaign_hero', 'props' => ['image' => 'https://cdn.example.com/keep.png']]]],
        ];
        $result = (new ThemePackageArchiveExporter())->export($package);
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'export-test-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($zipPath, $result['content']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);
        $theme = json_decode((string) $zip->getFromName('theme.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('assets/', (string) $theme['brand']['logo_url']);
        self::assertSame('https://cdn.example.com/keep.png', $theme['homepage']['sections'][0]['props']['image']);
        self::assertIsString($zip->getFromName('checksums.json'));
        self::assertIsString($zip->getFromName((string) $theme['brand']['logo_url']));
        $zip->close();
        @unlink($zipPath);
    }
}
