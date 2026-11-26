<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Modules\Inventory\Application\StockAlertService;
use App\Repositories\InventoryRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class StockAlertTest extends TestCase
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

    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
            $query = is_array($query) ? $query : [];
        }
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
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

    public function testSubscribeDedupesAndRestockNotifies(): void
    {
        $service = new StockAlertService();
        $first = $service->subscribe('p-1', 'v-1', 'buyer@example.test', null);
        self::assertSame('pending', $first['state']);
        self::assertNotEmpty($first['unsubscribe_token']);
        try {
            $service->subscribe('p-1', 'v-1', 'buyer@example.test', null);
            self::fail('duplicate should throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }
        // Restock 0 -> positive wakes the alert (no mailer configured: emailed 0).
        (new InventoryRepository())->setOnHand('v-1', 0, 'test');
        $result = $service->checkRestock('v-1');
        self::assertSame(0, $result['notified']);
        (new InventoryRepository())->setOnHand('v-1', 5, 'test');
        $result = $service->checkRestock('v-1');
        self::assertSame(1, $result['notified']);
        self::assertSame(0, $result['emailed']);
        // Second check finds nothing pending.
        self::assertSame(0, $service->checkRestock('v-1')['notified']);
        // Unsubscribe token works once (on a still-pending alert).
        $second = $service->subscribe('p-2', null, 'other@example.test', null);
        self::assertTrue($service->unsubscribe($second['unsubscribe_token']));
        self::assertFalse($service->unsubscribe($second['unsubscribe_token']));
    }

    public function testPriceDropSubscribeValidatesAndFires(): void
    {
        $service = new StockAlertService();
        try {
            $service->subscribe('p-pd', 'v-pd', 'pd@example.test', null, 'price_drop', null);
            self::fail('target required');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Target price', $e->getMessage());
        }
        try {
            $service->subscribe('p-pd', 'v-pd', 'pd@example.test', null, 'bogus', null);
            self::fail('type validated');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Type must be', $e->getMessage());
        }
        $row = $service->subscribe('p-pd', 'v-pd', 'pd@example.test', null, 'price_drop', 15000);
        self::assertSame('price_drop', $row['type']);
        // Above target: nothing fires.
        self::assertSame(0, $service->checkPriceDrop('v-pd', 20000)['notified']);
        // At target: fires once (no mailer configured: emailed 0).
        $fired = $service->checkPriceDrop('v-pd', 15000);
        self::assertSame(1, $fired['notified']);
        self::assertSame(0, $fired['emailed']);
        self::assertSame(0, $service->checkPriceDrop('v-pd', 10000)['notified']);
    }

    public function testPreorderRequiresFlagAndFiresOnRestock(): void
    {
        $service = new StockAlertService();
        try {
            $service->subscribe('p-po', 'v-po', 'po@example.test', null, 'preorder', null);
            self::fail('flag required');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not available for preorder', $e->getMessage());
        }
        Database::table('products')->insert(['id' => 'p-po', 'name' => 'Preorder Chair', 'slug' => 'preorder-chair', 'status' => 'active', 'is_preorder' => 1]);
        Database::table('product_variants')->insert(['id' => 'v-po', 'product_id' => 'p-po', 'sku' => 'PO-001', 'price_minor' => 50000]);
        $row = $service->subscribe('p-po', 'v-po', 'po@example.test', null, 'preorder', null);
        self::assertSame('preorder', $row['type']);
        (new InventoryRepository())->setOnHand('v-po', 0, 'test');
        self::assertSame(0, $service->checkRestock('v-po')['notified']);
        (new InventoryRepository())->setOnHand('v-po', 3, 'test');
        $fired = $service->checkRestock('v-po');
        self::assertSame(1, $fired['notified']);
    }

    public function testHttpSubscribeAcceptsTypeAndTarget(): void
    {
        $ok = $this->call('POST', '/api/products/p-http/stock-alerts', ['email' => 'buyer@example.test', 'type' => 'price_drop', 'target_price_minor' => 9000]);
        $this->assertSame(200, $ok['status']);
        $this->assertSame('price_drop', $ok['json']['data']['type']);
        $bad = $this->call('POST', '/api/products/p-http/stock-alerts', ['email' => 'buyer@example.test', 'type' => 'price_drop']);
        $this->assertSame(422, $bad['status']);
        $bogus = $this->call('POST', '/api/products/p-http/stock-alerts', ['email' => 'buyer@example.test', 'type' => 'nope']);
        $this->assertSame(422, $bogus['status']);
    }

    public function testHttpSubscribeRequiresContactAndUnsubscribeFlow(): void
    {
        $none = $this->call('POST', '/api/products/p-9/stock-alerts', []);
        $this->assertSame(422, $none['status']);
        $bad = $this->call('POST', '/api/products/p-9/stock-alerts', ['email' => 'not-an-email']);
        $this->assertSame(422, $bad['status']);
        $ok = $this->call('POST', '/api/products/p-9/stock-alerts', ['email' => 'buyer@example.test']);
        $this->assertSame(200, $ok['status']);
        $this->assertNotEmpty($ok['json']['data']['unsubscribe_token']);
        $unsub = $this->call('POST', '/api/products/stock-alerts/unsubscribe', ['token' => $ok['json']['data']['unsubscribe_token']]);
        $this->assertSame(200, $unsub['status']);
    }
}
