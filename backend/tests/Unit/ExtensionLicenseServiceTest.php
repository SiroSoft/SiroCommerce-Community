<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Extension\Application\ExtensionLicenseService;
use App\Modules\Extension\Application\ExtensionLicenseStore;
use App\Console\Commands\ExtensionLicenseCommand;
use PHPUnit\Framework\TestCase;

final class ExtensionLicenseServiceTest extends TestCase
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
            $env = $_ENV[$key] ?? null;
            $this->originalEnv[$key] = is_scalar($env) ? (string) $env : '';
            if (!array_key_exists($key, $_ENV)) {
                unset($this->originalEnv[$key]);
            }
            $putenv = getenv($key);
            if ($putenv !== false) {
                $this->originalPutenv[$key] = $putenv;
            }
            $this->setEnv($key, null);
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-license-' . bin2hex(random_bytes(4));
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
    }

    public function testCommunityEditionWithoutLicenseIsEntitled(): void
    {
        $service = new ExtensionLicenseService();
        $status = $service->status();

        self::assertTrue($status['ok']);
        self::assertSame('community', $status['edition']);
        self::assertSame('community', $status['reason']);
        self::assertNull($status['license_id']);
        self::assertNull($status['expires_at']);
        self::assertTrue($service->entitled('siro/anything'));
        self::assertTrue($service->entitled('siro/anything', 'some-feature'));
    }

    public function testValidSignedLicenseGrantsExtensionsAndFeatures(): void
    {
        $payload = $this->payload();
        $json = $this->signedLicense($payload);
        $status = (new ExtensionLicenseService())->load($json);

        self::assertTrue($status['ok'], $status['reason']);
        self::assertSame('pro', $status['edition']);
        self::assertSame('lic_test', $status['license_id']);
        self::assertSame(['siro/alpha'], $status['extensions']);
        self::assertSame(['reports'], $status['features']);
        self::assertSame('valid', $status['reason']);
        $expiresAt = (int) strtotime((string) $payload['expires_at']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $expiresAt), $status['expires_at']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $expiresAt + (30 * 86400)), $status['grace_until']);
        self::assertStringNotContainsString('BEGIN PUBLIC KEY', (string) json_encode($status));
    }

    public function testEntitlementChecksExtensionAndFeature(): void
    {
        $this->activate($this->signedLicense($this->payload()));
        $service = new ExtensionLicenseService();
        self::assertTrue($service->entitled('siro/alpha'));
        self::assertTrue($service->entitled('siro/alpha', 'reports'));
        self::assertFalse($service->entitled('siro/beta'));
        self::assertFalse($service->entitled('siro/alpha', 'exports'));
    }

    public function testWildcardExtensionsAndFeaturesEntitleEverything(): void
    {
        $this->activate($this->signedLicense($this->payload(['extensions' => '*', 'features' => ['*']])));
        $service = new ExtensionLicenseService();

        self::assertTrue($service->entitled('siro/anything'));
        self::assertTrue($service->entitled('siro/anything', 'any-feature'));
    }

    public function testTamperedPayloadFailsSignatureVerification(): void
    {
        $payload = $this->payload();
        $signature = $this->signature($payload);
        $tampered = $payload;
        $tampered['extensions'] = ['siro/evil'];
        $json = $this->document($tampered, $signature);
        $status = (new ExtensionLicenseService())->load($json);

        self::assertFalse($status['ok']);
        self::assertSame('invalid_signature', $status['reason']);
        $this->activate($json);
        self::assertFalse((new ExtensionLicenseService())->entitled('siro/evil'));
    }

    public function testMalformedBase64SignatureIsRejected(): void
    {
        $json = $this->document($this->payload(), 'not-valid-base64!!!');
        $status = (new ExtensionLicenseService())->load($json);

        self::assertFalse($status['ok']);
        self::assertSame('invalid_signature', $status['reason']);
    }

    public function testExpiredBeyondGraceIsRejected(): void
    {
        $payload = $this->payload([
            'expires_at' => time() - (10 * 86400),
            'grace_days' => 5,
        ]);
        $json = $this->signedLicense($payload);
        $status = (new ExtensionLicenseService())->load($json);

        self::assertFalse($status['ok']);
        self::assertSame('expired', $status['reason']);
        $this->activate($json);
        self::assertFalse((new ExtensionLicenseService())->entitled('siro/alpha'));
    }

    public function testLicenseInsideGracePeriodRemainsEntitled(): void
    {
        $payload = $this->payload([
            'expires_at' => time() - 3600,
            'grace_days' => 30,
        ]);
        $json = $this->signedLicense($payload);
        $status = (new ExtensionLicenseService())->load($json);

        self::assertTrue($status['ok']);
        self::assertSame('grace', $status['reason']);
        $this->activate($json);
        self::assertTrue((new ExtensionLicenseService())->entitled('siro/alpha'));
    }

    public function testNotYetValidLicenseIsRejected(): void
    {
        $payload = $this->payload(['not_before' => time() + 3600]);
        $json = $this->signedLicense($payload);
        $status = (new ExtensionLicenseService())->load($json);

        self::assertFalse($status['ok']);
        self::assertSame('not_yet_valid', $status['reason']);
        $this->activate($json);
        self::assertFalse((new ExtensionLicenseService())->entitled('siro/alpha'));
    }

    public function testUnknownEditionIsRejected(): void
    {
        $json = $this->signedLicense($this->payload(['edition' => 'platinum']));
        $status = (new ExtensionLicenseService())->load($json);

        self::assertFalse($status['ok']);
        self::assertSame('invalid_edition', $status['reason']);
    }

    public function testMissingPublicKeyIsReportedWithoutLeakingMaterial(): void
    {
        $json = $this->signedLicense($this->payload());
        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY', null);
        $status = (new ExtensionLicenseService())->load($json);

        self::assertFalse($status['ok']);
        self::assertSame('public_key_missing', $status['reason']);
    }

    public function testPublicKeyFromFileAndLicensePathFromEnv(): void
    {
        $publicKeyFile = $this->tempDir . DIRECTORY_SEPARATOR . 'public.pem';
        self::assertNotFalse(file_put_contents($publicKeyFile, $this->publicKeyPem));
        $licenseFile = $this->tempDir . DIRECTORY_SEPARATOR . 'license.json';
        self::assertNotFalse(file_put_contents($licenseFile, $this->signedLicense($this->payload())));

        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY', null);
        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY_FILE', $publicKeyFile);
        $this->setEnv('EXTENSION_LICENSE_FILE', $licenseFile);

        $service = new ExtensionLicenseService();
        $status = $service->status();

        self::assertTrue($status['ok'], $status['reason']);
        self::assertSame('pro', $status['edition']);
        self::assertTrue($service->entitled('siro/alpha', 'reports'));
    }

    public function testMissingLicenseFileIsInvalidButUnconfiguredLicenseIsCommunity(): void
    {
        $service = new ExtensionLicenseService();
        $status = $service->status($this->tempDir . DIRECTORY_SEPARATOR . 'missing.json');

        self::assertFalse($status['ok']);
        self::assertSame('license_file_not_found', $status['reason']);
        self::assertSame('community', $service->status()['edition']);
    }

    public function testMalformedJsonIsRejected(): void
    {
        $status = (new ExtensionLicenseService())->load('{"payload":');

        self::assertFalse($status['ok']);
        self::assertSame('malformed_license', $status['reason']);
    }

    public function testStatusFallsBackToPortalStoreWhenEnvUnset(): void
    {
        $storeFile = $this->tempDir . DIRECTORY_SEPARATOR . 'active.json';
        $this->setEnv('EXTENSION_LICENSE_STORE_FILE', $storeFile);
        (new ExtensionLicenseStore(null, $storeFile))->write($this->signedLicense($this->payload()));

        $service = new ExtensionLicenseService();
        $status = $service->status();

        self::assertTrue($status['ok'], $status['reason']);
        self::assertSame('pro', $status['edition']);
        self::assertSame(ExtensionLicenseService::SOURCE_STORAGE, $service->source());
        self::assertTrue($service->stored());
        self::assertTrue($service->entitled('siro/alpha', 'reports'));
    }

    public function testEnvLicenseFileOverridesPortalStore(): void
    {
        $storeFile = $this->tempDir . DIRECTORY_SEPARATOR . 'active.json';
        $this->setEnv('EXTENSION_LICENSE_STORE_FILE', $storeFile);
        (new ExtensionLicenseStore(null, $storeFile))->write(
            $this->signedLicense($this->payload(['extensions' => ['siro/beta']]))
        );

        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . 'env-license.json';
        self::assertNotFalse(file_put_contents($envFile, $this->signedLicense($this->payload())));
        $this->setEnv('EXTENSION_LICENSE_FILE', $envFile);

        $service = new ExtensionLicenseService();
        $status = $service->status();

        self::assertTrue($status['ok'], $status['reason']);
        self::assertSame(ExtensionLicenseService::SOURCE_ENV, $service->source());
        self::assertTrue($service->entitled('siro/alpha'));
        self::assertFalse($service->entitled('siro/beta'));
    }

    public function testNoLicenseSourceIsCommunityAndNone(): void
    {
        $this->setEnv('EXTENSION_LICENSE_STORE_FILE', $this->tempDir . DIRECTORY_SEPARATOR . 'missing.json');

        $service = new ExtensionLicenseService();
        $status = $service->status();

        self::assertSame('community', $status['edition']);
        self::assertSame(ExtensionLicenseService::SOURCE_NONE, $service->source());
        self::assertFalse($service->stored());
    }

    public function testCorruptPortalStoreIsReportedAsMalformed(): void
    {
        $storeFile = $this->tempDir . DIRECTORY_SEPARATOR . 'active.json';
        self::assertNotFalse(file_put_contents($storeFile, '{"payload":'));
        $this->setEnv('EXTENSION_LICENSE_STORE_FILE', $storeFile);

        $service = new ExtensionLicenseService();
        $status = $service->status();

        self::assertFalse($status['ok']);
        self::assertSame('malformed_license', $status['reason']);
        self::assertSame(ExtensionLicenseService::SOURCE_STORAGE, $service->source());
    }

    public function testCommandReportsStatusAndExitCodeWithoutSpawningProcess(): void
    {
        $licenseFile = $this->tempDir . DIRECTORY_SEPARATOR . 'license.json';
        self::assertNotFalse(file_put_contents($licenseFile, $this->signedLicense($this->payload())));

        $command = new ExtensionLicenseCommand();
        ob_start();
        $code = $command->run([$licenseFile, '--json']);
        $output = ob_get_clean();

        self::assertSame(0, $code);
        self::assertIsString($output);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('pro', $decoded['license']['edition']);
        self::assertTrue($decoded['entitlements']['all_extensions'] === false);
        self::assertStringNotContainsString('BEGIN PUBLIC KEY', $output);
    }

    public function testCommandExitsNonZeroForInvalidLicense(): void
    {
        $expired = $this->signedLicense($this->payload([
            'expires_at' => time() - (60 * 86400),
            'grace_days' => 1,
        ]));
        $licenseFile = $this->tempDir . DIRECTORY_SEPARATOR . 'expired.json';
        self::assertNotFalse(file_put_contents($licenseFile, $expired));

        $command = new ExtensionLicenseCommand();
        ob_start();
        $code = $command->run([$licenseFile]);
        ob_end_clean();

        self::assertSame(1, $code);
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
            'extensions' => ['siro/alpha'],
            'features' => ['reports'],
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'not_before' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600),
            'grace_days' => 30,
            'limits' => ['extensions' => 5],
        ];

        foreach ($overrides as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function signedLicense(array $payload): string
    {
        return $this->document($payload, $this->signature($payload));
    }

    /** @param array<string, mixed> $payload */
    private function signature(array $payload): string
    {
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = '';
        self::assertTrue(openssl_sign($canonical, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));

        return base64_encode($signature);
    }

    /** @param array<string, mixed> $payload */
    private function document(array $payload, string $signature): string
    {
        return json_encode(
            ['payload' => $payload, 'signature' => $signature],
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
