<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Smart\Application\SmartAggregator;
use Siro\Core\Database;
use Tests\TestCase;

final class AnomalyWebhookTest extends TestCase
{
    private function seedEvents(int $recent, int $old): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO analytics_events (id, event, event_id, payload_json, created_at) VALUES (?, ?, ?, ?, ?)');
        for ($i = 0; $i < $recent; $i++) {
            $stmt->execute([\App\Support\Ids::new(), 'refund.created', 'anom-r-' . $i . '-' . uniqid(), '{}', date('Y-m-d H:i:s', time() - 3600)]);
        }
        for ($i = 0; $i < $old; $i++) {
            $stmt->execute([\App\Support\Ids::new(), 'refund.created', 'anom-o-' . $i . '-' . uniqid(), '{}', date('Y-m-d H:i:s', time() - 3 * 86400)]);
        }
    }

    public function testWebhookFiresOnlyOnTransitionsIntoOpen(): void
    {
        $this->seedEvents(6, 2);
        $calls = [];
        $aggregator = new SmartAggregator(static function (array $payload) use (&$calls): void {
            $calls[] = $payload;
        });

        self::assertSame(1, $aggregator->aggregateAnomalies());
        self::assertCount(1, $calls);
        self::assertSame(['kind', 'observed', 'baseline_x100', 'ratio_x100', 'at'], array_keys($calls[0]));
        self::assertSame('refund_spike', $calls[0]['kind']);
        self::assertSame(6, $calls[0]['observed']);
        self::assertIsInt($calls[0]['baseline_x100']);
        self::assertIsInt($calls[0]['ratio_x100']);
        self::assertIsString($calls[0]['at']);

        // Still open: no second page.
        self::assertSame(1, $aggregator->aggregateAnomalies());
        self::assertCount(1, $calls);

        // Condition clears -> resolved.
        Database::connection()->exec("UPDATE analytics_events SET created_at = '2020-01-01 00:00:00' WHERE event = 'refund.created'");
        self::assertSame(0, $aggregator->aggregateAnomalies());
        $resolved = Database::table('ops_anomaly_alerts')->where('kind', 'refund_spike')->first();
        self::assertIsArray($resolved);
        self::assertSame('resolved', $resolved['state']);

        // Fresh spike after resolution opens again and pages again.
        $this->seedEvents(6, 0);
        self::assertSame(1, $aggregator->aggregateAnomalies());
        self::assertCount(2, $calls);
    }

    public function testWebhookFailureNeverBreaksAggregation(): void
    {
        $this->seedEvents(6, 2);
        $aggregator = new SmartAggregator(static function (array $payload): void {
            throw new \RuntimeException('webhook down');
        });

        self::assertSame(1, $aggregator->aggregateAnomalies());
        $row = Database::table('ops_anomaly_alerts')->where('kind', 'refund_spike')->first();
        self::assertIsArray($row);
        self::assertSame('open', $row['state']);
    }
}
