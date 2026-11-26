<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Application\FeatureFlagService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Pro edition scaffolding: environment-driven flags and the read-only admin
 * endpoint. Community never unlocks Pro flags, and the endpoint must not leak
 * raw allowlist configuration or secrets.
 */
final class FeatureFlagTest extends TestCase
{
    private Router $router;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['PRO_EDITION', 'PRO_FEATURES'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }

        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            $this->setEnv($key, $value);
        }

        parent::tearDown();
    }

    public function testCommunityEditionDisablesAllFlagsByDefault(): void
    {
        $this->setEnv('PRO_EDITION', null);
        $this->setEnv('PRO_FEATURES', null);

        $service = new FeatureFlagService();
        $state = $service->all();

        self::assertSame('community', $state['edition']);
        self::assertSame(FeatureFlagService::FLAGS, array_keys($state['flags']));
        foreach ($state['flags'] as $flag => $enabled) {
            self::assertFalse($enabled, $flag . ' must stay disabled in community');
        }
    }

    public function testCommunityIgnoresAnAllowlistAndUnknownEditionFailsClosed(): void
    {
        $this->setEnv('PRO_EDITION', 'community');
        $this->setEnv('PRO_FEATURES', 'marketplace,sso,white_label');

        $community = new FeatureFlagService();
        self::assertFalse($community->enabled('marketplace'));
        self::assertFalse($community->enabled('sso'));

        $unknown = new FeatureFlagService('ultra', 'marketplace');
        self::assertSame('community', $unknown->edition());
        self::assertFalse($unknown->enabled('marketplace'));
    }

    public function testProEditionEnablesOnlyAllowlistedKnownFlags(): void
    {
        $this->setEnv('PRO_EDITION', 'pro');
        $this->setEnv('PRO_FEATURES', ' marketplace , SSO, unknown_flag ');

        $service = new FeatureFlagService();
        $state = $service->all();

        self::assertSame('pro', $state['edition']);
        self::assertTrue($state['flags']['marketplace']);
        self::assertTrue($state['flags']['sso']);
        self::assertFalse($state['flags']['multi_store_theming']);
        self::assertFalse($state['flags']['enterprise_audit']);
        self::assertFalse($state['flags']['ai_managed']);
        self::assertFalse($state['flags']['white_label']);

        self::assertTrue($service->enabled('marketplace'));
        self::assertFalse($service->enabled('unknown_flag'));
        self::assertFalse($service->enabled(''));
    }

    public function testEnterpriseEditionStillHonoursTheAllowlist(): void
    {
        $this->setEnv('PRO_EDITION', 'enterprise');
        $this->setEnv('PRO_FEATURES', 'white_label,enterprise_audit');

        $service = new FeatureFlagService();

        self::assertSame('enterprise', $service->edition());
        self::assertTrue($service->enabled('white_label'));
        self::assertTrue($service->enabled('enterprise_audit'));
        self::assertFalse($service->enabled('marketplace'));
    }

    public function testFeatureEndpointRequiresAdminOrOwnerAndReturnsSafePayload(): void
    {
        $this->setEnv('PRO_EDITION', 'pro');
        $this->setEnv('PRO_FEATURES', 'marketplace,sso');

        $anon = $this->call('GET', '/api/admin/features');
        self::assertSame(401, $anon['status']);

        $viewer = $this->loginAs('viewer-features@shop.test', 'viewer');
        $forbidden = $this->call('GET', '/api/admin/features', [], $viewer);
        self::assertSame(403, $forbidden['status']);

        foreach (['admin' => 'admin-features@shop.test', 'owner' => 'owner-features@shop.test'] as $role => $email) {
            $user = $this->loginAs($email, $role);
            $response = $this->call('GET', '/api/admin/features', [], $user);

            self::assertSame(200, $response['status']);
            self::assertTrue($response['json']['success']);
            self::assertSame('pro', $response['json']['data']['edition']);
            self::assertSame(FeatureFlagService::FLAGS, array_keys($response['json']['data']['flags']));
            self::assertTrue($response['json']['data']['flags']['marketplace']);
            self::assertTrue($response['json']['data']['flags']['sso']);
            self::assertFalse($response['json']['data']['flags']['white_label']);
            self::assertArrayNotHasKey('allowlist', $response['json']['data']);
        }

        $body = (string) json_encode($anon['json']) . (string) json_encode($forbidden['json']);
        self::assertStringNotContainsString('PRO_FEATURES', $body);
        self::assertStringNotContainsString('test-secret-for-sirocommerce-phase2-tests-32c', $body);
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed} */
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

        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    /** @return array<string, string> */
    private function loginAs(string $email, string $role): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');

        return [
            'Authorization' => 'Bearer ' . $tokens->access,
            'X-Store-Id' => 'default',
            'X-Channel-Id' => 'default',
            'X-Locale' => 'en',
            'X-Currency' => 'USD',
        ];
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
