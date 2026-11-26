<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Modules\Smart\Application\OpsAlertService;
use App\Modules\Smart\Application\SmartAggregator;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Ops anomaly alerts (P4 ops intelligence, rules-based): spikes in refunds,
 * payment failures and fulfillment failures open one row per kind, ack
 * survives until the condition clears, then the row resolves.
 */
final class AnomalyAlertsTest extends TestCase
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

    private function seedEvents(string $event, int $recent, int $old): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO analytics_events (id, event, event_id, payload_json, created_at) VALUES (?, ?, ?, ?, ?)');
        for ($i = 0; $i < $recent; $i++) {
            $stmt->execute([\App\Support\Ids::new(), $event, 'anom-r-' . $event . '-' . $i . '-' . uniqid(), '{}', date('Y-m-d H:i:s', time() - 3600)]);
        }
        for ($i = 0; $i < $old; $i++) {
            $stmt->execute([\App\Support\Ids::new(), $event, 'anom-o-' . $event . '-' . $i . '-' . uniqid(), '{}', date('Y-m-d H:i:s', time() - 3 * 86400)]);
        }
    }

    public function testRefundSpikeOpensAckSurvivesThenResolves(): void
    {
        // 6 refunds in 24h, 2 in the prior week: baseline 0.29/day → fires.
        $this->seedEvents('refund.created', 6, 2);
        self::assertSame(1, (new SmartAggregator())->aggregateAnomalies());
        $row = Database::table('ops_anomaly_alerts')->where('kind', 'refund_spike')->first();
        self::assertIsArray($row);
        self::assertSame('open', $row['state']);
        self::assertSame(6, (int) ($row['observed'] ?? -1));
        // No payment/fulfillment spikes on a quiet store.
        self::assertNull(Database::table('ops_anomaly_alerts')->where('kind', 'payment_fail_spike')->first());
        // Ack keeps the row acked while the spike continues.
        self::assertTrue((new OpsAlertService())->ack('refund_spike'));
        self::assertSame(0, (new SmartAggregator())->aggregateAnomalies());
        $acked = Database::table('ops_anomaly_alerts')->where('kind', 'refund_spike')->first();
        self::assertIsArray($acked);
        self::assertSame('acked', $acked['state']);
        // Spike clears (events age out) → resolved.
        Database::connection()->exec("UPDATE analytics_events SET created_at = '2020-01-01 00:00:00' WHERE event = 'refund.created'");
        self::assertSame(0, (new SmartAggregator())->aggregateAnomalies());
        $resolved = Database::table('ops_anomaly_alerts')->where('kind', 'refund_spike')->first();
        self::assertIsArray($resolved);
        self::assertSame('resolved', $resolved['state']);
    }

    public function testQuietStoreStaysSilent(): void
    {
        $this->seedEvents('payment.failed', 2, 0);
        self::assertSame(0, (new SmartAggregator())->aggregateAnomalies());
        self::assertNull(Database::table('ops_anomaly_alerts')->where('kind', 'payment_fail_spike')->first());
    }

    public function testOpsAlertRoutesRequireAdmin(): void
    {
        $list = $this->call('GET', '/api/admin/ops-alerts');
        self::assertSame(401, $list['status']);
        $ack = $this->call('POST', '/api/admin/ops-alerts/refund_spike/ack');
        self::assertSame(401, $ack['status']);
    }
}
