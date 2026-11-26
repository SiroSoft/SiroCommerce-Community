<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\UserRepository;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;
use App\Models\Reservation;

/**
 * Developer superpowers (spec 09): a failed transaction is diagnosed and
 * recovered coherently — trace → doctor → dry-run → recover → verify.
 */
final class DevTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $stub = new \stdClass();
        $stub->router = $this->router;
        $app = $stub;
        require dirname(__DIR__, 2) . '/routes/api.php';

        $carts = new CartRepository();
        $carts->createVariant(['id' => 'v-dev-1', 'product_id' => 'p-dev-1', 'sku' => 'DEV-001', 'price_minor' => 75000]);
        (new InventoryRepository())->setOnHand('v-dev-1', 5, 'test');
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $queryParams = [];
        $parts = explode('?', $path, 2);
        $cleanPath = $parts[0];
        if (isset($parts[1])) {
            parse_str($parts[1], $queryParams);
        }
        $request = new Request($method, $cleanPath, $queryParams, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        /** @var array{status: int, json: mixed} $out */
        $out = ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
        return $out;
    }

    /** @return array<string, string> */
    private function admin(): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'd', 'email' => 'dev@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('dev@shop.test', 'secret123');
        $access = $tokens->access;
        return ['Authorization' => 'Bearer ' . (is_string($access) ? $access : '')];
    }

    public function testTraceDiagnoseRecoverCycle(): void
    {
        $admin = $this->admin();

        // Build a real order to trace.
        $cart = $this->call('POST', '/api/carts', []);
        $cartId = $cart['json']['data']['id'];
        $cartHeaders = ['X-Cart-Token' => $cart['json']['data']['access_token']];
        $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-dev-1', 'qty' => 1], $cartHeaders);
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], ['Idempotency-Key' => 'dev-key-1', 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'] + $cartHeaders);
        $orderId = $placed['json']['data']['id'];

        $trace = $this->call('GET', "/api/admin/dev/trace/{$orderId}", [], $admin);
        $this->assertSame(200, $trace['status']);
        $steps = array_column($trace['json']['data']['timeline'], 'step');
        $this->assertContains('order.placed', $steps);
        $this->assertContains('order.transition', $steps);
        $this->assertContains('payment', $steps);
        $this->assertContains('inventory', $steps);
        $this->assertContains('ledger', $steps);

        $missing = $this->call('GET', '/api/admin/dev/trace/nope', [], $admin);
        $this->assertSame(404, $missing['status']);

        // Healthy system: doctor finds nothing critical.
        $doctor = $this->call('GET', '/api/admin/dev/doctor?scope=all', [], $admin);
        $this->assertSame(200, $doctor['status']);
        $criticals = array_filter($doctor['json']['data'], static fn ($f) => $f['level'] === 'critical');
        $this->assertCount(0, array_values($criticals));

        $scoped = $this->call('GET', "/api/admin/dev/doctor?scope=order:{$orderId}", [], $admin);
        $this->assertSame(200, $scoped['status']);

        $badScope = $this->call('GET', '/api/admin/dev/doctor?scope=bogus', [], $admin);
        $this->assertSame(422, $badScope['status']);

        // Recovery dry-run changes nothing; real run releases nothing (no expired holds).
        $dry = $this->call('POST', '/api/admin/dev/recover', ['action' => 'release-expired', 'dry_run' => 1], $admin);
        $this->assertSame(200, $dry['status']);
        $this->assertTrue($dry['json']['data']['dry_run']);

        $badAction = $this->call('POST', '/api/admin/dev/recover', ['action' => 'nuke'], $admin);
        $this->assertSame(422, $badAction['status']);
    }

    public function testExpiredHoldRecovery(): void
    {
        $admin = $this->admin();
        $inventory = new \App\Modules\Inventory\Application\InventoryManager(new \App\Modules\Platform\Infrastructure\InventoryStoreAdapter(new InventoryRepository()));
        $held = $inventory->reserve('v-dev-1', 2, 'cart-old', 1);
        $heldId = is_string($held['id'] ?? null) ? $held['id'] : '';
        Reservation::find($heldId)?->update(['expires_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $this->assertSame(3, $inventory->available('v-dev-1'));

        $doctor = $this->call('GET', '/api/admin/dev/doctor?scope=inventory', [], $admin);
        $this->assertSame(200, $doctor['status']);
        $this->assertNotCount(0, $doctor['json']['data']);

        $dry = $this->call('POST', '/api/admin/dev/recover', ['action' => 'release-expired', 'dry_run' => 1], $admin);
        $this->assertSame([$heldId], $dry['json']['data']['released']);
        $this->assertSame(3, $inventory->available('v-dev-1'));

        $real = $this->call('POST', '/api/admin/dev/recover', ['action' => 'release-expired', 'dry_run' => 0], $admin);
        $this->assertSame(200, $real['status']);
        $this->assertTrue($real['json']['data']['verified']);
        $this->assertSame(5, $inventory->available('v-dev-1'));
    }
}
