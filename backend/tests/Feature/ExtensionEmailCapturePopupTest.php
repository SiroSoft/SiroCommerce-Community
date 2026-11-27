<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\EmailCapturePopup\EmailCaptureService;
use App\Extensions\Siro\EmailCapturePopup\Extension as EmailCaptureExtension;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Coupon\Application\CouponService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionRouteLoader;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Mail\Application\MailTemplateRegistry;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use App\Support\Mailer;
use Siro\Core\Database;
use Siro\Core\Mail\MailProvider;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * RULE-E10 test matrix for siro/email-capture-popup (PLAN §5) +
 * EXTENSION_SCOPING.md §6 scope tests.
 */
final class ExtensionEmailCapturePopupTest extends TestCase
{
    private Router $router;

    /** @var list<Router> Keep retired routers alive so spl_object_id is not reused by the loader. */
    private array $retiredRouters = [];

    /** @var list<array<string, mixed>> Captured outbound mail. */
    private array $sentMail = [];

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(EmailCaptureExtension::class);
        ExtensionRegistry::reset();
        MailTemplateRegistry::reset();
        $this->resetMountedRoutes();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        $sent = &$this->sentMail;
        Mailer::useProvider(new class($sent) implements MailProvider {
            /** @var list<array<string, mixed>> */
            public array $sent;
            /** @param list<array<string, mixed>> $sent */
            public function __construct(array &$sent) { $this->sent = &$sent; }
            /** @param array<string, mixed> $mail */
            public function send(array $mail): bool { $this->sent[] = $mail; return true; }
        });
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        MailTemplateRegistry::reset();
        Mailer::reset();
        $this->resetMountedRoutes();
        parent::tearDown();
    }

    private function resetMountedRoutes(): void
    {
        $property = (new \ReflectionClass(ExtensionRouteLoader::class))->getProperty('mounted');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @return array{status: int, json: mixed}
     */
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
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    private function service(): ExtensionLifecycleService
    {
        return new ExtensionLifecycleService(ExtensionDiscovery::classes($this->basePath()));
    }

    /** @return array{access: string, role: string} */
    private function token(string $role = 'viewer'): array
    {
        $email = 'popup-' . $role . '-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'popup', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['access' => $tokens->access, 'role' => $role];
    }

    /** @return array<string, string> */
    private function headers(string $access, string $store = 'default', string $channel = 'web', ?string $idempotencyKey = null): array
    {
        return [
            'Authorization' => 'Bearer ' . $access,
            'X-Store-Id' => $store,
            'X-Channel-Id' => $channel,
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
            'Idempotency-Key' => $idempotencyKey ?? ('popup-' . bin2hex(random_bytes(6))),
        ];
    }

    /** @return array<string, string> */
    private function publicHeaders(string $store = 'default', string $channel = 'web', ?string $idempotencyKey = null, ?string $sessionId = null): array
    {
        $headers = [
            'X-Store-Id' => $store,
            'X-Channel-Id' => $channel,
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
            'Idempotency-Key' => $idempotencyKey ?? ('popup-pub-' . bin2hex(random_bytes(6))),
        ];
        if ($sessionId !== null) {
            $headers['X-Session-Id'] = $sessionId;
        }
        return $headers;
    }

    private function enablePopup(): void
    {
        $service = $this->service();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new EmailCaptureExtension())->manifest()));
        $service->enable(EmailCaptureExtension::id());
        $this->remountRoutes();
    }

    private function remountRoutes(): void
    {
        $this->retiredRouters[] = $this->router;
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require $this->basePath() . '/routes/api.php';
    }

    private function subscribe(string $email, ?string $sessionId = null, string $store = 'default', string $channel = 'web'): array
    {
        $body = ['email' => $email, 'source' => 'popup'];
        if ($sessionId !== null) {
            $body['session_id'] = $sessionId;
        }
        return $this->call('POST', '/api/ext/siro/email-capture-popup/subscribe', $body, $this->publicHeaders($store, $channel, null, $sessionId));
    }

    private function optInSession(string $sessionId): void
    {
        $res = $this->call('POST', '/api/consent/marketing', ['marketing' => true], ['X-Session-Id' => $sessionId, 'Idempotency-Key' => 'consent-' . bin2hex(random_bytes(6))]);
        self::assertSame(200, $res['status'], (string) json_encode($res['json']));
    }

    private function subscriberCount(string $store = 'default', string $channel = 'web'): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM ext_email_capture_subscribers WHERE store_id = ? AND channel_id = ?');
        self::assertInstanceOf(\PDOStatement::class, $stmt);
        $stmt->execute([$store, $channel]);
        return (int) $stmt->fetchColumn();
    }

    // RULE-E10 row 1: manifest valid; bad id/version/requires rejected.
    public function testManifestAndMigrationDeclareScopedTable(): void
    {
        self::assertContains(EmailCaptureExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new EmailCaptureExtension())->manifest();
        self::assertSame('siro/email-capture-popup', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['email-capture:read', 'email-capture:manage', 'coupon:create'], $manifest['capabilities']);
        self::assertSame(['email-capture:manage', 'coupon:create'], $manifest['permissions']);
        self::assertSame('migrations/20261126000021_create_ext_email_capture_subscribers.php', $manifest['migrations'][0]);
        self::assertSame(['footer'], $manifest['slots']['storefront']);
        self::assertSame('admin/stats', $manifest['admin']['panels'][0]['endpoint']);
        self::assertSame('admin/subscribers', $manifest['admin']['panels'][1]['endpoint']);

        $raw = file_get_contents($this->basePath() . '/app/Extensions/Siro/EmailCapturePopup/manifest.json');
        self::assertIsString($raw);
        $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertSame('siro.extension/2', $package['schema'] ?? null);
        self::assertSame('siro/email-capture-popup', $package['id'] ?? null);
        self::assertSame('module', $package['type'] ?? null);
        self::assertSame(['footer'], $package['slots']['storefront'] ?? null);

        $this->enablePopup();
        $statement = Database::connection()->query(
            'SELECT id, store_id, channel_id, email, customer_id, source, coupon_code, consented_at, created_at, updated_at FROM ext_email_capture_subscribers LIMIT 0'
        );
        self::assertNotFalse($statement);
        self::assertSame(10, $statement->columnCount());
        // boot() registers the welcome-mail binding (PLAN §2.4).
        self::assertSame('welcome', MailTemplateRegistry::templateFor(EmailCaptureService::EVENT_SUBSCRIBED));
    }

    // RULE-E10 row 8: version mismatch blocks install/enable with a message.
    public function testVersionMismatchBlocksInstall(): void
    {
        $bad = (new EmailCaptureExtension())->manifest();
        $bad['requires'] = ['core' => '^99.0', 'commerce' => '^0.1'];
        $manifest = ExtensionManifest::fromArray($bad);
        $errors = $this->service()->validate($manifest);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('^99.0', implode('; ', $errors));
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->install($manifest);
    }

    // RULE-E10 row 4: routes under /api/ext/<id>, 401 without auth, 403 without capability.
    public function testAdminRoutesRequireAuthAndManageCapability(): void
    {
        $this->enablePopup();
        $noAuth = $this->call('GET', '/api/ext/siro/email-capture-popup/admin/stats', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web']);
        self::assertSame(401, $noAuth['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/email-capture-popup/admin/stats', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/email-capture-popup/admin/stats', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);
        self::assertIsArray($allowed['json']);
        self::assertSame(0, $allowed['json']['data']['total'] ?? null);
    }

    public function testPublicConfigIsUnauthenticatedAndScoped(): void
    {
        $this->enablePopup();
        $res = $this->call('GET', '/api/ext/siro/email-capture-popup/public', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(200, $res['status'], (string) json_encode($res['json']));
        self::assertTrue((bool) ($res['json']['data']['active'] ?? false));
        self::assertSame(8000, $res['json']['data']['delay_ms'] ?? null);
        self::assertSame(10, $res['json']['data']['coupon_percent'] ?? null);
    }

    // Consent gate (§2.5): no opt-in → row stored, consented_at null, NO mail.
    public function testSubscribeWithoutConsentStoresRowButSendsNoMail(): void
    {
        $this->enablePopup();
        $email = 'noconsent-' . bin2hex(random_bytes(4)) . '@test.local';
        $res = $this->subscribe($email);
        self::assertSame(201, $res['status'], (string) json_encode($res['json']));
        self::assertSame($email, $res['json']['data']['email'] ?? null);
        self::assertSame('', $res['json']['data']['consented_at'] ?? null);
        self::assertSame('', $res['json']['data']['coupon_code'] ?? null);
        self::assertFalse((bool) ($res['json']['data']['mail_sent'] ?? true));
        self::assertSame([], $this->sentMail);
        self::assertSame(1, $this->subscriberCount());
    }

    // Consent gate (§2.5) + mail (§2.4) + coupon (§2.6): session opt-in → mail + coupon.
    public function testSubscribeWithSessionConsentSendsWelcomeMailAndCoupon(): void
    {
        $this->enablePopup();
        $session = 'popup-sess-' . bin2hex(random_bytes(6));
        $this->optInSession($session);
        $email = 'consented-' . bin2hex(random_bytes(4)) . '@test.local';
        $res = $this->subscribe($email, $session);
        self::assertSame(201, $res['status'], (string) json_encode($res['json']));
        self::assertNotSame('', $res['json']['data']['consented_at'] ?? '');
        $coupon = (string) ($res['json']['data']['coupon_code'] ?? '');
        self::assertNotSame('', $coupon);
        self::assertTrue((bool) ($res['json']['data']['mail_sent'] ?? false));
        self::assertCount(1, $this->sentMail);
        self::assertSame($email, $this->sentMail[0]['to'] ?? null);
        self::assertSame('Welcome to SiroCommerce', $this->sentMail[0]['subject'] ?? null);
        self::assertStringContainsString($coupon, (string) ($this->sentMail[0]['body'] ?? ''));
        $found = null;
        $coupons = new CouponService(new \App\Modules\Platform\Infrastructure\CouponStoreAdapter(new \App\Repositories\CouponRepository()));
        foreach ($coupons->all() as $row) {
            if (($row['code'] ?? null) === $coupon) $found = $row;
        }
        self::assertNotNull($found);
    }

    // Consent gate for a logged-in customer (preference source).
    public function testSubscribeWithCustomerConsentSendsMail(): void
    {
        $this->enablePopup();
        $email = 'customer-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'shopper', 'email' => $email, 'password' => 'secret123', 'role' => 'viewer']);
        $access = $auth->login($email, 'secret123')->access;
        $in = $this->call('POST', '/api/consent/marketing', ['marketing' => true], ['Authorization' => 'Bearer ' . $access, 'Idempotency-Key' => 'consent-cust-' . bin2hex(random_bytes(6))]);
        self::assertSame(200, $in['status'], (string) json_encode($in['json']));
        $res = $this->subscribe($email);
        self::assertSame(201, $res['status'], (string) json_encode($res['json']));
        self::assertTrue((bool) ($res['json']['data']['mail_sent'] ?? false));
        self::assertCount(1, $this->sentMail);
    }

    // RULE-E10 row 5: replay is safe — same email twice, one row, one mail.
    public function testResubscribeIsIdempotentNoSecondMail(): void
    {
        $this->enablePopup();
        $session = 'popup-replay-' . bin2hex(random_bytes(6));
        $this->optInSession($session);
        $email = 'replay-' . bin2hex(random_bytes(4)) . '@test.local';
        $first = $this->subscribe($email, $session);
        self::assertSame(201, $first['status']);
        $second = $this->subscribe($email, $session);
        self::assertSame(200, $second['status']);
        self::assertTrue((bool) ($second['json']['data']['already_subscribed'] ?? false));
        self::assertFalse((bool) ($second['json']['data']['mail_sent'] ?? true));
        self::assertSame($first['json']['data']['id'] ?? null, $second['json']['data']['id'] ?? null);
        self::assertSame(1, $this->subscriberCount());
        self::assertCount(1, $this->sentMail);
    }

    public function testSubscribeValidation(): void
    {
        $this->enablePopup();
        $cases = [
            [['email' => 'not-an-email'], 422],
            [['email' => ''], 422],
            [[], 422],
            [['email' => 'ok-' . bin2hex(random_bytes(4)) . '@test.local', 'source' => 'bad source!'], 422],
            [['email' => str_repeat('a', 250) . '@test.local'], 422],
        ];
        foreach ($cases as [$body, $expected]) {
            $res = $this->call('POST', '/api/ext/siro/email-capture-popup/subscribe', $body, $this->publicHeaders());
            self::assertSame($expected, $res['status'], 'Expected ' . $expected . ' for ' . json_encode($body));
        }
    }

    // RULE-E10 row 5 (loader): mutations require Idempotency-Key.
    public function testMutationRequiresIdempotencyKey(): void
    {
        $this->enablePopup();
        $headers = $this->publicHeaders();
        unset($headers['Idempotency-Key']);
        $res = $this->call('POST', '/api/ext/siro/email-capture-popup/subscribe', ['email' => 'key-' . bin2hex(random_bytes(4)) . '@test.local'], $headers);
        self::assertSame(422, $res['status']);

        $admin = $this->token('admin');
        $adminHeaders = $this->headers($admin['access']);
        unset($adminHeaders['Idempotency-Key']);
        $coupon = $this->call('POST', '/api/ext/siro/email-capture-popup/coupons', ['code' => 'POPUP10'], $adminHeaders);
        self::assertSame(422, $coupon['status']);
    }

    // SCOPING §6: same email is invisible across scopes; list is scoped.
    public function testScopeIsolation(): void
    {
        $this->enablePopup();
        $email = 'scoped-' . bin2hex(random_bytes(4)) . '@test.local';
        $first = $this->subscribe($email, null, 'default', 'web');
        self::assertSame(201, $first['status']);
        $second = $this->subscribe($email, null, 'store-b', 'web');
        self::assertSame(201, $second['status'], 'Same email must work in a second store');
        self::assertNotSame($first['json']['data']['id'] ?? null, $second['json']['data']['id'] ?? null);
        self::assertSame(1, $this->subscriberCount('default', 'web'));
        self::assertSame(1, $this->subscriberCount('store-b', 'web'));

        $admin = $this->token('admin');
        $listA = $this->call('GET', '/api/ext/siro/email-capture-popup/admin/subscribers', [], $this->headers($admin['access'], 'default', 'web'));
        self::assertSame(200, $listA['status']);
        self::assertSame(1, $listA['json']['data']['total'] ?? null);
        $listB = $this->call('GET', '/api/ext/siro/email-capture-popup/admin/subscribers', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $listB['status']);
        self::assertSame(1, $listB['json']['data']['total'] ?? null);
        self::assertNotSame(
            $listA['json']['data']['rows'][0]['id'] ?? null,
            $listB['json']['data']['rows'][0]['id'] ?? null
        );
        $stats = $this->call('GET', '/api/ext/siro/email-capture-popup/admin/stats', [], $this->headers($admin['access'], 'default', 'web'));
        self::assertSame(1, $stats['json']['data']['total'] ?? null);
        self::assertSame(0, $stats['json']['data']['consented'] ?? null);

        // Delete in the wrong scope must not touch the other store's row.
        $otherId = (string) ($listB['json']['data']['rows'][0]['id'] ?? '');
        $wrongScope = $this->call('DELETE', '/api/ext/siro/email-capture-popup/admin/subscribers/' . $otherId, [], $this->headers($admin['access'], 'default', 'web'));
        self::assertSame(404, $wrongScope['status']);
        self::assertSame(1, $this->subscriberCount('store-b', 'web'));
    }

    public function testAdminListStatsAndDelete(): void
    {
        $this->enablePopup();
        $session = 'popup-admin-' . bin2hex(random_bytes(6));
        $this->optInSession($session);
        $email = 'admin-' . bin2hex(random_bytes(4)) . '@test.local';
        $created = $this->subscribe($email, $session);
        self::assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');
        self::assertNotSame('', $id);

        $admin = $this->token('admin');
        $list = $this->call('GET', '/api/ext/siro/email-capture-popup/admin/subscribers', [], $this->headers($admin['access']));
        self::assertSame(200, $list['status']);
        self::assertSame(1, $list['json']['data']['total'] ?? null);
        self::assertSame($email, $list['json']['data']['rows'][0]['email'] ?? null);
        self::assertArrayHasKey('table', $list['json']['data']);
        self::assertSame($email, $list['json']['data']['table']['rows'][0]['email'] ?? null);

        $stats = $this->call('GET', '/api/ext/siro/email-capture-popup/admin/stats', [], $this->headers($admin['access']));
        self::assertSame(1, $stats['json']['data']['total'] ?? null);
        self::assertSame(1, $stats['json']['data']['consented'] ?? null);
        self::assertSame(1, $stats['json']['data']['with_coupon'] ?? null);
        self::assertSame(1, $stats['json']['data']['today'] ?? null);

        $deleted = $this->call('DELETE', '/api/ext/siro/email-capture-popup/admin/subscribers/' . $id, [], $this->headers($admin['access']));
        self::assertSame(200, $deleted['status'], (string) json_encode($deleted['json']));
        self::assertSame(0, $this->subscriberCount());
        $gone = $this->call('DELETE', '/api/ext/siro/email-capture-popup/admin/subscribers/' . $id, [], $this->headers($admin['access']));
        self::assertSame(404, $gone['status']);

        $auditFile = $this->basePath() . '/storage/logs/audit/audit-' . date('Y-m-d') . '.jsonl';
        self::assertFileExists($auditFile);
        $audit = is_file($auditFile) ? (string) file_get_contents($auditFile) : '';
        self::assertStringContainsString('ext.email-capture-popup.subscriber.deleted', $audit);
        self::assertStringContainsString($id, $audit);
    }

    // RULE-E10 row 4 (coupon): 403 without capability, 422 on bad input, 201 on create.
    public function testCouponsRouteRequiresCapabilityAndCreates(): void
    {
        $this->enablePopup();
        $viewer = $this->token('viewer');
        $denied = $this->call('POST', '/api/ext/siro/email-capture-popup/coupons', ['code' => 'POPUP10'], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);

        $admin = $this->token('admin');
        $badCode = $this->call('POST', '/api/ext/siro/email-capture-popup/coupons', ['code' => 'x'], $this->headers($admin['access']));
        self::assertSame(422, $badCode['status']);
        $badRules = $this->call('POST', '/api/ext/siro/email-capture-popup/coupons', ['code' => 'POPUP10', 'rules' => '[1,2]'], $this->headers($admin['access']));
        self::assertSame(422, $badRules['status']);

        $code = 'POPUP-' . strtoupper(bin2hex(random_bytes(3)));
        $created = $this->call('POST', '/api/ext/siro/email-capture-popup/coupons', ['code' => $code, 'rules' => '{"type":"percent","value":10}'], $this->headers($admin['access']));
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        self::assertSame($code, $created['json']['data']['code'] ?? null);

        $auditFile = $this->basePath() . '/storage/logs/audit/audit-' . date('Y-m-d') . '.jsonl';
        $audit = is_file($auditFile) ? (string) file_get_contents($auditFile) : '';
        self::assertStringContainsString('ext.email-capture-popup.coupon.created', $audit);
        self::assertStringContainsString($code, $audit);
    }

    // RULE-E10 rows 2 + 7: disable unplugs routes; uninstall keep-data →
    // reinstall reuses data; upgrade keeps state enabled.
    public function testDisableUninstallKeepDataAndUpgrade(): void
    {
        $this->enablePopup();
        $email = 'keep-' . bin2hex(random_bytes(4)) . '@test.local';
        $created = $this->subscribe($email);
        self::assertSame(201, $created['status']);
        $id = (string) ($created['json']['data']['id'] ?? '');

        $admin = $this->token('admin');
        $ok = $this->call('GET', '/api/ext/siro/email-capture-popup/admin/stats', [], $this->headers($admin['access']));
        self::assertSame(200, $ok['status']);

        $lifecycle = $this->service();
        $lifecycle->disable(EmailCaptureExtension::id());
        $this->remountRoutes();
        $gone = $this->call('GET', '/api/ext/siro/email-capture-popup/admin/stats', [], $this->headers($admin['access']));
        self::assertSame(404, $gone['status']);
        $publicGone = $this->call('GET', '/api/ext/siro/email-capture-popup/public', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(404, $publicGone['status']);
        $health = $this->call('GET', '/health/live');
        self::assertSame(200, $health['status']);

        // Uninstall keep-data: table + row survive; reinstall reuses them.
        $lifecycle->uninstall(EmailCaptureExtension::id(), false);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_email_capture_subscribers'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['ext_email_capture_subscribers'], $tables);
        self::assertSame(1, $this->subscriberCount());

        $lifecycle->install(ExtensionManifest::fromArray((new EmailCaptureExtension())->manifest()));
        $lifecycle->enable(EmailCaptureExtension::id());
        $this->remountRoutes();
        self::assertSame(1, $this->subscriberCount());
        $replay = $this->subscribe($email);
        self::assertSame(200, $replay['status']);
        self::assertSame($id, $replay['json']['data']['id'] ?? null);

        // Upgrade to 1.0.1: state ends enabled.
        $upgraded = (new EmailCaptureExtension())->manifest();
        $upgraded['version'] = '1.0.1';
        $lifecycle->upgrade(ExtensionManifest::fromArray($upgraded));
        $row = \App\Models\Extension::query()->where('id', EmailCaptureExtension::id())->first();
        self::assertNotNull($row);
        $data = $row instanceof \Siro\Core\Model ? $row->toArray() : (array) $row;
        self::assertSame('enabled', $data['state'] ?? null);
        self::assertSame('1.0.1', $data['version'] ?? null);
    }

    // RULE-E10 row 3: uninstall(removeData=true) drops ext_* tables, core intact.
    public function testUninstallRemoveDataDropsTableKeepsCore(): void
    {
        $this->enablePopup();
        $created = $this->subscribe('gone-' . bin2hex(random_bytes(4)) . '@test.local');
        self::assertSame(201, $created['status']);

        $lifecycle = $this->service();
        $lifecycle->disable(EmailCaptureExtension::id());
        $this->remountRoutes();
        $lifecycle->uninstall(EmailCaptureExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_email_capture_subscribers'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
        $orders = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='orders'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['orders'], $orders);
    }
}
