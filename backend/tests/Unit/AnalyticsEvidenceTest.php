<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Modules\Analytics\Application\AnalyticsService;
use App\Modules\Analytics\Contracts\AnalyticsStore;
use App\Modules\Analytics\Domain\AnalyticsRedactor;
use App\Modules\Analytics\Domain\AnalyticsEventSchema;
use App\Role;
use Tests\TestCase;

final class AnalyticsEvidenceTest extends TestCase
{
    public function testAnalyticsCapabilityIsReadOnlyRoleGated(): void
    {
        self::assertTrue(Role::can(Role::MARKETER, 'analytics'));
        self::assertFalse(Role::can(Role::VIEWER, 'analytics'));
    }

    public function testPayloadRedactionAndSchemaValidation(): void
    {
        self::assertSame(['order_id' => 'o1', 'nested' => ['state' => 'paid']], AnalyticsRedactor::redact(['order_id' => 'o1', 'email' => 'buyer@example.test', 'nested' => ['state' => 'paid', 'phone' => '123']]));
        self::assertSame([['sku' => 's1', 'quantity' => 2]], AnalyticsRedactor::redact(['lines' => [['sku' => 's1', 'quantity' => 2, 'email' => 'secret@example.test']]])['lines']);
        $store = new class implements AnalyticsStore { public function record(array $data): array { return $data; } public function count(string $event): int { return 0; } };
        $service = new AnalyticsService($store);
        $row = $service->record('payment.captured', ['payment_id' => 'p1', 'email' => 'secret@example.test']);
        self::assertArrayNotHasKey('email', json_decode((string) $row['payload_json'], true));
        $this->expectException(\InvalidArgumentException::class);
        $service->record('payment.captured', [], null, AnalyticsEventSchema::VERSION + 1);
    }

    public function testRecordUsesDeterministicReplayIdAndHashesSessionOnce(): void
    {
        $store = new class implements AnalyticsStore { public array $rows = []; public function record(array $data): array { $this->rows[] = $data; return $data; } public function count(string $event): int { return 0; } };
        $service = new AnalyticsService($store);
        $first = $service->record('order.placed', ['entity_type' => 'order', 'entity_id' => 'o1', 'session_key' => 'session-1']);
        $second = $service->record('order.placed', ['entity_type' => 'order', 'entity_id' => 'o1', 'session_key' => 'session-1']);
        self::assertSame($first['event_id'], $second['event_id']);
        self::assertSame(hash('sha256', 'session-1'), $first['session_key']);
        self::assertCount(2, $store->rows);
    }

    public function testUnsupportedEventIsRejected(): void
    {
        $store = new class implements AnalyticsStore { public function record(array $data): array { return $data; } public function count(string $event): int { return 0; } };
        $service = new AnalyticsService($store);
        $this->expectException(\InvalidArgumentException::class);
        $service->record('not.supported', []);
    }

    public function testReplayUsesStableEventIdAndAllowsExtendedEvents(): void
    {
        $store = new class implements AnalyticsStore { public array $rows = []; public function record(array $data): array { $this->rows[$data['event_id']] = $data; return $data; } public function count(string $event): int { return count($this->rows); } };
        $service = new AnalyticsService($store);
        $first = $service->record('fulfillment.delivered', ['fulfillment_id' => 'f1']);
        $second = $service->record('fulfillment.delivered', ['fulfillment_id' => 'f1']);
        self::assertSame($first['event_id'], $second['event_id']);
        self::assertCount(1, $store->rows);
    }

    public function testAnalyticsRecordCarriesTenantScope(): void
    {
        $store = new class implements AnalyticsStore {
            public array $row = [];
            public function record(array $data): array { $this->row = $data; return $data; }
            public function count(string $event): int { return 0; }
        };
        (new AnalyticsService($store))->track('order.placed', 'order', 'order-1', null, 100, 'store-1', 'channel-1');
        self::assertSame('store-1', $store->row['store_id']);
        self::assertSame('channel-1', $store->row['channel_id']);
    }
}
