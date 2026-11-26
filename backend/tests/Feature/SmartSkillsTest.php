<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Modules\Smart\Application\SmartAggregator;
use App\Modules\Smart\Application\SmartLexiconScorer;
use App\Modules\Smart\Application\SmartQuery;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class SmartSkillsTest extends TestCase
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

    public function testQueryNormalizationRejectsPii(): void
    {
        self::assertSame('linen shirt', SmartQuery::normalize('  Linen   SHIRT '));
        self::assertNull(SmartQuery::normalize('buyer@example.test'));
        self::assertNull(SmartQuery::normalize('call 0901234567 now'));
        self::assertNull(SmartQuery::normalize('https://evil.test/x'));
        self::assertNull(SmartQuery::normalize(''));
    }

    public function testLexiconScoresWithNegationAndBothLocales(): void
    {
        $scorer = new SmartLexiconScorer();
        self::assertGreaterThan(0, $scorer->score('Beautiful soft linen, very comfortable'));
        self::assertLessThan(0, $scorer->score('Chất lượng tệ, giao hàng chậm'));
        self::assertLessThan(0, $scorer->score('Not good, very disappointed'));
        // On-time delivery is positive; a neutral body stays zero.
        self::assertGreaterThan(0, $scorer->score('Giao hàng đúng hẹn'));
        self::assertSame(0, $scorer->score('Sản phẩm bình thường'));
        self::assertGreaterThan(0, $scorer->score('Vai dep, giao hang nhanh'));
        self::assertGreaterThan(0, $scorer->score('Chat lieu tuyet voi, rat ung'));
        self::assertLessThan(0, $scorer->score('San pham khong tot, giao hang cham'));
        self::assertLessThan(0, $scorer->score('Không tốt, vải mỏng và phai màu'));
    }

    public function testAffinitiesCountPairsOncePerOrder(): void
    {
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO orders (id, number, currency, subtotal_minor, grand_total_minor, state, idempotency_key) VALUES ('o-aff-1', 'AFF-1', 'VND', 100, 100, 'paid', 'aff-1'), ('o-aff-2', 'AFF-2', 'VND', 100, 100, 'paid', 'aff-2'), ('o-aff-3', 'AFF-3', 'VND', 100, 100, 'cancelled', 'aff-3')");
        $pdo->exec("INSERT INTO order_lines (id, order_id, variant_id, sku, name, qty, unit_price_minor) VALUES ('l1', 'o-aff-1', 'v-a', 'A', 'A', 1, 50), ('l2', 'o-aff-1', 'v-b', 'B', 'B', 2, 25), ('l3', 'o-aff-2', 'v-a', 'A', 'A', 1, 50), ('l4', 'o-aff-2', 'v-b', 'B', 'B', 1, 50), ('l5', 'o-aff-3', 'v-a', 'A', 'A', 1, 50), ('l6', 'o-aff-3', 'v-c', 'C', 'C', 1, 50)");
        $written = (new SmartAggregator())->aggregateAffinities();
        self::assertSame(1, $written);
        $row = $pdo->query("SELECT product_a, product_b, orders, score FROM smart_affinities")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('v-a', $row['product_a']);
        self::assertSame('v-b', $row['product_b']);
        self::assertSame(2, (int) $row['orders']);
        // Idempotent re-rollup.
        self::assertSame(1, (new SmartAggregator())->aggregateAffinities());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM smart_affinities')->fetchColumn());
    }

    public function testSearchTermsAndSuggestEndpoint(): void
    {
        $pdo = Database::connection();
        $payload = static fn (string $event, string $query): string => json_encode(['event' => $event, 'properties' => ['query' => $query]]);
        $pdo->prepare('INSERT INTO analytics_events (id, event, payload_json) VALUES (?, ?, ?)')->execute(['e1', 'search_submit', $payload('search_submit', 'Linen Shirt')]);
        $pdo->prepare('INSERT INTO analytics_events (id, event, payload_json) VALUES (?, ?, ?)')->execute(['e2', 'search_submit', 'not-json{{{']);
        $written = (new SmartAggregator())->aggregateSearchTerms();
        self::assertSame(1, $written);
        $res = $this->call('GET', '/api/search/suggest?q=lin');
        self::assertSame(200, $res['status']);
        self::assertSame('linen shirt', $res['json']['data'][0]['query']);
        $empty = $this->call('GET', '/api/search/suggest?q=zzz-no-match');
        self::assertSame([], $empty['json']['data']);
    }

    public function testReviewRollupAndSummaryEndpoint(): void
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO product_reviews (id, product_id, customer_id, order_id, order_line_id, rating, title, body, status) VALUES (?, 'p-sum-1', 'c-1', 'o-1', 'l-1', ?, 't', ?, 'approved')")->execute(['r1', 5, 'Beautiful soft linen, very comfortable and durable']);
        $pdo->prepare("INSERT INTO product_reviews (id, product_id, customer_id, order_id, order_line_id, rating, title, body, status) VALUES (?, 'p-sum-1', 'c-2', 'o-2', 'l-2', ?, 't', ?, 'approved')")->execute(['r2', 2, 'Too small and delivery was slow']);
        $pdo->prepare("INSERT INTO product_reviews (id, product_id, customer_id, order_id, order_line_id, rating, title, body, status) VALUES (?, 'p-sum-1', 'c-3', 'o-3', 'l-3', ?, 't', ?, 'pending')")->execute(['r3', 1, 'Terrible, hate it']);
        self::assertSame(1, (new SmartAggregator())->aggregateReviewRollups());
        $res = $this->call('GET', '/api/products/p-sum-1/reviews/summary');
        self::assertSame(200, $res['status']);
        $data = $res['json']['data'];
        self::assertSame(3.5, $data['rating_avg']);
        self::assertSame(2, $data['rating_count']);
        self::assertSame(1, $data['pos_count']);
        self::assertSame(1, $data['neg_count']);
        self::assertArrayHasKey('comfort', $data['aspects']);
        self::assertNotNull($data['excerpts']['positive']);
        $missing = $this->call('GET', '/api/products/p-none/reviews/summary');
        self::assertNull($missing['json']['data']);
    }

    public function testColdStartEndpointsReturnEmpty(): void
    {
        $res = $this->call('GET', '/api/products/v-xyz/affinities');
        self::assertSame(200, $res['status']);
        self::assertSame([], $res['json']['data']);
        $sug = $this->call('GET', '/api/search/suggest');
        self::assertSame(200, $sug['status']);
        self::assertSame([], $sug['json']['data']);
    }
}
