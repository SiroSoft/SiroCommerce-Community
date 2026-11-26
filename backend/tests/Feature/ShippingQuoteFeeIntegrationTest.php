<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\ShippingFeeRules\Extension as ShippingFeeRulesExtension;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Cart\Application\CartService;
use App\Modules\Checkout\Application\CheckoutService;
use App\Modules\Checkout\Application\QuoteService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Fulfillment\Application\PickupPointService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Modules\Shipping\Application\ShippingFeeAdjusterRegistry;
use App\Modules\Shipping\Contracts\ShippingFeeAdjuster;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\UserRepository;
use Siro\Core\Container;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ShippingQuoteFeeIntegrationTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(ShippingFeeRulesExtension::class);
        ExtensionRegistry::reset();
        ShippingFeeAdjusterRegistry::reset();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        (new CartRepository())->createVariant([
            'id' => 'fee-variant-1',
            'product_id' => 'fee-product-1',
            'sku' => 'FEE-001',
            'price_minor' => 100000,
        ]);
        $inventory = new InventoryRepository();
        $inventory->setOnHand('fee-variant-1', 100, 'test', 'default');
        $inventory->setOnHand('fee-variant-1', 100, 'test', 'store-hn');
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        ShippingFeeAdjusterRegistry::reset();
        parent::tearDown();
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @param array<string, mixed> $query @return array{status: int, json: mixed} */
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
    private function token(string $role = 'admin'): array
    {
        $email = 'ship-quote-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'ship', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['access' => $tokens->access, 'role' => $role];
    }

    /** @return array<string, string> */
    private function headers(string $access, string $store = 'default', string $channel = 'web'): array
    {
        return [
            'Authorization' => 'Bearer ' . $access,
            'X-Store-Id' => $store,
            'X-Channel-Id' => $channel,
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
            'Idempotency-Key' => 'ship-quote-' . bin2hex(random_bytes(6)),
        ];
    }

    private function enableShippingFeeRules(): ExtensionLifecycleService
    {
        $service = $this->service();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new ShippingFeeRulesExtension())->manifest()));
        $service->enable(ShippingFeeRulesExtension::id());
        $this->remountRoutes();
        return $service;
    }

    private function remountRoutes(): void
    {
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require $this->basePath() . '/routes/api.php';
    }

    /** @return array<string, mixed> */
    private function rule(string $name, string $method, int $fee, int $freeOver = 0): array
    {
        return [
            'name' => $name,
            'method' => $method,
            'min_subtotal_minor' => 0,
            'fee_minor' => $fee,
            'free_over_minor' => $freeOver,
            'active' => 1,
            'priority' => 100,
        ];
    }

    private function createRule(array $admin, array $rule, string $store = 'default', string $channel = 'web'): void
    {
        $response = $this->call('POST', '/api/ext/siro/shipping-fee-rules/rules', $rule, $this->headers((string) $admin['access'], $store, $channel));
        self::assertSame(200, $response['status'], (string) json_encode($response['json']));
    }

    /** @return array{string, array<string, string>} */
    private function cartWithLine(int $qty): array
    {
        $cart = $this->call('POST', '/api/carts', []);
        self::assertSame(201, $cart['status']);
        $cartId = (string) $cart['json']['data']['id'];
        $headers = ['X-Cart-Token' => (string) $cart['json']['data']['access_token']];
        $line = $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'fee-variant-1', 'qty' => $qty], $headers);
        self::assertSame(201, $line['status']);
        return [$cartId, $headers];
    }

    public function testCartQuoteAppliesStandardCodAndFreeThreshold(): void
    {
        $this->enableShippingFeeRules();
        $admin = $this->token();
        $this->createRule($admin, $this->rule('Standard fee', 'standard', 25000, 500000));
        $this->createRule($admin, $this->rule('COD fee', 'cod', 15000));

        [$cartId, $cartHeaders] = $this->cartWithLine(1);
        $standard = $this->call('GET', "/api/carts/{$cartId}/quote", [], $cartHeaders, ['fulfillment_method' => 'standard']);
        self::assertSame(200, $standard['status']);
        self::assertSame(25000, $standard['json']['data']['shipping_minor']);
        self::assertSame(125000, $standard['json']['data']['total_minor']);

        $cod = $this->call('GET', "/api/carts/{$cartId}/quote", [], $cartHeaders, ['fulfillment_method' => 'cod']);
        self::assertSame(200, $cod['status']);
        self::assertSame(15000, $cod['json']['data']['shipping_minor']);
        self::assertSame(115000, $cod['json']['data']['total_minor']);

        [$freeCartId, $freeHeaders] = $this->cartWithLine(6);
        $free = $this->call('GET', "/api/carts/{$freeCartId}/quote", [], $freeHeaders, ['fulfillment_method' => 'standard']);
        self::assertSame(200, $free['status']);
        self::assertSame(0, $free['json']['data']['shipping_minor']);
        self::assertSame(600000, $free['json']['data']['total_minor']);
    }

    public function testFeeIsStoreAndChannelScopedAndDisableStopsIt(): void
    {
        $lifecycle = $this->enableShippingFeeRules();
        $admin = $this->token();
        $this->createRule($admin, $this->rule('Store A standard', 'standard', 25000), 'store-a', 'web');
        self::assertTrue(ShippingFeeAdjusterRegistry::has());

        [$cartId] = $this->cartWithLine(1);
        /** @var QuoteService $quotes */
        $quotes = Container::getInstance()->make(QuoteService::class);

        $inside = $quotes->quote($cartId, null, [
            'scope' => ['store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND'],
            'shipping_method' => 'standard',
        ]);
        self::assertSame(25000, $inside['shipping_minor']);
        self::assertSame(125000, $inside['grand_total_minor']);

        $otherStore = $quotes->quote($cartId, null, [
            'scope' => ['store_id' => 'store-b', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND'],
            'shipping_method' => 'standard',
        ]);
        self::assertSame(0, $otherStore['shipping_minor']);
        self::assertSame(100000, $otherStore['grand_total_minor']);

        $otherChannel = $quotes->quote($cartId, null, [
            'scope' => ['store_id' => 'store-a', 'channel_id' => 'mobile', 'locale' => 'en', 'currency' => 'VND'],
            'shipping_method' => 'standard',
        ]);
        self::assertSame(0, $otherChannel['shipping_minor']);

        $lifecycle->disable(ShippingFeeRulesExtension::id());
        self::assertFalse(ShippingFeeAdjusterRegistry::has());
        $disabled = $quotes->quote($cartId, null, [
            'scope' => ['store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND'],
            'shipping_method' => 'standard',
        ]);
        self::assertSame(0, $disabled['shipping_minor']);
    }

    public function testPickupKeepsFreeShippingWhileRulesAreEnabled(): void
    {
        $this->enableShippingFeeRules();
        $admin = $this->token();
        $this->createRule($admin, $this->rule('Standard fee', 'standard', 25000));

        (new PickupPointService())->create([
            'code' => 'FEE-HN-01',
            'name' => 'Fee pickup point',
            'source_id' => 'store-hn',
            'sla_hours' => 24,
        ], 'fee-pickup-point-1');

        [$cartId, $cartHeaders] = $this->cartWithLine(1);
        $quote = $this->call('GET', "/api/carts/{$cartId}/quote", [], $cartHeaders, [
            'fulfillment_method' => 'pickup',
            'pickup_point' => 'FEE-HN-01',
        ]);
        self::assertSame(200, $quote['status'], (string) json_encode($quote['json']));
        self::assertSame(0, $quote['json']['data']['shipping_minor']);
        self::assertSame(100000, $quote['json']['data']['total_minor']);
    }

    public function testMissingRuleTableAndAdjusterFailureDoNotBreakQuote(): void
    {
        $this->enableShippingFeeRules();
        $admin = $this->token();
        $this->createRule($admin, $this->rule('Standard fee', 'standard', 25000));

        Database::connection()->exec('DROP TABLE ext_shipping_fee_rules');
        [$cartId, $cartHeaders] = $this->cartWithLine(1);
        $missing = $this->call('GET', "/api/carts/{$cartId}/quote", [], $cartHeaders, ['fulfillment_method' => 'standard']);
        self::assertSame(200, $missing['status']);
        self::assertSame(0, $missing['json']['data']['shipping_minor']);

        ShippingFeeAdjusterRegistry::reset();
        ShippingFeeAdjusterRegistry::register('test/throwing', new class implements ShippingFeeAdjuster {
            public function fee(array $scope, int $subtotalMinor, string $method): int
            {
                throw new \RuntimeException('adjuster exploded');
            }
        });
        $failed = $this->call('GET', "/api/carts/{$cartId}/quote", [], $cartHeaders, ['fulfillment_method' => 'standard']);
        self::assertSame(200, $failed['status']);
        self::assertSame(0, $failed['json']['data']['shipping_minor']);
    }

    public function testSnapshotAndOrderTotalsUseAdjustedShipping(): void
    {
        $this->enableShippingFeeRules();
        $admin = $this->token();
        $this->createRule($admin, $this->rule('Standard fee', 'standard', 25000));

        [$cartId] = $this->cartWithLine(1);
        /** @var QuoteService $quotes */
        $quotes = Container::getInstance()->make(QuoteService::class);
        $scope = ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND'];
        $quote = $quotes->quote($cartId, null, ['scope' => $scope, 'shipping_method' => 'standard']);
        self::assertSame(25000, $quote['shipping_minor']);
        self::assertSame(125000, $quote['grand_total_minor']);

        /** @var CartService $carts */
        $carts = Container::getInstance()->make(CartService::class);
        $direct = $carts->quote($cartId, null, null, null, null, null, 'standard', null, $scope, 'standard');
        self::assertSame(25000, $direct['shipping_minor']);
        self::assertSame(125000, $direct['total_minor']);

        /** @var CheckoutService $checkout */
        $checkout = Container::getInstance()->make(CheckoutService::class);
        $order = $checkout->place($cartId, 'fee-place-1', null, null, $scope, 'cod');
        self::assertSame(25000, $order['shipping_minor']);
        self::assertSame(125000, $order['grand_total_minor']);
    }
}
