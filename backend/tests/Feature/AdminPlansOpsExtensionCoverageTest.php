<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class AdminPlansOpsExtensionCoverageTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    public function testSubscriptionPlanCrudSurface(): void
    {
        $headers = $this->adminHeaders();

        $list = $this->call('GET', '/api/v2/admin/subscription-plans', [], $headers);
        self::assertSame(200, $list['status']);
        self::assertSame([], $list['json']['data']);
        self::assertSame(422, $this->call('GET', '/api/v2/admin/subscription-plans?state=bogus', [], $headers)['status']);

        self::assertSame(422, $this->call('POST', '/api/v2/admin/subscription-plans', ['name' => 'Broken'], $headers)['status']);
        self::assertSame(422, $this->call('POST', '/api/v2/admin/subscription-plans', [
            'name' => 'Zero', 'amount_minor' => 0, 'currency' => 'USD', 'interval' => 'monthly',
        ], $headers)['status']);

        $created = $this->call('POST', '/api/v2/admin/subscription-plans', [
            'name' => 'Monthly Care', 'amount_minor' => 1200, 'currency' => 'usd', 'interval' => 'monthly',
            'interval_count' => 1, 'trial_period' => 'weekly', 'trial_period_count' => 7, 'state' => 'active', 'provider' => 'sandbox',
        ], $headers);
        self::assertSame(201, $created['status']);
        self::assertSame('MONTHLY', strtoupper((string) $created['json']['data']['interval']));
        $id = (string) $created['json']['data']['id'];

        self::assertSame(404, $this->call('PUT', '/api/v2/admin/subscription-plans/missing', ['name' => 'Nope'], $headers)['status']);
        self::assertSame(422, $this->call('PUT', '/api/v2/admin/subscription-plans/' . $id, ['interval' => 'daily'], $headers)['status']);
        $updated = $this->call('PUT', '/api/v2/admin/subscription-plans/' . $id, ['amount_minor' => 1500, 'state' => 'disabled'], $headers);
        self::assertSame(200, $updated['status']);
        self::assertSame(1500, $updated['json']['data']['amount_minor']);
        self::assertSame('disabled', $updated['json']['data']['state']);

        $filtered = $this->call('GET', '/api/v2/admin/subscription-plans?state=disabled', [], $headers);
        self::assertSame(200, $filtered['status']);
        self::assertCount(1, $filtered['json']['data']);

        self::assertSame(404, $this->call('DELETE', '/api/v2/admin/subscription-plans/missing', [], $headers)['status']);

        Database::connection()->prepare(
            'INSERT INTO subscriptions (id, customer_id, plan_id, provider, currency, amount_minor, interval, interval_count, state, next_billing_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['surface-sub', 'customer', $id, 'sandbox', 'USD', 1500, 'monthly', 1, 'active', '2026-12-01 00:00:00']);
        self::assertSame(409, $this->call('DELETE', '/api/v2/admin/subscription-plans/' . $id, [], $headers)['status']);

        Database::connection()->prepare('DELETE FROM subscriptions WHERE id = ?')->execute(['surface-sub']);
        self::assertSame(204, $this->call('DELETE', '/api/v2/admin/subscription-plans/' . $id, [], $headers)['status']);
    }

    public function testExtensionControllerValidationAndMissingIds(): void
    {
        $headers = $this->adminHeaders();

        self::assertSame(200, $this->call('GET', '/api/admin/extensions', [], $headers)['status']);
        self::assertSame(200, $this->call('GET', '/api/admin/extensions/ui', [], $headers)['status']);

        self::assertSame(422, $this->call('POST', '/api/admin/extensions/install', ['id' => 'vendor/name'], $headers)['status']);
        self::assertSame(404, $this->call('POST', '/api/admin/extensions/install', [], $headers + ['Idempotency-Key' => 'ext-key-1'])['status']);
        self::assertSame(404, $this->call('POST', '/api/admin/extensions/install', ['id' => 'missing/extension'], $headers + ['Idempotency-Key' => 'ext-key-2'])['status']);

        self::assertSame(422, $this->call('POST', '/api/admin/extensions/enable', ['id' => 'missing/extension'], $headers)['status']);
        self::assertSame(404, $this->call('POST', '/api/admin/extensions/enable', ['id' => 'missing/extension'], $headers + ['Idempotency-Key' => 'ext-key-3'])['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/extensions/disable', ['id' => 'missing/extension'], $headers)['status']);
        self::assertSame(404, $this->call('POST', '/api/admin/extensions/disable', ['id' => 'missing/extension'], $headers + ['Idempotency-Key' => 'ext-key-4'])['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/extensions/uninstall', ['id' => 'missing/extension'], $headers)['status']);
        self::assertSame(404, $this->call('POST', '/api/admin/extensions/uninstall', ['id' => 'missing/extension', 'remove_data' => true], $headers + ['Idempotency-Key' => 'ext-key-5'])['status']);
        self::assertSame(404, $this->call('POST', '/api/admin/extensions/health', [], $headers)['status']);
        self::assertSame(404, $this->call('POST', '/api/admin/extensions/health', ['id' => 'missing/extension'], $headers)['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/extensions/upgrade', ['id' => 'missing/extension'], $headers)['status']);
        self::assertSame(404, $this->call('POST', '/api/admin/extensions/upgrade', ['id' => 'missing/extension'], $headers + ['Idempotency-Key' => 'ext-key-6'])['status']);
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        $email = 'ops-admin-' . bin2hex(random_bytes(4)) . '@shop.test';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'Ops Admin', 'email' => $email, 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: array<string, mixed>} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        $route = $path;
        if (str_contains($path, '?')) {
            [$route, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }
        $request = new Request($method, $route, $query, $headers, $body, '127.0.0.1');
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
}
