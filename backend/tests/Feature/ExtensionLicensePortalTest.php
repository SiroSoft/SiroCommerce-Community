<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionLicenseStore;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * P2 admin-only license portal: import/remove a signed license envelope,
 * env-managed overrides, idempotency, auth and no-secret responses.
 */
final class ExtensionLicensePortalTest extends TestCase
{
    private const ENV_KEYS = [
        'EXTENSION_LICENSE_PUBLIC_KEY',
        'EXTENSION_LICENSE_PUBLIC_KEY_FILE',
        'EXTENSION_LICENSE_FILE',
        'EXTENSION_LICENSE_STORE_FILE',
    ];

    private Router $router;

    /** @var array<string, string> */
    private array $originalEnv = [];

    private string $tempDir;

    private string $storeFile;

    /** @var \OpenSSLAsymmetricKey */
    private mixed $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        ExtensionRegistry::reset();

        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $_ENV)) {
                $env = $_ENV[$key];
                $this->originalEnv[$key] = is_scalar($env) ? (string) $env : '';
            }
            $this->setEnv($key, null);
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-portal-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
        $this->storeFile = $this->tempDir . DIRECTORY_SEPARATOR . 'active.json';
        $this->setEnv('EXTENSION_LICENSE_STORE_FILE', $this->storeFile);

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, 'Unable to generate RSA key pair for portal tests');
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        $this->privateKey = $key;
        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY', $details['key']);

        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
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

    public function testImportValidLicenseStoresItAndDoesNotLeakSecrets(): void
    {
        $license = $this->signedLicense($this->payload());

        $response = $this->import($license, 'portal-valid-1');
        self::assertSame(200, $response['status'], (string) json_encode($response['json']));
        self::assertTrue($response['json']['data']['stored']);
        self::assertSame('storage', $response['json']['data']['source']);
        self::assertSame('pro', $response['json']['data']['status']['edition']);
        self::assertSame('valid', $response['json']['data']['status']['reason']);

        self::assertFileExists($this->storeFile);
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($this->storeFile) & 0777);
        }

        $status = $this->call('GET', '/api/admin/extensions/license', [], $this->headers('admin'));
        self::assertSame(200, $status['status']);
        self::assertSame('pro', $status['json']['data']['edition']);
        self::assertSame('storage', $status['json']['data']['source']);
        self::assertTrue($status['json']['data']['stored']);

        $encoded = (string) json_encode([$response['json'], $status['json']]);
        self::assertStringNotContainsString('signature', $encoded);
        self::assertStringNotContainsString('BEGIN PUBLIC KEY', $encoded);
        self::assertStringNotContainsString($this->tempDir, $encoded);
        self::assertStringNotContainsString($this->storeFile, $encoded);
    }

    public function testImportAcceptsLicenseObject(): void
    {
        $license = json_decode($this->signedLicense($this->payload()), true, 512, JSON_THROW_ON_ERROR);

        $response = $this->import($license, 'portal-object-1');

        self::assertSame(200, $response['status']);
        self::assertTrue($response['json']['data']['stored']);
        self::assertSame('pro', $response['json']['data']['status']['edition']);
    }

    public function testImportRejectsInvalidSignatureWithoutStoring(): void
    {
        $document = json_decode($this->signedLicense($this->payload()), true, 512, JSON_THROW_ON_ERROR);
        $document['payload']['extensions'] = ['siro/evil'];
        $tampered = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = $this->import($tampered, 'portal-bad-signature-1');

        self::assertSame(422, $response['status']);
        self::assertFileDoesNotExist($this->storeFile);
        self::assertStringNotContainsString('signature', strtolower((string) json_encode($response['json'])));
    }

    public function testImportRejectsMalformedAndMissingDocuments(): void
    {
        $malformed = $this->import('{"payload":', 'portal-malformed-1');
        self::assertSame(422, $malformed['status']);

        $missing = $this->call('POST', '/api/admin/extensions/license/import', [], $this->headers('admin') + ['Idempotency-Key' => 'portal-missing-1']);
        self::assertSame(422, $missing['status']);

        $notObject = $this->import('[1,2,3]', 'portal-array-1');
        self::assertSame(422, $notObject['status']);
        self::assertFileDoesNotExist($this->storeFile);
    }

    public function testImportRequiresIdempotencyKey(): void
    {
        $response = $this->call(
            'POST',
            '/api/admin/extensions/license/import',
            ['license' => $this->signedLicense($this->payload())],
            $this->headers('admin')
        );

        self::assertSame(422, $response['status']);
        self::assertFileDoesNotExist($this->storeFile);
    }

    public function testExpiredSignedLicenseIsStoredForTheRecord(): void
    {
        $license = $this->signedLicense($this->payload([
            'expires_at' => time() - (10 * 86400),
            'grace_days' => 1,
        ]));

        $response = $this->import($license, 'portal-expired-1');
        self::assertSame(200, $response['status']);
        self::assertTrue($response['json']['data']['stored']);
        self::assertFalse($response['json']['data']['status']['ok']);
        self::assertSame('expired', $response['json']['data']['status']['reason']);

        $status = $this->call('GET', '/api/admin/extensions/license', [], $this->headers('owner'));
        self::assertFalse($status['json']['data']['ok']);
        self::assertSame('expired', $status['json']['data']['reason']);
        self::assertSame('storage', $status['json']['data']['source']);
    }

    public function testRemoveIsIdempotentAndReturnsToCommunity(): void
    {
        self::assertSame(200, $this->import($this->signedLicense($this->payload()), 'portal-remove-import-1')['status']);

        $first = $this->remove('portal-remove-1');
        self::assertSame(200, $first['status']);
        self::assertTrue($first['json']['data']['removed']);
        self::assertSame('none', $first['json']['data']['source']);
        self::assertFalse($first['json']['data']['stored']);
        self::assertSame('community', $first['json']['data']['status']['edition']);
        self::assertFileDoesNotExist($this->storeFile);

        $second = $this->remove('portal-remove-2');
        self::assertSame(200, $second['status']);
        self::assertFalse($second['json']['data']['removed']);
        self::assertSame('community', $second['json']['data']['status']['edition']);
    }

    public function testEnvManagedLicenseIsNeverOverwrittenOrDeleted(): void
    {
        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . 'env-license.json';
        $envLicense = $this->signedLicense($this->payload(['extensions' => ['siro/alpha']]));
        self::assertNotFalse(file_put_contents($envFile, $envLicense));
        $this->setEnv('EXTENSION_LICENSE_FILE', $envFile);

        $imported = $this->import($this->signedLicense($this->payload()), 'portal-env-import-1');
        self::assertSame(200, $imported['status']);
        self::assertFalse($imported['json']['data']['stored']);
        self::assertSame('env', $imported['json']['data']['source']);
        self::assertFileDoesNotExist($this->storeFile);
        self::assertSame($envLicense, (string) file_get_contents($envFile));

        $remove = $this->remove('portal-env-remove-1');
        self::assertSame(409, $remove['status']);
        self::assertSame($envLicense, (string) file_get_contents($envFile));

        $status = $this->call('GET', '/api/admin/extensions/license', [], $this->headers('admin'));
        self::assertSame('env', $status['json']['data']['source']);
        self::assertSame('pro', $status['json']['data']['edition']);
    }

    public function testPortalRequiresOwnerOrAdminAuthentication(): void
    {
        self::assertSame(401, $this->call('GET', '/api/admin/extensions/license')['status']);
        self::assertSame(401, $this->call('POST', '/api/admin/extensions/license/import', ['license' => 'x'])['status']);
        self::assertSame(401, $this->call('DELETE', '/api/admin/extensions/license')['status']);
        self::assertSame(401, $this->call('GET', '/api/admin/ecosystem/pro-feed')['status']);

        $viewer = $this->headers('viewer');
        $deniedImport = $this->call(
            'POST',
            '/api/admin/extensions/license/import',
            ['license' => $this->signedLicense($this->payload())],
            $viewer + ['Idempotency-Key' => 'portal-viewer-import-1']
        );
        self::assertSame(403, $deniedImport['status']);
        self::assertSame(403, $this->call('DELETE', '/api/admin/extensions/license', [], $viewer + ['Idempotency-Key' => 'portal-viewer-remove-1'])['status']);
        self::assertSame(403, $this->call('GET', '/api/admin/ecosystem/pro-feed', [], $viewer)['status']);
        self::assertFileDoesNotExist($this->storeFile);
    }

    /**
     * @param array<string, mixed>|string $license
     * @return array{status: int, json: array<string, mixed>}
     */
    private function import(array|string $license, string $idempotencyKey): array
    {
        return $this->call(
            'POST',
            '/api/admin/extensions/license/import',
            ['license' => $license],
            $this->headers('admin') + ['Idempotency-Key' => $idempotencyKey]
        );
    }

    /** @return array{status: int, json: array<string, mixed>} */
    private function remove(string $idempotencyKey): array
    {
        return $this->call(
            'DELETE',
            '/api/admin/extensions/license',
            [],
            $this->headers('admin') + ['Idempotency-Key' => $idempotencyKey]
        );
    }

    /** @return array{status: int, json: array<string, mixed>} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        $decoded = json_decode($output, true);

        return ['status' => $response->statusCode(), 'json' => is_array($decoded) ? $decoded : []];
    }

    /** @return array<string, string> */
    private function headers(string $role): array
    {
        $email = 'portal-' . $role . '-' . bin2hex(random_bytes(4)) . '@shop.test';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');

        return [
            'Authorization' => 'Bearer ' . $tokens->access,
            'X-Store-Id' => 'default',
            'X-Channel-Id' => 'default',
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $now = time();
        $payload = [
            'license_id' => 'lic_portal',
            'customer_id' => 'cust_portal',
            'edition' => 'pro',
            'extensions' => ['siro/affiliate'],
            'features' => ['affiliate'],
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
