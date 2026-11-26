<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\StorefrontConfig\Application\ThemePackageArchiveExporter;
use App\Modules\StorefrontConfig\Application\ThemePackageArchiveInstaller;
use PHPUnit\Framework\TestCase;
use Siro\Core\Storage;
use ZipArchive;

final class ThemePackageArchiveExporterTest extends TestCase
{
    private const ENV_KEYS = ['EXTENSION_SIGNING_PRIVATE_KEY', 'EXTENSION_SIGNING_KEY_ID', 'EXTENSION_SIGNING_PUBLIC_KEY', 'EXTENSION_SIGNING_PUBLIC_KEYS'];

    /** @var array<string, string> */
    private array $originalEnv = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        Storage::fake();
        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $_ENV)) $this->originalEnv[$key] = is_scalar($_ENV[$key]) ? (string) $_ENV[$key] : '';
            $this->setEnv($key, null);
        }
    }

    protected function tearDown(): void
    {
        Storage::reset();
        foreach ($this->tempFiles as $file) @unlink($file);
        foreach (self::ENV_KEYS as $key) {
            $this->setEnv($key, $this->originalEnv[$key] ?? null);
        }
    }

    public function testBundlesLocalStorageAssetsAndRewritesReferences(): void
    {
        $png = $this->png();
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
        self::assertFalse($result['signed']);
        $zip = $this->open($result['content']);
        $theme = json_decode((string) $zip->getFromName('theme.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('assets/', (string) $theme['brand']['logo_url']);
        self::assertSame('https://cdn.example.com/keep.png', $theme['homepage']['sections'][0]['props']['image']);
        self::assertIsString($zip->getFromName('checksums.json'));
        self::assertIsString($zip->getFromName((string) $theme['brand']['logo_url']));
        self::assertFalse($zip->getFromName('signature.json'));
        $zip->close();
    }

    public function testSignedExportVerifiesThroughTheInstaller(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) self::markTestSkipped('Sodium is required for signature tests');
        $pair = sodium_crypto_sign_keypair();
        $this->setEnv('EXTENSION_SIGNING_PRIVATE_KEY', base64_encode(sodium_crypto_sign_secretkey($pair)));
        $this->setEnv('EXTENSION_SIGNING_PUBLIC_KEYS', json_encode(['test-key' => base64_encode(sodium_crypto_sign_publickey($pair))], JSON_THROW_ON_ERROR));
        $this->setEnv('EXTENSION_SIGNING_KEY_ID', 'test-key');

        $png = $this->png();
        Storage::put('themes/demo/logo.png', $png);
        $package = [
            'schema' => 'siro.theme/1',
            'id' => 'siro/atelier-noir',
            'key' => 'atelier-noir',
            'name' => 'Atelier Noir',
            'version' => '1.0.0',
            'license' => ['required' => true, 'sku' => 'siro-theme-atelier-noir'],
            'brand' => ['name' => 'Noir', 'logo_url' => '/storage/themes/demo/logo.png'],
            'homepage' => ['sections' => [['id' => 'hero', 'type' => 'campaign_hero', 'props' => []]]],
        ];
        $result = (new ThemePackageArchiveExporter())->export($package);
        self::assertTrue($result['signed']);

        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'export-signed-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($zipPath, $result['content']);
        try {
            $report = (new ThemePackageArchiveInstaller())->verify($zipPath, true);
            self::assertTrue($report['signed']);
            self::assertSame(['required' => true, 'sku' => 'siro-theme-atelier-noir'], $report['license']);
        } finally {
            @unlink($zipPath);
        }
    }

    private function png(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        self::assertIsString($png);

        return $png;
    }

    private function open(string $content): ZipArchive
    {
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'export-test-' . bin2hex(random_bytes(4)) . '.zip';
        $this->tempFiles[] = $zipPath;
        file_put_contents($zipPath, $content);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);

        return $zip;
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            putenv($key);
            return;
        }
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}
