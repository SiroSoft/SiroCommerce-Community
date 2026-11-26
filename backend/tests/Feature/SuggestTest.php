<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Modules\Smart\Application\NativeSuggestProvider;
use App\Modules\Smart\Application\SmartAggregator;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * As-you-type autocomplete (spec SEARCH_AUTOCOMPLETE_SPEC): query ranking
 * from the query log plus tier-gated product cards, fail-closed.
 */
final class SuggestTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        Database::table('products')->insert(['id' => 'p-sug-1', 'name' => 'Linen Shirt', 'slug' => 'linen-shirt', 'status' => 'active', 'currency' => 'VND']);
        Database::table('product_variants')->insert(['id' => 'v-sug-1', 'product_id' => 'p-sug-1', 'sku' => 'LINEN-001', 'price_minor' => 45000]);
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

    public function testSuggestRanksPrefixFirstAndAddsProducts(): void
    {
        $pdo = Database::connection();
        $payload = static fn (string $query): string => json_encode(['event' => 'search_submit', 'properties' => ['query' => $query]]);
        $pdo->prepare('INSERT INTO analytics_events (id, event, payload_json) VALUES (?, ?, ?)')->execute(['sg1', 'search_submit', $payload('linen shirt')]);
        $pdo->prepare('INSERT INTO analytics_events (id, event, payload_json) VALUES (?, ?, ?)')->execute(['sg2', 'search_submit', $payload('blue linen pants')]);
        self::assertSame(2, (new SmartAggregator())->aggregateSearchTerms());
        $res = $this->call('GET', '/api/search/suggest?q=lin');
        self::assertSame(200, $res['status']);
        // Legacy shape kept: data is the query list, prefix hit first.
        self::assertSame('linen shirt', $res['json']['data'][0]['query']);
        self::assertSame('blue linen pants', $res['json']['data'][1]['query']);
        // Product cards alongside.
        self::assertSame('Linen Shirt', $res['json']['products'][0]['name']);
        self::assertSame('v-sug-1', $res['json']['products'][0]['variant_id']);
        self::assertSame(45000, $res['json']['products'][0]['price_minor']);
    }

    public function testSuggestHidesGatedProductsFromGuests(): void
    {
        Database::table('products')->insert(['id' => 'p-sug-2', 'name' => 'Linen Vault', 'slug' => 'linen-vault', 'status' => 'active', 'currency' => 'VND', 'min_tier' => 'gold']);
        Database::table('product_variants')->insert(['id' => 'v-sug-2', 'product_id' => 'p-sug-2', 'sku' => 'LINEN-002', 'price_minor' => 99000]);
        $provider = new NativeSuggestProvider();
        $guest = $provider->suggest('linen', 8, null);
        $ids = array_column($guest['products'], 'product_id');
        self::assertContains('p-sug-1', $ids);
        self::assertNotContains('p-sug-2', $ids);
        $gold = $provider->suggest('linen', 8, 'gold');
        self::assertContains('p-sug-2', array_column($gold['products'], 'product_id'));
    }

    public function testSuggestFailsClosed(): void
    {
        $empty = $this->call('GET', '/api/search/suggest?q=');
        self::assertSame([], $empty['json']['data']);
        $pii = $this->call('GET', '/api/search/suggest?q=buyer@example.test');
        self::assertSame([], $pii['json']['data']);
        self::assertSame([], $pii['json']['products']);
    }
}
