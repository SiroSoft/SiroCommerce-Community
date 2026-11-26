<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class LaunchRoutesTest extends TestCase
{
    private Router $router;
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
        $this->envBackup = [];
        parent::tearDown();
    }

    public function testLaunchStatusAndProviderStatusRequireAdmin(): void
    {
        self::assertSame(401, $this->call('GET', '/api/admin/launch/status')['status']);
        self::assertSame(401, $this->call('GET', '/api/admin/providers/status')['status']);

        $admin = $this->adminHeaders('admin');
        $status = $this->call('GET', '/api/admin/launch/status', [], $admin);
        self::assertSame(200, $status['status']);
        self::assertArrayHasKey('ready', $status['json']['data']);
        self::assertArrayHasKey('score', $status['json']['data']);
        self::assertCount(8, $status['json']['data']['checks']);

        $providers = $this->call('GET', '/api/admin/providers/status', [], $admin);
        self::assertSame(200, $providers['status']);
        foreach (['payment', 'shipping', 'mail', 'search', 'ai'] as $key) {
            self::assertArrayHasKey($key, $providers['json']['data']);
        }
    }

    public function testViewerRoleIsForbidden(): void
    {
        $viewer = $this->adminHeaders('viewer');
        self::assertSame(403, $this->call('GET', '/api/admin/launch/status', [], $viewer)['status']);
    }

    public function testProviderStatusNeverLeaksSecretValues(): void
    {
        $this->setEnv([
            'PAYMENT_DEFAULT_PROVIDER' => 'stripe',
            'PAYMENT_MODE' => 'production',
            'STRIPE_SECRET_KEY' => 'sk_live_super_secret_launch_test',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_super_secret_launch_test',
        ]);

        $response = $this->call('GET', '/api/admin/providers/status', [], $this->adminHeaders('admin'));
        self::assertSame(200, $response['status']);
        $encoded = json_encode($response['json'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sk_live_super_secret_launch_test', $encoded);
        self::assertStringNotContainsString('whsec_super_secret_launch_test', $encoded);
        self::assertSame('stripe', $response['json']['data']['payment']['provider']);
        self::assertTrue($response['json']['data']['payment']['configured']);
    }

    public function testSeedDemoRequiresIdempotencyKeyAndIsIdempotent(): void
    {
        $admin = $this->adminHeaders('admin');

        self::assertSame(422, $this->call('POST', '/api/admin/launch/seed-demo', [], $admin)['status']);

        $first = $this->call('POST', '/api/admin/launch/seed-demo', [], $admin + ['Idempotency-Key' => 'launch-seed-first']);
        self::assertSame(200, $first['status']);
        self::assertSame(6, $first['json']['data']['created']['products']);
        self::assertSame(5, $first['json']['data']['created']['pages']);

        $second = $this->call('POST', '/api/admin/launch/seed-demo', [], $admin + ['Idempotency-Key' => 'launch-seed-second']);
        self::assertSame(200, $second['status']);
        self::assertSame(0, array_sum($second['json']['data']['created']));
    }

    /** @return array<string, string> */
    private function adminHeaders(string $role): array
    {
        $email = 'launch-' . $role . '-admin@shop.test';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'launch admin', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: array<string, mixed>} */
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
        /** @var array<string, mixed> $decoded */
        return ['status' => $response->statusCode(), 'json' => is_array($decoded) ? $decoded : []];
    }

    /** @param array<string, string> $values */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->envBackup)) {
                $existing = $_ENV[$key] ?? getenv($key);
                $this->envBackup[$key] = is_string($existing) ? $existing : false;
            }
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
