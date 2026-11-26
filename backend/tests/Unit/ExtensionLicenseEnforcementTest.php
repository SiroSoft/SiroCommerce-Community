<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ExtensionInterface;
use App\Extension\ExtensionRegistry;
use App\Models\Extension;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Domain\ExtensionManifest;
use Tests\TestCase;

final class LicenseGatedExtension implements ExtensionInterface
{
    public static int $bootCount = 0;

    public static function id(): string
    {
        return 'vendor/gated';
    }

    public static function compatibleCore(): string
    {
        return '^1.0';
    }

    public static function compatibleCommerce(): string
    {
        return '^0.1';
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'id' => self::id(),
            'version' => '1.0.0',
            'requires' => ['core' => self::compatibleCore(), 'commerce' => self::compatibleCommerce()],
            'capabilities' => [],
            'permissions' => [],
            'migrations' => [],
            'routes' => [],
            'license' => ['required' => true, 'sku' => 'sku-pro'],
        ];
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return [];
    }

    public function boot(): void
    {
        self::$bootCount++;
    }

    public function shutdown(): void
    {
    }

    /** @return array<string, \App\Contracts\PaymentGatewayInterface> */
    public function paymentGateways(): array
    {
        return [];
    }

    /** @return array<string, \App\Contracts\ShippingProviderInterface> */
    public function shippingProviders(): array
    {
        return [];
    }
}

final class LicenseOptionalExtension implements ExtensionInterface
{
    public static int $bootCount = 0;

    public static function id(): string
    {
        return 'vendor/optional';
    }

    public static function compatibleCore(): string
    {
        return '^1.0';
    }

    public static function compatibleCommerce(): string
    {
        return '^0.1';
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'id' => self::id(),
            'version' => '1.0.0',
            'requires' => ['core' => self::compatibleCore(), 'commerce' => self::compatibleCommerce()],
            'capabilities' => [],
            'permissions' => [],
            'migrations' => [],
            'routes' => [],
            'license' => ['required' => false],
        ];
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return [];
    }

    public function boot(): void
    {
        self::$bootCount++;
    }

    public function shutdown(): void
    {
    }

    /** @return array<string, \App\Contracts\PaymentGatewayInterface> */
    public function paymentGateways(): array
    {
        return [];
    }

    /** @return array<string, \App\Contracts\ShippingProviderInterface> */
    public function shippingProviders(): array
    {
        return [];
    }
}

final class ExtensionLicenseEnforcementTest extends TestCase
{
    private const ENV_KEYS = [
        'EXTENSION_LICENSE_PUBLIC_KEY',
        'EXTENSION_LICENSE_PUBLIC_KEY_FILE',
        'EXTENSION_LICENSE_FILE',
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
        parent::setUp();
        ExtensionRegistry::reset();
        LicenseGatedExtension::$bootCount = 0;
        LicenseOptionalExtension::$bootCount = 0;

        foreach (self::ENV_KEYS as $key) {
            $env = $_ENV[$key] ?? null;
            if (array_key_exists($key, $_ENV)) {
                $this->originalEnv[$key] = is_scalar($env) ? (string) $env : '';
            }
            $putenv = getenv($key);
            if ($putenv !== false) {
                $this->originalPutenv[$key] = $putenv;
            }
            $this->setEnv($key, null);
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-enforcement-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, 'Unable to generate RSA key pair for license tests');
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        $this->privateKey = $key;
        $this->publicKeyPem = $details['key'];
        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY', $this->publicKeyPem);
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $this->originalEnv)) {
                $this->setEnv($key, $this->originalEnv[$key]);
            } else {
                $this->setEnv($key, null);
            }
        }

        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function testEnableFailsWithoutProLicense(): void
    {
        $service = new ExtensionLifecycleService([LicenseGatedExtension::class]);
        $service->discover();

        try {
            $service->enable('vendor/gated');
            self::fail('Enabling a Pro-gated extension without a license must fail');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('requires a valid Pro license', $exception->getMessage());
        }

        self::assertSame('discovered', Extension::find('vendor/gated')?->state);
        self::assertNull(ExtensionRegistry::extension('vendor/gated'));
        self::assertSame(0, LicenseGatedExtension::$bootCount);
    }

    public function testEnableSucceedsWithValidProLicense(): void
    {
        $this->activate($this->signedLicense($this->payload(['extensions' => ['vendor/gated']])));

        $service = new ExtensionLifecycleService([LicenseGatedExtension::class]);
        $service->discover();
        $service->enable('vendor/gated');

        self::assertSame('enabled', Extension::find('vendor/gated')?->state);
        self::assertNotNull(ExtensionRegistry::extension('vendor/gated'));
        self::assertSame(1, LicenseGatedExtension::$bootCount);
    }

    public function testEnableSucceedsWithEnterpriseWildcardLicense(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'edition' => 'enterprise',
            'extensions' => '*',
        ])));

        $service = new ExtensionLifecycleService([LicenseGatedExtension::class]);
        $service->discover();
        $service->enable('vendor/gated');

        self::assertSame('enabled', Extension::find('vendor/gated')?->state);
        self::assertSame(1, LicenseGatedExtension::$bootCount);
    }

    public function testEnableFailsWhenLicenseExpiredBeyondGrace(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'extensions' => ['vendor/gated'],
            'expires_at' => time() - (10 * 86400),
            'grace_days' => 5,
        ])));

        $service = new ExtensionLifecycleService([LicenseGatedExtension::class]);
        $service->discover();

        try {
            $service->enable('vendor/gated');
            self::fail('Enabling a Pro-gated extension with an expired license must fail');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('requires a valid Pro license', $exception->getMessage());
            self::assertStringContainsString('expired', $exception->getMessage());
        }

        self::assertSame('discovered', Extension::find('vendor/gated')?->state);
        self::assertSame(0, LicenseGatedExtension::$bootCount);
    }

    public function testEnableFailsWhenExtensionIsNotListedInLicense(): void
    {
        $this->activate($this->signedLicense($this->payload(['extensions' => ['vendor/other']])));

        $service = new ExtensionLifecycleService([LicenseGatedExtension::class]);
        $service->discover();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a valid Pro license');
        $service->enable('vendor/gated');
    }

    public function testEnableSucceedsWhenLicenseIsNotRequired(): void
    {
        $service = new ExtensionLifecycleService([LicenseOptionalExtension::class]);
        $service->discover();
        $service->enable('vendor/optional');

        self::assertSame('enabled', Extension::find('vendor/optional')?->state);
        self::assertNotNull(ExtensionRegistry::extension('vendor/optional'));
        self::assertSame(1, LicenseOptionalExtension::$bootCount);
    }

    public function testManifestWithoutLicenseDeclarationRemainsCompatible(): void
    {
        $manifest = ExtensionManifest::fromArray(['id' => 'vendor/plain', 'version' => '1.0.0']);

        self::assertSame([], $manifest->license);
        self::assertSame([], $manifest->toArray()['license']);
    }

    public function testManifestRoundTripsLicenseDeclaration(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'id' => 'vendor/plain',
            'version' => '1.0.0',
            'license' => ['required' => true, 'sku' => 'sku-pro'],
        ]);

        self::assertSame(['required' => true, 'sku' => 'sku-pro'], $manifest->license);
        self::assertSame(['required' => true, 'sku' => 'sku-pro'], $manifest->toArray()['license']);
    }

    public function testManifestRejectsInvalidLicenseDeclarations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExtensionManifest::fromArray([
            'id' => 'vendor/plain',
            'version' => '1.0.0',
            'license' => ['required' => 'yes'],
        ]);
    }

    private function activate(string $json): void
    {
        $this->licenseCounter++;
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'active-' . $this->licenseCounter . '.json';
        self::assertNotFalse(file_put_contents($file, $json));
        $this->setEnv('EXTENSION_LICENSE_FILE', $file);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $now = time();
        $payload = [
            'license_id' => 'lic_test',
            'customer_id' => 'cust_1',
            'edition' => 'pro',
            'extensions' => ['vendor/gated'],
            'features' => [],
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'not_before' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600),
            'grace_days' => 30,
        ];

        foreach ($overrides as $key => $value) {
            $payload[$key] = $value;
        }

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
