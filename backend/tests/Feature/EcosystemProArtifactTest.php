<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Models\Extension;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\EcosystemCatalogService;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Application\ProArtifactService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Tests\Fixtures\ProArtifactFixtureExtension;
use Tests\TestCase;

/**
 * Private Pro artifact pipeline: signed artifact metadata/tokens, entitlement
 * gating, checksum/signature verification, install/update without auto-enable,
 * and safe errors that never expose paths or key material.
 */
final class EcosystemProArtifactTest extends TestCase
{
    private const ENV_KEYS = [
        'EXTENSION_LICENSE_PUBLIC_KEY',
        'EXTENSION_LICENSE_PUBLIC_KEY_FILE',
        'EXTENSION_LICENSE_FILE',
        'EXTENSION_SIGNING_PRIVATE_KEY',
        'EXTENSION_SIGNING_PUBLIC_KEYS',
        'EXTENSION_SIGNING_KEY_ID',
        'PRO_ARTIFACT_DIR',
        'PRO_ARTIFACT_TOKEN_KEY',
    ];

    private Router $router;

    /** @var array<string, string> */
    private array $originalEnv = [];

    /** @var array<string, string> */
    private array $originalPutenv = [];

    private string $root;

    private string $catalogPath;

    private string $artifactDir;

    private string $sourceDir;

    private int $licenseCounter = 0;

    /** @var \OpenSSLAsymmetricKey */
    private mixed $licensePrivateKey;

    private string $licensePublicKeyPem;

    private string $signingPrivateKey = '';

    private string $tokenKey = 'test-pro-artifact-token-key-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        ExtensionRegistry::reset();

        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $_ENV)) {
                $env = $_ENV[$key];
                $this->originalEnv[$key] = is_scalar($env) ? (string) $env : '';
            }
            $putenv = getenv($key);
            if ($putenv !== false) {
                $this->originalPutenv[$key] = $putenv;
            }
            $this->setEnv($key, null);
        }

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-pro-artifact-' . bin2hex(random_bytes(4));
        $this->artifactDir = $this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pro';
        $this->catalogPath = $this->root . DIRECTORY_SEPARATOR . 'catalog.json';
        $this->sourceDir = $this->root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'pro-fixture';
        foreach ([
            $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Extensions',
            $this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework',
            $this->artifactDir,
            $this->sourceDir,
        ] as $dir) {
            self::assertTrue(mkdir($dir, 0775, true) || is_dir($dir), 'Could not create test directory');
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, 'Unable to generate RSA key pair for Pro artifact tests');
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        $this->licensePrivateKey = $key;
        $this->licensePublicKeyPem = $details['key'];
        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY', $this->licensePublicKeyPem);

        if (function_exists('sodium_crypto_sign_keypair')) {
            $pair = sodium_crypto_sign_keypair();
            $this->signingPrivateKey = base64_encode(sodium_crypto_sign_secretkey($pair));
            $this->setEnv('EXTENSION_SIGNING_PRIVATE_KEY', $this->signingPrivateKey);
            $this->setEnv('EXTENSION_SIGNING_PUBLIC_KEYS', (string) json_encode([
                'key-1' => base64_encode(sodium_crypto_sign_publickey($pair)),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->setEnv('EXTENSION_SIGNING_KEY_ID', 'key-1');
        }

        $this->setEnv('PRO_ARTIFACT_DIR', $this->artifactDir);
        $this->setEnv('PRO_ARTIFACT_TOKEN_KEY', $this->tokenKey);
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        ProArtifactFixtureExtension::$version = '1.0.0';

        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $this->originalEnv)) {
                $this->setEnv($key, $this->originalEnv[$key]);
            } else {
                $this->setEnv($key, null);
            }
        }

        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testProFeedRequiresLicenseAndExposesOnlySafeArtifactMetadata(): void
    {
        $packed = $this->packSignedArtifact('1.0.0');
        $this->writeCatalog('1.0.0', $packed['sha256']);

        try {
            $this->catalogService()->proFeed();
            self::fail('Pro feed without a license must fail');
        } catch (\RuntimeException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame(EcosystemCatalogService::PRO_FEED_LICENSE_REQUIRED, $e->getMessage());
        }

        $this->activate($this->signedLicense($this->licensePayload(['extensions' => ['siro/pro-fixture']])));
        $feed = $this->catalogService()->proFeed();

        self::assertCount(1, $feed['items']);
        $item = $feed['items'][0];
        self::assertSame('siro/pro-fixture', $item['id']);
        self::assertSame('pro', $item['edition']);
        self::assertSame('ed25519:key-1', $item['signature']);
        self::assertSame('valid', $item['entitlement']);
        self::assertTrue($item['installable']);
        self::assertFalse($item['can_update']);

        $artifact = $item['artifact'];
        self::assertIsArray($artifact);
        self::assertTrue($artifact['available']);
        self::assertSame('siro-pro-fixture-1.0.0.zip', $artifact['filename']);
        self::assertSame($packed['sha256'], $artifact['sha256']);
        self::assertTrue($artifact['signed']);
        self::assertIsString($artifact['download_token']);
        self::assertNotSame('', $artifact['download_token']);
        self::assertSame(ProArtifactService::TOKEN_TTL, $artifact['download_expires_in']);

        $encoded = (string) json_encode($feed);
        self::assertStringNotContainsString($this->root, $encoded);
        self::assertStringNotContainsString($this->artifactDir, $encoded);
        self::assertStringNotContainsString($this->signingPrivateKey, $encoded);
        self::assertStringNotContainsString($this->tokenKey, $encoded);
        self::assertStringNotContainsString('PRO_ARTIFACT_DIR', $encoded);
    }

    public function testGraceIsAllowedButExpiredAndCommunityAreForbidden(): void
    {
        $packed = $this->packSignedArtifact('1.0.0');
        $this->writeCatalog('1.0.0', $packed['sha256']);

        $this->activate($this->signedLicense($this->licensePayload([
            'extensions' => ['siro/pro-fixture'],
            'expires_at' => time() - 3600,
            'grace_days' => 30,
        ])));
        $feed = $this->catalogService()->proFeed();
        self::assertSame('grace', $feed['items'][0]['entitlement']);
        self::assertArrayHasKey('download_token', $feed['items'][0]['artifact']);

        $this->activate($this->signedLicense($this->licensePayload([
            'extensions' => ['siro/pro-fixture'],
            'expires_at' => time() - (10 * 86400),
            'grace_days' => 5,
        ])));
        try {
            $this->catalogService()->proFeed();
            self::fail('Expired license must not expose the Pro feed');
        } catch (\RuntimeException $e) {
            self::assertSame(403, $e->getCode());
        }

        $this->setEnv('EXTENSION_LICENSE_FILE', $this->root . DIRECTORY_SEPARATOR . 'missing-license.json');
        try {
            $this->catalogService()->proFeed();
            self::fail('Missing/community license must not expose the Pro feed');
        } catch (\RuntimeException $e) {
            self::assertSame(403, $e->getCode());
        }
    }

    public function testNonEntitledItemHasNoArtifactTokenAndCannotInstall(): void
    {
        $packed = $this->packSignedArtifact('1.0.0');
        $this->writeCatalog('1.0.0', $packed['sha256']);
        $this->activate($this->signedLicense($this->licensePayload(['extensions' => ['siro/other']])));

        $feed = $this->catalogService()->proFeed();
        $item = $feed['items'][0];
        self::assertSame('missing', $item['entitlement']);
        self::assertNull($item['artifact']);
        self::assertFalse($item['installable']);
        self::assertFalse($item['can_update']);
        self::assertSame('Pro license does not entitle this item', $item['action_reason']);

        try {
            $this->artifactService()->install('siro/pro-fixture');
            self::fail('Non-entitled install must fail');
        } catch (\RuntimeException $e) {
            self::assertSame(403, $e->getCode());
            self::assertStringNotContainsString($this->root, $e->getMessage());
        }
    }

    public function testDownloadTokenResolvesOnlyTheExactArtifact(): void
    {
        $packed = $this->packSignedArtifact('1.0.0');
        $this->writeCatalog('1.0.0', $packed['sha256']);
        $this->activate($this->signedLicense($this->licensePayload(['extensions' => ['siro/pro-fixture']])));

        $feed = $this->catalogService()->proFeed();
        $token = $feed['items'][0]['artifact']['download_token'];
        self::assertIsString($token);

        $download = $this->artifactService()->resolveDownload($token);
        self::assertSame('siro-pro-fixture-1.0.0.zip', $download['filename']);
        self::assertFileExists($download['path']);
        self::assertStringStartsWith($this->artifactDir, $download['path']);

        $payload = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true), true);
        self::assertIsArray($payload);
        self::assertSame('siro/pro-fixture', $payload['id'] ?? null);
        self::assertSame('1.0.0', $payload['version'] ?? null);
        self::assertSame($packed['sha256'], $payload['sha256'] ?? null);
        self::assertArrayNotHasKey('path', $payload);
        self::assertStringNotContainsString($this->root, (string) json_encode($payload));

        $parts = explode('.', $token);
        $parts[1][0] = $parts[1][0] === 'A' ? 'B' : 'A';
        $tampered = implode('.', $parts);
        try {
            $this->artifactService()->resolveDownload($tampered);
            self::fail('Tampered token must fail');
        } catch (\RuntimeException $e) {
            self::assertSame(403, $e->getCode());
            self::assertStringNotContainsString($this->root, $e->getMessage());
        }
    }

    public function testInstallDoesNotEnableAndUpdatePreservesDisabledState(): void
    {
        $first = $this->packSignedArtifact('1.0.0');
        $this->writeCatalog('1.0.0', $first['sha256']);
        $this->activate($this->signedLicense($this->licensePayload(['extensions' => ['siro/pro-fixture']])));

        $service = $this->artifactService();
        $installed = $service->install('siro/pro-fixture');

        self::assertSame('installed', $installed['state']);
        self::assertSame('1.0.0', $installed['version']);
        self::assertNull($installed['previous_version']);
        self::assertFalse($installed['updated']);
        self::assertFalse($installed['backup']);
        self::assertFileExists($this->root . '/app/Extensions/Siro/ProFixture/Extension.php');
        self::assertNull(ExtensionRegistry::extension('siro/pro-fixture'));

        $row = Extension::query()->where('id', 'siro/pro-fixture')->first();
        self::assertInstanceOf(Extension::class, $row);
        self::assertSame('installed', $row->state);

        $lifecycle = new ExtensionLifecycleService([ProArtifactFixtureExtension::class]);
        $lifecycle->disable('siro/pro-fixture');

        $second = $this->packSignedArtifact('1.1.0');
        ProArtifactFixtureExtension::$version = '1.1.0';
        $this->writeCatalog('1.1.0', $second['sha256']);

        $updated = $service->install('siro/pro-fixture', '1.1.0', true);
        self::assertSame('disabled', $updated['state']);
        self::assertSame('1.1.0', $updated['version']);
        self::assertSame('1.0.0', $updated['previous_version']);
        self::assertTrue($updated['updated']);
        self::assertTrue($updated['backup']);

        $row = Extension::query()->where('id', 'siro/pro-fixture')->first();
        self::assertInstanceOf(Extension::class, $row);
        self::assertSame('disabled', $row->state);
        self::assertSame('1.1.0', $row->version);
        self::assertNull(ExtensionRegistry::extension('siro/pro-fixture'));
    }

    public function testUnsignedArtifactAndChecksumMismatchAreRejected(): void
    {
        $unsigned = $this->packUnsignedArtifact('1.0.0');
        $this->writeCatalog('1.0.0', $unsigned['sha256'], null);
        $this->activate($this->signedLicense($this->licensePayload(['extensions' => ['siro/pro-fixture']])));

        $feed = $this->catalogService()->proFeed();
        self::assertFalse($feed['items'][0]['artifact']['available']);
        self::assertFalse($feed['items'][0]['artifact']['signed']);
        self::assertStringContainsStringIgnoringCase('not signed', (string) $feed['items'][0]['artifact']['reason']);

        try {
            $this->artifactService()->install('siro/pro-fixture');
            self::fail('Unsigned Pro artifact must not install');
        } catch (\RuntimeException $e) {
            self::assertSame(422, $e->getCode());
            self::assertStringContainsStringIgnoringCase('signed', $e->getMessage());
            self::assertStringNotContainsString($this->root, $e->getMessage());
        }

        $signed = $this->packSignedArtifact('1.0.0');
        $this->writeCatalog('1.0.0', str_repeat('0', 64));

        try {
            $this->artifactService()->install('siro/pro-fixture');
            self::fail('Checksum mismatch must not install');
        } catch (\RuntimeException $e) {
            self::assertSame(422, $e->getCode());
            self::assertStringContainsStringIgnoringCase('checksum', $e->getMessage());
            self::assertStringNotContainsString($this->root, $e->getMessage());
            self::assertNotSame($signed['sha256'], str_repeat('0', 64));
        }
    }

    public function testMissingArtifactFailsClosedWithoutLeakingPaths(): void
    {
        $this->writeCatalog('1.0.0', str_repeat('a', 64));
        $this->activate($this->signedLicense($this->licensePayload(['extensions' => ['siro/pro-fixture']])));

        $feed = $this->catalogService()->proFeed();
        self::assertFalse($feed['items'][0]['artifact']['available']);
        self::assertNull($feed['items'][0]['artifact']['filename']);
        self::assertStringContainsStringIgnoringCase('not available', (string) $feed['items'][0]['artifact']['reason']);

        try {
            $this->artifactService()->install('siro/pro-fixture');
            self::fail('Missing artifact must not install');
        } catch (\RuntimeException $e) {
            self::assertSame(404, $e->getCode());
            self::assertStringNotContainsString($this->root, $e->getMessage());
            self::assertStringNotContainsString($this->artifactDir, $e->getMessage());
        }
    }

    public function testBuildScriptEmitsSignedMetadataWithoutSecretsOrPaths(): void
    {
        if ($this->signingPrivateKey === '') {
            self::markTestSkipped('ext-sodium is unavailable for the Pro artifact build script');
        }
        $php = $this->phpWithSodium();
        if ($php === null) {
            self::markTestSkipped('ext-sodium is unavailable for the Pro artifact build script');
        }

        $script = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ecosystem-catalog' . DIRECTORY_SEPARATOR . 'package-pro.php';
        $this->writeSourceManifest('1.0.0');
        $env = getenv();
        $env['EXTENSION_SIGNING_PRIVATE_KEY'] = $this->signingPrivateKey;
        $env['EXTENSION_SIGNING_KEY_ID'] = 'key-1';

        $command = array_merge($php, [
            $script,
            '--source=' . $this->sourceDir,
            '--out=' . $this->artifactDir,
            '--key=key-1',
            '--json',
        ]);
        $result = $this->runProcess($command, $env);

        self::assertSame(0, $result['code'], $result['stderr']);
        $metadata = json_decode($result['stdout'], true);
        self::assertIsArray($metadata);
        self::assertSame(1, $metadata['count'] ?? null);
        $artifact = $metadata['artifacts'][0] ?? null;
        self::assertIsArray($artifact);
        self::assertSame('siro/pro-fixture', $artifact['id'] ?? null);
        self::assertSame('1.0.0', $artifact['version'] ?? null);
        self::assertSame('siro-pro-fixture-1.0.0.zip', $artifact['filename'] ?? null);
        self::assertTrue($artifact['signed'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($artifact['sha256'] ?? ''));
        self::assertFileExists($this->artifactDir . DIRECTORY_SEPARATOR . 'siro-pro-fixture-1.0.0.zip');
        self::assertSame(hash_file('sha256', $this->artifactDir . DIRECTORY_SEPARATOR . 'siro-pro-fixture-1.0.0.zip'), $artifact['sha256'] ?? null);
        self::assertStringNotContainsString($this->signingPrivateKey, $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString($this->artifactDir, $result['stdout'] . $result['stderr']);
    }

    public function testProRoutesRequireAdminAndIdempotency(): void
    {
        foreach ([
            'POST /api/admin/ecosystem/pro-artifacts/download',
            'POST /api/admin/ecosystem/pro/install',
            'POST /api/admin/ecosystem/pro/update',
        ] as $route) {
            [$method, $path] = explode(' ', $route, 2);
            self::assertTrue($this->routeRegistered($method, $path), $route . ' is not mounted');
            self::assertSame(401, $this->call($method, $path, [])['status']);
            self::assertSame(403, $this->call($method, $path, [], $this->headers('viewer'))['status']);
        }

        $admin = $this->headers('admin');
        self::assertSame(422, $this->call('POST', '/api/admin/ecosystem/pro/install', ['id' => 'siro/pro-fixture'], $admin)['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/ecosystem/pro/update', ['id' => 'siro/pro-fixture'], $admin)['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/ecosystem/pro-artifacts/download', [], $admin)['status']);
    }

    private function catalogService(): EcosystemCatalogService
    {
        return new EcosystemCatalogService($this->root, $this->catalogPath);
    }

    private function artifactService(): ProArtifactService
    {
        return new ProArtifactService($this->root, $this->catalogPath, null, [ProArtifactFixtureExtension::class]);
    }

    /**
     * @return array{path: string, sha256: string}
     */
    private function packSignedArtifact(string $version): array
    {
        if ($this->signingPrivateKey === '') {
            self::markTestSkipped('ext-sodium is unavailable for signed Pro artifacts');
        }
        $this->writeSourceManifest($version);
        $output = $this->artifactDir . DIRECTORY_SEPARATOR . 'siro-pro-fixture-' . $version . '.zip';
        $packed = (new ExtensionPackageInstaller($this->root))->pack($this->sourceDir, $output, 'key-1');
        self::assertTrue($packed['signed']);

        return ['path' => $packed['path'], 'sha256' => $packed['sha256']];
    }

    /**
     * @return array{path: string, sha256: string}
     */
    private function packUnsignedArtifact(string $version): array
    {
        $this->writeSourceManifest($version);
        $previous = $this->signingPrivateKey;
        $this->setEnv('EXTENSION_SIGNING_PRIVATE_KEY', null);
        try {
            $output = $this->artifactDir . DIRECTORY_SEPARATOR . 'siro-pro-fixture-' . $version . '.zip';
            $packed = (new ExtensionPackageInstaller($this->root))->pack($this->sourceDir, $output, 'key-1');
        } finally {
            $this->setEnv('EXTENSION_SIGNING_PRIVATE_KEY', $previous === '' ? null : $previous);
        }
        self::assertFalse($packed['signed']);

        return ['path' => $packed['path'], 'sha256' => $packed['sha256']];
    }

    private function writeSourceManifest(string $version): void
    {
        $manifest = [
            'schema' => 'siro.extension/2',
            'id' => 'siro/pro-fixture',
            'type' => 'module',
            'name' => 'Pro Fixture',
            'description' => 'Signed Pro artifact fixture.',
            'version' => $version,
            'requires' => ['core' => '^1.0', 'commerce' => '^0.3'],
            'runtime' => ['php' => '>=8.2'],
            'provider' => 'App\\Extensions\\Siro\\ProFixture\\Extension',
            'capabilities' => [],
            'permissions' => [],
            'migrations' => [],
            'routes' => [],
            'license' => ['required' => true, 'sku' => 'siro-pro-fixture'],
        ];
        self::assertNotFalse(file_put_contents(
            $this->sourceDir . DIRECTORY_SEPARATOR . 'manifest.json',
            (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        ));
        self::assertNotFalse(file_put_contents(
            $this->sourceDir . DIRECTORY_SEPARATOR . 'Extension.php',
            "<?php\nnamespace App\\Extensions\\Siro\\ProFixture;\nfinal class Extension\n{\n}\n"
        ));
        self::assertNotFalse(file_put_contents($this->sourceDir . DIRECTORY_SEPARATOR . 'README.md', '# Pro Fixture'));
    }

    private function writeCatalog(string $version, string $sha256, ?string $signature = 'ed25519:key-1'): void
    {
        $item = [
            'id' => 'siro/pro-fixture',
            'type' => 'plugin',
            'name' => 'Pro Fixture',
            'description' => 'Signed Pro artifact fixture.',
            'vendor' => 'Siro',
            'edition' => 'pro',
            'status' => 'published',
            'latest_version' => $version,
            'min_core' => '^1.0',
            'min_commerce' => '^0.3',
            'license_required' => true,
            'license_sku' => 'siro-pro-fixture',
            'preview_url' => null,
            'changelog_url' => null,
            'changelog' => 'Signed Pro fixture artifact.',
            'artifact_url' => null,
            'sha256' => $sha256,
            'signature' => $signature,
            'published_at' => '2026-11-24',
            'tags' => ['pro'],
        ];
        $catalog = [
            'schema' => EcosystemCatalogService::SCHEMA,
            'generated_at' => '2026-11-24T00:00:00Z',
            'items' => [$item],
        ];
        self::assertNotFalse(file_put_contents(
            $this->catalogPath,
            (string) json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function licensePayload(array $overrides = []): array
    {
        $now = time();
        $payload = [
            'license_id' => 'lic_pro_artifact',
            'customer_id' => 'cust_1',
            'edition' => 'pro',
            'extensions' => ['siro/pro-fixture'],
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
        self::assertTrue(openssl_sign($canonical, $signature, $this->licensePrivateKey, OPENSSL_ALGO_SHA256));

        return json_encode(
            ['payload' => $payload, 'signature' => base64_encode($signature)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function activate(string $json): void
    {
        $this->licenseCounter++;
        $file = $this->root . DIRECTORY_SEPARATOR . 'active-' . $this->licenseCounter . '.json';
        self::assertNotFalse(file_put_contents($file, $json));
        $this->setEnv('EXTENSION_LICENSE_FILE', $file);
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

    /**
     * @return list<string>|null
     */
    private function phpWithSodium(): ?array
    {
        $probe = $this->runProcess([PHP_BINARY, '-d', 'extension=sodium', '-r', 'exit(function_exists("sodium_crypto_sign_detached") ? 0 : 1);'], getenv());
        if ($probe['code'] === 0) {
            return [PHP_BINARY, '-d', 'extension=sodium'];
        }

        return function_exists('sodium_crypto_sign_detached') ? [PHP_BINARY] : null;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, array $env): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->root, $env);
        if (!is_resource($process)) {
            return ['code' => 1, 'stdout' => '', 'stderr' => 'Could not start process'];
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function routeRegistered(string $method, string $path): bool
    {
        foreach ($this->router->getRoutes() as $route) {
            if (($route['method'] ?? '') === strtoupper($method) && ($route['path'] ?? '') === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $role): array
    {
        $email = 'pro-artifact-' . $role . '-' . bin2hex(random_bytes(4)) . '@shop.test';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');

        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, json: array<string, mixed>}
     */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
        $response = $this->router->dispatch($request);
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        $decoded = json_decode($output, true);

        return ['status' => $response->statusCode(), 'json' => is_array($decoded) ? $decoded : []];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
