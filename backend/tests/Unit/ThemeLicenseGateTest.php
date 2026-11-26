<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Extension\Application\ExtensionLicenseService;
use App\Modules\StorefrontConfig\Application\ThemeLicenseGate;
use PHPUnit\Framework\TestCase;

final class ThemeLicenseGateTest extends TestCase
{
    private const ENV_KEYS = [
        'EXTENSION_LICENSE_PUBLIC_KEY',
        'EXTENSION_LICENSE_PUBLIC_KEY_FILE',
        'EXTENSION_LICENSE_FILE',
        'EXTENSION_LICENSE_STORE_FILE',
    ];

    /** @var array<string, string> */
    private array $originalEnv = [];

    /** @var array<string, string> */
    private array $originalPutenv = [];

    private string $tempDir;

    private int $licenseCounter = 0;

    /** @var \OpenSSLAsymmetricKey */
    private mixed $privateKey;

    private string $publicKeyPem;

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $_ENV)) {
                $this->originalEnv[$key] = is_scalar($_ENV[$key]) ? (string) $_ENV[$key] : '';
            }
            $putenv = getenv($key);
            if ($putenv !== false) {
                $this->originalPutenv[$key] = $putenv;
            }
            $this->setEnv($key, null);
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-theme-gate-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key, 'Unable to generate RSA key pair for theme license tests');
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        $this->privateKey = $key;
        $this->publicKeyPem = $details['key'];
        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY', $this->publicKeyPem);
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $this->originalEnv)) {
                $this->setEnv($key, $this->originalEnv[$key]);
            } else {
                $this->setEnv($key, null);
            }
        }
        foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    public function testFreeThemeNeedsNoLicense(): void
    {
        (new ThemeLicenseGate())->assertEntitled(['required' => false, 'sku' => null]);
        self::assertTrue(true);
    }

    public function testCommunityEditionCannotImportLicensedTheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('siro-theme-atelier-noir');
        (new ThemeLicenseGate())->assertEntitled(['required' => true, 'sku' => 'siro-theme-atelier-noir']);
    }

    public function testMissingLicenseCannotImportLicensedTheme(): void
    {
        $this->setEnv('EXTENSION_LICENSE_FILE', $this->tempDir . DIRECTORY_SEPARATOR . 'missing.json');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('license_file_not_found');
        (new ThemeLicenseGate())->assertEntitled(['required' => true, 'sku' => 'siro-theme-atelier-noir']);
    }

    public function testExpiredLicenseCannotImportLicensedTheme(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'expires_at' => time() - (10 * 86400),
            'grace_days' => 5,
        ])));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expired');
        (new ThemeLicenseGate())->assertEntitled(['required' => true, 'sku' => 'siro-theme-atelier-noir']);
    }

    public function testValidLicenseWithSkuAllowsImport(): void
    {
        $this->activate($this->signedLicense($this->payload(['extensions' => ['siro-theme-atelier-noir']])));

        (new ThemeLicenseGate())->assertEntitled(['required' => true, 'sku' => 'siro-theme-atelier-noir']);
        self::assertTrue(true);
    }

    public function testGraceLicenseAllowsImport(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'extensions' => ['siro-theme-atelier-noir'],
            'expires_at' => time() - 3600,
            'grace_days' => 30,
        ])));

        (new ThemeLicenseGate())->assertEntitled(['required' => true, 'sku' => 'siro-theme-atelier-noir']);
        self::assertTrue(true);
    }

    public function testWildcardEnterpriseLicenseAllowsImport(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'edition' => 'enterprise',
            'extensions' => '*',
        ])));

        (new ThemeLicenseGate())->assertEntitled(['required' => true, 'sku' => 'siro-theme-apothecary-calm']);
        self::assertTrue(true);
    }

    public function testLicenseForAnotherSkuIsDenied(): void
    {
        $this->activate($this->signedLicense($this->payload(['extensions' => ['siro-theme-apothecary-calm']])));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('siro-theme-atelier-noir');
        (new ThemeLicenseGate())->assertEntitled(['required' => true, 'sku' => 'siro-theme-atelier-noir']);
    }

    public function testDenialNeverLeaksLicenseMaterial(): void
    {
        $this->activate($this->signedLicense($this->payload(['extensions' => ['siro-theme-apothecary-calm']])));

        try {
            (new ThemeLicenseGate())->assertEntitled(['required' => true, 'sku' => 'siro-theme-atelier-noir']);
            self::fail('A mismatched SKU must be denied');
        } catch (\InvalidArgumentException $exception) {
            $message = $exception->getMessage();
            self::assertStringNotContainsString('BEGIN', $message);
            self::assertStringNotContainsString((string) $this->tempDir, $message);
            self::assertStringNotContainsString('signature', strtolower($message));
        }
    }

    private function activate(string $json): void
    {
        $this->licenseCounter++;
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'license-' . $this->licenseCounter . '.json';
        self::assertNotFalse(file_put_contents($file, $json));
        $this->setEnv('EXTENSION_LICENSE_FILE', $file);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $now = time();
        $payload = [
            'license_id' => 'lic_theme',
            'customer_id' => 'cust_1',
            'edition' => 'pro',
            'extensions' => ['siro-theme-atelier-noir'],
            'features' => [],
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'not_before' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600),
            'grace_days' => 30,
        ];
        foreach ($overrides as $key => $value) $payload[$key] = $value;

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function signedLicense(array $payload): string
    {
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = '';
        self::assertTrue(openssl_sign($canonical, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));

        return json_encode(
            ['payload' => $payload, 'signature' => base64_encode($signature)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
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
