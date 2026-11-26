<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\LicensePortal\Extension as LicensePortalExtension;
use App\Extensions\Siro\LicensePortal\LicensePortalService;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Application\ExtensionQualityGate;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Models\Order;
use App\Models\OrderLine;
use App\Repositories\UserRepository;
use App\Support\CommerceScope;
use App\Support\Ids;
use App\Support\Mailer;
use Siro\Core\Database;
use Siro\Core\Event;
use Siro\Core\Mail\MailProvider;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ExtensionLicensePortalExtensionTest extends TestCase
{
    private const ENV_KEYS = [
        'EXTENSION_LICENSE_PUBLIC_KEY',
        'EXTENSION_LICENSE_PUBLIC_KEY_FILE',
        'EXTENSION_LICENSE_FILE',
        'EXTENSION_LICENSE_STORE_FILE',
        'LICENSE_PORTAL_SKU_MAP',
    ];

    private Router $router;

    /** @var array<string, string> */
    private array $originalEnv = [];

    private string $tempDir;

    /** @var \OpenSSLAsymmetricKey */
    private mixed $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(LicensePortalExtension::class);
        ExtensionRegistry::reset();
        LicensePortalService::listen(false);

        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $_ENV)) {
                $env = $_ENV[$key];
                $this->originalEnv[$key] = is_scalar($env) ? (string) $env : '';
            }
            $this->setEnv($key, null);
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-license-portal-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, 'Unable to generate RSA key pair for license portal tests');
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
        LicensePortalService::listen(false);
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

    private function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    private function lifecycle(): ExtensionLifecycleService
    {
        return new ExtensionLifecycleService(ExtensionDiscovery::classes($this->basePath()));
    }

    private function manifest(): ExtensionManifest
    {
        return ExtensionManifest::fromArray((new LicensePortalExtension())->manifest());
    }

    private function enablePortal(): void
    {
        $service = $this->lifecycle();
        $service->discover();
        $service->install($this->manifest());
        $service->enable(LicensePortalExtension::id());
        $this->remountRoutes();
    }

    private function remountRoutes(): void
    {
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require $this->basePath() . '/routes/api.php';
    }

    /** @return array{status: int, json: mixed, raw: string, headers: array<string, string>} */
    private function call(string $method, string $path, array $body = [], array $headers = [], array $query = []): array
    {
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $exception) {
            $response = $exception->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        return [
            'status' => $response->statusCode(),
            'json' => json_decode($output, true),
            'raw' => $output,
            'headers' => $response->headers(),
        ];
    }

    /** @return array{access: string, role: string} */
    private function token(string $role = 'viewer'): array
    {
        $email = 'lp-' . $role . '-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');

        return ['access' => $tokens->access, 'role' => $role];
    }

    /** @return array<string, string> */
    private function headers(?string $access = null, string $store = 'default', string $channel = 'web', ?string $key = null): array
    {
        $headers = [
            'X-Store-Id' => $store,
            'X-Channel-Id' => $channel,
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ];
        if ($access !== null && $access !== '') {
            $headers['Authorization'] = 'Bearer ' . $access;
        }
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $now = time();
        $payload = [
            'license_id' => 'lic_portal_' . bin2hex(random_bytes(4)),
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

    /** @return array<string, mixed> */
    private function assignBody(string $license, string $customerId = 'cust_portal', ?string $orderId = null, string $sku = 'siro-pro-annual'): array
    {
        return array_filter([
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'sku' => $sku,
            'license' => $license,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $json */
    private function deliveryId(array $json): string
    {
        $id = $json['data']['delivery']['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    public function testManifestDiscoveryAndQualityGate(): void
    {
        self::assertContains(LicensePortalExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new LicensePortalExtension())->manifest();
        self::assertSame('siro/license-portal', $manifest['id']);
        self::assertSame(['license-portal:manage'], $manifest['permissions']);
        self::assertSame('admin/overview', $manifest['admin']['panels'][0]['endpoint']);
        self::assertSame('migrations/20261126000001_create_ext_license_portal_tables.php', $manifest['migrations'][0]);

        $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-license-portal-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            $source = $this->basePath() . '/app/Extensions/Siro/LicensePortal';
            $packed = (new ExtensionPackageInstaller($this->basePath()))->pack($source, $output);
            self::assertTrue($packed['ok']);
            $report = (new ExtensionQualityGate($this->basePath()))->run($output);
            self::assertTrue($report['ok'], (string) json_encode($report));
            $names = array_map(static fn (array $check): string => (string) $check['name'], $report['checks']);
            foreach (['package', 'signature', 'store-scope', 'route-auth', 'secret-scan', 'php-lint'] as $name) {
                self::assertContains($name, $names);
            }
        } finally {
            @unlink($output);
        }
    }

    public function testInstallEnableAndAdminAuthCapability(): void
    {
        $this->enablePortal();
        $service = $this->lifecycle();
        $manifest = $this->manifest();
        self::assertSame('enabled', (string) (\App\Models\Extension::find($manifest->id)?->state));
        unset($service);

        $anonymous = $this->call('GET', '/api/ext/siro/license-portal/admin/deliveries', [], $this->headers());
        self::assertSame(401, $anonymous['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/license-portal/admin/deliveries', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/license-portal/admin/deliveries', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);

        $withoutKey = $this->call('POST', '/api/ext/siro/license-portal/admin/deliveries', $this->assignBody($this->signedLicense($this->payload())), $this->headers($admin['access']));
        self::assertSame(422, $withoutKey['status']);
    }

    public function testAdminAssignListReplayInvalidLicenseAndScopeIsolation(): void
    {
        $this->enablePortal();
        $admin = $this->token('admin');
        $license = $this->signedLicense($this->payload());
        $key = 'lp-assign-' . bin2hex(random_bytes(6));

        $created = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries',
            $this->assignBody($license, 'cust_portal', 'SC-LP-1', 'siro-pro-annual'),
            $this->headers($admin['access'], 'default', 'web', $key)
        );
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        self::assertIsArray($created['json']);
        self::assertSame('issued', $created['json']['data']['delivery']['state']);
        self::assertTrue($created['json']['data']['delivery']['has_license']);
        self::assertIsString($created['json']['data']['token']);
        self::assertStringContainsString('/api/ext/siro/license-portal/download/', (string) $created['json']['data']['download_url']);
        self::assertArrayNotHasKey('license_document', $created['json']['data']['delivery']);
        self::assertArrayNotHasKey('token_hash', $created['json']['data']['delivery']);
        self::assertArrayNotHasKey('idempotency_key', $created['json']['data']['delivery']);
        $encoded = (string) json_encode($created['json']);
        self::assertStringNotContainsString('BEGIN PUBLIC KEY', $encoded);
        self::assertStringNotContainsString($this->tempDir, $encoded);
        self::assertStringNotContainsString('signature', strtolower($encoded));

        $firstId = $this->deliveryId($created['json']);
        $replay = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries',
            $this->assignBody($license, 'cust_portal', 'SC-LP-1', 'siro-pro-annual'),
            $this->headers($admin['access'], 'default', 'web', $key)
        );
        self::assertSame(200, $replay['status']);
        self::assertTrue($replay['json']['data']['replayed']);
        self::assertSame($firstId, $this->deliveryId($replay['json']));
        self::assertNull($replay['json']['data']['token']);

        $list = $this->call('GET', '/api/ext/siro/license-portal/admin/deliveries', [], $this->headers($admin['access']));
        self::assertSame(200, $list['status']);
        self::assertCount(1, $list['json']['data']);
        self::assertSame($firstId, $list['json']['data'][0]['id']);

        $otherScope = $this->call('GET', '/api/ext/siro/license-portal/admin/deliveries', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherScope['status']);
        self::assertCount(0, $otherScope['json']['data']);

        $document = json_decode($license, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $document['payload']['extensions'] = ['siro/evil'];
        $tampered = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $invalid = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries',
            $this->assignBody($tampered, 'cust_bad'),
            $this->headers($admin['access'], 'default', 'web', 'lp-invalid-' . bin2hex(random_bytes(6)))
        );
        self::assertSame(422, $invalid['status']);
        self::assertStringNotContainsString('signature', strtolower((string) json_encode($invalid['json'])));

        $afterInvalid = $this->call('GET', '/api/ext/siro/license-portal/admin/deliveries', [], $this->headers($admin['access']));
        self::assertCount(1, $afterInvalid['json']['data']);

        $crossStoreAssign = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries',
            $this->assignBody($license, 'cust_portal', 'SC-LP-1', 'siro-pro-annual'),
            $this->headers($admin['access'], 'store-b', 'web', 'lp-cross-' . bin2hex(random_bytes(6)))
        );
        self::assertSame(201, $crossStoreAssign['status']);
        $otherList = $this->call('GET', '/api/ext/siro/license-portal/admin/deliveries', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertCount(1, $otherList['json']['data']);
        $defaultList = $this->call('GET', '/api/ext/siro/license-portal/admin/deliveries', [], $this->headers($admin['access']));
        self::assertCount(1, $defaultList['json']['data']);
    }

    public function testRevokeRotateAndDownloadLifecycle(): void
    {
        $this->enablePortal();
        $admin = $this->token('admin');
        $license = $this->signedLicense($this->payload());
        $created = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries',
            $this->assignBody($license, 'cust_portal', 'SC-LP-2', 'siro-pro-annual'),
            $this->headers($admin['access'], 'default', 'web', 'lp-dl-' . bin2hex(random_bytes(6)))
        );
        self::assertSame(201, $created['status']);
        $id = $this->deliveryId($created['json']);
        $token = $created['json']['data']['token'];
        self::assertIsString($token);

        $download = $this->call('GET', '/api/ext/siro/license-portal/download/' . $token);
        self::assertSame(200, $download['status']);
        self::assertSame($license, $download['raw']);
        self::assertStringContainsString('attachment;', (string) ($download['headers']['Content-Disposition'] ?? ''));
        self::assertSame('no-store', $download['headers']['Cache-Control'] ?? null);
        $decoded = json_decode($download['raw'], true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('payload', $decoded);
        self::assertArrayHasKey('signature', $decoded);

        $second = $this->call('GET', '/api/ext/siro/license-portal/download/' . $token);
        self::assertSame(410, $second['status']);

        $rotate = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries/' . $id . '/rotate',
            ['token_ttl_hours' => 2],
            $this->headers($admin['access'], 'default', 'web', 'lp-rotate-' . bin2hex(random_bytes(6)))
        );
        self::assertSame(200, $rotate['status']);
        $rotatedToken = $rotate['json']['data']['token'];
        self::assertIsString($rotatedToken);
        self::assertNotSame($token, $rotatedToken);
        self::assertSame(404, $this->call('GET', '/api/ext/siro/license-portal/download/' . $token)['status']);

        $firstRotated = $this->call('GET', '/api/ext/siro/license-portal/download/' . $rotatedToken);
        self::assertSame(200, $firstRotated['status']);
        self::assertSame(410, $this->call('GET', '/api/ext/siro/license-portal/download/' . $rotatedToken)['status']);

        $rotateAgain = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries/' . $id . '/rotate',
            [],
            $this->headers($admin['access'], 'default', 'web', 'lp-rotate2-' . bin2hex(random_bytes(6)))
        );
        self::assertSame(200, $rotateAgain['status']);
        $expiringToken = $rotateAgain['json']['data']['token'];
        self::assertIsString($expiringToken);
        $stmt = Database::connection()->prepare('UPDATE ext_license_portal_deliveries SET token_expires_at = ? WHERE id = ?');
        self::assertInstanceOf(\PDOStatement::class, $stmt);
        $stmt->execute([date('Y-m-d H:i:s', time() - 60), $id]);
        self::assertSame(410, $this->call('GET', '/api/ext/siro/license-portal/download/' . $expiringToken)['status']);

        $revoked = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries/' . $id . '/revoke',
            [],
            $this->headers($admin['access'], 'default', 'web', 'lp-revoke-' . bin2hex(random_bytes(6)))
        );
        self::assertSame(200, $revoked['status']);
        self::assertSame('revoked', $revoked['json']['data']['state']);
        $revokedAgain = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries/' . $id . '/revoke',
            [],
            $this->headers($admin['access'], 'default', 'web', 'lp-revoke2-' . bin2hex(random_bytes(6)))
        );
        self::assertSame(200, $revokedAgain['status']);
        self::assertSame($revoked['json']['data']['revoked_at'], $revokedAgain['json']['data']['revoked_at']);
        self::assertSame(410, $this->call('GET', '/api/ext/siro/license-portal/download/' . $expiringToken)['status']);
    }

    public function testOrderPaidHookCreatesPendingDeliveriesIdempotentlyAndNoopsWithoutMapping(): void
    {
        $this->enablePortal();
        $orderId = 'SC-LP-ORDER-' . bin2hex(random_bytes(3));
        Order::create([
            'id' => $orderId,
            'number' => 'LP-' . bin2hex(random_bytes(4)),
            'customer_id' => 'cust_hook',
            'guest_email' => 'hook@example.com',
            'currency' => 'VND',
            'store_id' => 'default',
            'channel_id' => 'web',
            'subtotal_minor' => 100000,
            'grand_total_minor' => 100000,
            'state' => 'paid',
            'idempotency_key' => 'order-key-' . bin2hex(random_bytes(6)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        OrderLine::create([
            'id' => Ids::new(),
            'order_id' => $orderId,
            'variant_id' => 'var-license',
            'sku' => 'siro-pro-license',
            'name' => 'Siro Pro license',
            'qty' => 1,
            'unit_price_minor' => 100000,
        ]);

        Event::emit('order.paid', ['order_id' => $orderId, 'customer_id' => 'cust_hook']);
        Event::emit('order.paid', ['order_id' => $orderId, 'customer_id' => 'cust_hook']);

        $service = new LicensePortalService();
        $scope = new CommerceScope('default', 'web', 'en', 'VND');
        $rows = $service->list($scope, 'all', null, $orderId);
        self::assertCount(1, $rows);
        self::assertSame('pending', $rows[0]['state']);
        self::assertSame('siro-pro', $rows[0]['sku']);
        self::assertSame('siro-pro-license', $rows[0]['source_sku']);
        self::assertFalse($rows[0]['has_license']);
        self::assertFalse($rows[0]['has_active_token']);

        Event::emit('order.transitioned', ['order_id' => $orderId, 'to' => 'paid']);
        self::assertCount(1, $service->list($scope, 'all', null, $orderId));

        $unmappedId = 'SC-LP-UNMAPPED-' . bin2hex(random_bytes(3));
        Order::create([
            'id' => $unmappedId,
            'number' => 'LPU-' . bin2hex(random_bytes(4)),
            'currency' => 'VND',
            'store_id' => 'default',
            'channel_id' => 'web',
            'subtotal_minor' => 1000,
            'grand_total_minor' => 1000,
            'state' => 'paid',
            'idempotency_key' => 'order-key-' . bin2hex(random_bytes(6)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        OrderLine::create([
            'id' => Ids::new(),
            'order_id' => $unmappedId,
            'variant_id' => 'var-normal',
            'sku' => 'NORMAL-SKU',
            'name' => 'Normal product',
            'qty' => 1,
            'unit_price_minor' => 1000,
        ]);
        Event::emit('order.paid', ['order_id' => $unmappedId]);
        self::assertCount(0, $service->list($scope, 'all', null, $unmappedId));

        $otherScope = new CommerceScope('store-b', 'web', 'en', 'VND');
        self::assertCount(0, $service->list($otherScope, 'all', null, $orderId));
    }

    public function testAssignCanEmailTheOneTimeLinkWithoutLeakingTheLicense(): void
    {
        $this->enablePortal();
        $admin = $this->token('admin');
        $provider = new class implements MailProvider {
            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public function send(array $mail): bool
            {
                $this->sent[] = $mail;

                return true;
            }
        };
        Mailer::useProvider($provider);

        $body = $this->assignBody($this->signedLicense($this->payload()), 'cust_email');
        $body['email'] = 'buyer@example.com';
        $body['send_email'] = true;
        $created = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries',
            $body,
            $this->headers($admin['access'], 'default', 'web', 'lp-email-' . bin2hex(random_bytes(6)))
        );
        self::assertSame(201, $created['status']);
        self::assertTrue($created['json']['data']['email_sent']);
        $token = $created['json']['data']['token'];
        self::assertIsString($token);

        self::assertCount(1, $provider->sent);
        $mail = $provider->sent[0];
        self::assertSame('buyer@example.com', $mail['to'] ?? null);
        $mailBody = (string) ($mail['body'] ?? '');
        self::assertStringContainsString('/api/ext/siro/license-portal/download/' . $token, $mailBody);
        self::assertStringNotContainsString('signature', strtolower($mailBody));
        self::assertStringNotContainsString($this->tempDir, $mailBody);
    }

    public function testUninstallKeepsOrRemovesScopedData(): void
    {
        $this->enablePortal();
        $admin = $this->token('admin');
        $created = $this->call(
            'POST',
            '/api/ext/siro/license-portal/admin/deliveries',
            $this->assignBody($this->signedLicense($this->payload()), 'cust_keep'),
            $this->headers($admin['access'], 'default', 'web', 'lp-keep-' . bin2hex(random_bytes(6)))
        );
        self::assertSame(201, $created['status']);

        $service = $this->lifecycle();
        $service->uninstall(LicensePortalExtension::id(), false);
        $this->remountRoutes();
        self::assertSame(404, $this->call('GET', '/api/ext/siro/license-portal/admin/deliveries', [], $this->headers($admin['access']))['status']);
        $count = Database::connection()->query('SELECT COUNT(*) FROM ext_license_portal_deliveries')->fetchColumn();
        self::assertSame(1, (int) $count);

        $service = $this->lifecycle();
        $service->discover();
        $service->install($this->manifest());
        $service->enable(LicensePortalExtension::id());
        $this->remountRoutes();
        self::assertSame(1, (int) Database::connection()->query('SELECT COUNT(*) FROM ext_license_portal_deliveries')->fetchColumn());

        $service = $this->lifecycle();
        $service->uninstall(LicensePortalExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_license_portal_deliveries'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
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
