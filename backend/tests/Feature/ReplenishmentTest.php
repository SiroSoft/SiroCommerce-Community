<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Modules\Smart\Application\SmartAggregator;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Demand forecast hints (P4 ops intelligence, rules-based): velocity from
 * recent non-cancelled orders, cover from live availability, 14-day target.
 */
final class ReplenishmentTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        (new CartRepository())->createVariant(['id' => 'v-rep-1', 'product_id' => 'p-rep-1', 'sku' => 'REP-001', 'price_minor' => 10000]);
        (new InventoryRepository())->setOnHand('v-rep-1', 10, 'test');
        $pdo = Database::connection();
        $eightDaysAgo = date('Y-m-d H:i:s', time() - 8 * 86400);
        $twoDaysAgo = date('Y-m-d H:i:s', time() - 2 * 86400);
        $pdo->exec("INSERT INTO orders (id, number, currency, subtotal_minor, grand_total_minor, state, idempotency_key, created_at) VALUES ('o-rep-1', 'REP-1', 'VND', 100, 100, 'paid', 'rep-1', '{$eightDaysAgo}'), ('o-rep-2', 'REP-2', 'VND', 100, 100, 'paid', 'rep-2', '{$twoDaysAgo}'), ('o-rep-3', 'REP-3', 'VND', 100, 100, 'cancelled', 'rep-3', '{$twoDaysAgo}')");
        $pdo->exec("INSERT INTO order_lines (id, order_id, variant_id, sku, name, qty, unit_price_minor) VALUES ('rl1', 'o-rep-1', 'v-rep-1', 'REP-001', 'Rep item', 20, 50), ('rl2', 'o-rep-2', 'v-rep-1', 'REP-001', 'Rep item', 40, 50), ('rl3', 'o-rep-3', 'v-rep-1', 'REP-001', 'Rep item', 999, 50)");
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

    public function testVelocityCoverAndSuggestedQty(): void
    {
        self::assertSame(1, (new SmartAggregator())->aggregateReplenishment());
        $row = Database::table('replenishment_hints')->where('variant_id', 'v-rep-1')->first();
        self::assertIsArray($row);
        // Cancelled order's 999 units never count: 20 + 40 in 30d, 40 in 7d.
        self::assertSame(60, (int) ($row['sold_30'] ?? -1));
        self::assertSame(40, (int) ($row['sold_7'] ?? -1));
        // 2/day over 10 available → 5 days cover, top-up to 14 days = 18.
        self::assertSame(5, (int) ($row['days_of_cover'] ?? -1));
        self::assertSame(18, (int) ($row['suggested_qty'] ?? -1));
        // Idempotent re-rollup keeps one row.
        self::assertSame(1, (new SmartAggregator())->aggregateReplenishment());
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM replenishment_hints WHERE variant_id = 'v-rep-1'")->fetchColumn());
    }
}
