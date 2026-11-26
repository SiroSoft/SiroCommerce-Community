<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\StorefrontConfig\Application\ThemePackageArchiveInstaller;
use PHPUnit\Framework\TestCase;
use Siro\Core\Storage;
use ZipArchive;

final class ThemePackageArchiveInstallerTest extends TestCase
{
    private const ENV_KEYS = ['EXTENSION_SIGNING_PUBLIC_KEY', 'EXTENSION_SIGNING_PUBLIC_KEYS', 'EXTENSION_REQUIRE_SIGNATURE'];

    private string $root;

    /** @var array<string, string> */
    private array $originalEnv = [];

    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-theme-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        Storage::fake();
        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $_ENV)) $this->originalEnv[$key] = is_scalar($_ENV[$key]) ? (string) $_ENV[$key] : '';
            $this->setEnv($key, null);
        }
        if (function_exists('sodium_crypto_sign_keypair')) {
            $pair = sodium_crypto_sign_keypair();
            $this->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
            $this->secretKey = base64_encode(sodium_crypto_sign_secretkey($pair));
        } else {
            $this->publicKey = '';
            $this->secretKey = '';
        }
    }

    protected function tearDown(): void
    {
        Storage::reset();
        foreach (self::ENV_KEYS as $key) {
            $this->setEnv($key, $this->originalEnv[$key] ?? null);
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) continue;
            if ($item->isDir()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    public function testImportsThemeJsonAndRewritesLocalAssets(): void
    {
        $png = $this->png();
        $package = $this->package(['brand' => ['name' => 'Northfield', 'logo_url' => 'assets/logo.png'], 'homepage' => ['sections' => [['id' => 'hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['image' => 'assets/hero.png']]]]]);
        $zip = $this->zip([
            'theme.json' => $this->json($package),
            'assets/logo.png' => $png,
            'assets/hero.png' => $png,
        ]);
        $result = (new ThemePackageArchiveInstaller())->install($zip);
        self::assertSame('pharmacy', $result['theme_id']);
        self::assertFalse($result['signed']);
        self::assertStringStartsWith('/storage/themes/pharmacy/', (string) $result['package']['brand']['logo_url']);
        self::assertStringStartsWith('/storage/themes/pharmacy/', (string) $result['package']['homepage']['sections'][0]['props']['image']);
        self::assertCount(1, $result['assets']);
        $path = 'themes/pharmacy/' . hash('sha256', $png) . '.png';
        Storage::assertExists($path);
    }

    public function testFreeThemeWithExplicitLicenseBlockIsUnaffected(): void
    {
        $zip = $this->zip([
            'theme.json' => $this->json($this->package(['license' => ['required' => false, 'sku' => null]])),
        ]);

        $report = (new ThemePackageArchiveInstaller())->verify($zip);

        self::assertSame(['required' => false, 'sku' => null], $report['license']);
    }

    public function testUnsignedLicensedArchiveIsRejectedWhenSignatureIsRequired(): void
    {
        $this->setEnv('EXTENSION_REQUIRE_SIGNATURE', '1');
        $zip = $this->zip([
            'theme.json' => $this->json($this->package(['license' => ['required' => true, 'sku' => 'siro-theme-atelier-noir']])),
        ]);

        try {
            (new ThemePackageArchiveInstaller())->install($zip);
            self::fail('An unsigned licensed archive must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('signature', strtolower($exception->getMessage()));
        }
    }

    public function testSignedArchiveWithValidChecksumsIsAccepted(): void
    {
        $this->setEnv('EXTENSION_REQUIRE_SIGNATURE', '1');
        $this->setEnv('EXTENSION_SIGNING_PUBLIC_KEY', $this->publicKey);
        $png = $this->png();
        $theme = $this->json($this->package([
            'license' => ['required' => true, 'sku' => 'siro-theme-atelier-noir'],
            'brand' => ['name' => 'Noir', 'logo_url' => 'assets/logo.png'],
        ]));
        $checksums = $this->json(['assets/logo.png' => hash('sha256', $png)]);
        $signature = $this->signature($theme, $checksums);
        $zip = $this->zip([
            'theme.json' => $theme,
            'checksums.json' => $checksums,
            'signature.json' => $this->json($signature),
            'assets/logo.png' => $png,
        ]);

        $result = (new ThemePackageArchiveInstaller())->install($zip);

        self::assertTrue($result['signed']);
        Storage::assertExists('themes/pharmacy/' . hash('sha256', $png) . '.png');
    }

    public function testInvalidSignatureIsRejectedBeforeAssetsAreWritten(): void
    {
        $this->setEnv('EXTENSION_REQUIRE_SIGNATURE', '1');
        $this->setEnv('EXTENSION_SIGNING_PUBLIC_KEY', $this->publicKey);
        $png = $this->png();
        $theme = $this->json($this->package(['license' => ['required' => true, 'sku' => 'siro-theme-atelier-noir']]));
        $checksums = $this->json(['assets/logo.png' => hash('sha256', $png)]);
        $signature = $this->signature(str_replace('pharmacy', 'pharmacx', $theme), $checksums);
        $zip = $this->zip([
            'theme.json' => $theme,
            'checksums.json' => $checksums,
            'signature.json' => $this->json($signature),
            'assets/logo.png' => $png,
        ]);

        try {
            (new ThemePackageArchiveInstaller())->install($zip);
            self::fail('An invalid signature must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('signature', strtolower($exception->getMessage()));
        }
        Storage::assertMissing('themes/pharmacy/' . hash('sha256', $png) . '.png');
    }

    public function testChecksumMismatchIsRejectedBeforeAssetsAreWritten(): void
    {
        $png = $this->png();
        $theme = $this->json($this->package());
        $checksums = $this->json(['assets/logo.png' => str_repeat('0', 64)]);
        $zip = $this->zip([
            'theme.json' => $theme,
            'checksums.json' => $checksums,
            'assets/logo.png' => $png,
        ]);

        try {
            (new ThemePackageArchiveInstaller())->install($zip);
            self::fail('A checksum mismatch must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('checksum', strtolower($exception->getMessage()));
        }
        Storage::assertMissing('themes/pharmacy/' . hash('sha256', $png) . '.png');
    }

    public function testSignatureWithoutChecksumsIsRejected(): void
    {
        $theme = $this->json($this->package());
        $signature = ['algorithm' => 'ed25519', 'key_id' => 'default', 'signature' => base64_encode('x')];
        $zip = $this->zip([
            'theme.json' => $theme,
            'signature.json' => $this->json($signature),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('checksums.json');
        (new ThemePackageArchiveInstaller())->install($zip);
    }

    public function testRejectsExecutableAndUnsafeAssets(): void
    {
        foreach (['assets/shell.php', 'assets/icon.svg', 'assets/app.js', 'assets/page.html'] as $asset) {
            $zip = $this->zip([
                'theme.json' => $this->json($this->package(['id' => 'acme/bad', 'key' => 'bad'])),
                $asset => '<?php echo 1;',
            ]);
            try {
                (new ThemePackageArchiveInstaller())->install($zip);
                self::fail('Asset should have been rejected: ' . $asset);
            } catch (\InvalidArgumentException) {
                $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
                Storage::assertMissing('themes/bad/' . hash('sha256', '<?php echo 1;') . '.' . $extension);
            }
        }
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function package(array $overrides = []): array
    {
        return array_replace([
            'schema' => 'siro.theme/1',
            'id' => 'acme/pharmacy',
            'name' => 'Pharmacy',
            'version' => '1.0.0',
            'homepage' => ['sections' => [['id' => 'hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => []]]],
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array{algorithm: string, key_id: string, signature: string} */
    private function signature(string $themeRaw, string $checksumsRaw): array
    {
        if ($this->secretKey === '' || !function_exists('sodium_crypto_sign_detached')) {
            self::markTestSkipped('ext-sodium is required for signature tests');
        }
        $payload = hash('sha256', $themeRaw) . "\n" . hash('sha256', $checksumsRaw);
        $secret = base64_decode($this->secretKey, true);
        self::assertIsString($secret);

        return [
            'algorithm' => 'ed25519',
            'key_id' => 'default',
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secret)),
        ];
    }

    private function png(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        self::assertIsString($png);

        return $png;
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'theme-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $content) $zip->addFromString($name, $content);
        $zip->close();

        return $path;
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
