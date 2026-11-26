<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Platform\Infrastructure\DhlCarrier;
use App\Modules\Platform\Infrastructure\DhlSandboxTransport;
use App\Modules\Platform\Infrastructure\DhlHttpTransport;
use App\Modules\Shipping\Application\ShippingService;
use App\Modules\Shipping\Contracts\ShippingEvidenceStore;
use App\Modules\Shipping\Contracts\ShippingProviderRegistry;
use App\Modules\Shipping\Contracts\Carrier;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Modules\Shipping\Contracts\ShippingShipmentStore;
use PHPUnit\Framework\TestCase;

final class ShippingProviderTest extends TestCase
{
    public function testDhlRatesShipmentAndTrackingNormalizeToCanonicalEvidence(): void
    {
        $carrier = new DhlCarrier(new DhlSandboxTransport());
        $service = new ShippingService(new TestShippingProviderRegistry($carrier), new InMemoryShippingEvidenceStore(), new InMemoryShippingShipmentStore());

        $rates = $service->quote(['origin' => ['country' => 'VN'], 'destination' => ['country' => 'DE'], 'packages' => [['weight_g' => 500]]]);
        self::assertSame('dhl-express-worldwide', $rates[0]['code']);

        $store = new InMemoryShippingEvidenceStore();
        $service = new ShippingService(new TestShippingProviderRegistry($carrier), $store, new InMemoryShippingShipmentStore());
        $shipment = $service->create(['destination' => ['country' => 'DE']], 'ship-key-1');
        self::assertSame('dhl', $shipment['provider']);
        self::assertSame('label_created', $shipment['status']);
        self::assertSame('shipment-test', $shipment['shipment_id']);

        $tracking = $service->track((string) $shipment['tracking_number']);
        self::assertSame('in_transit', $tracking['status']);
        self::assertSame('in_transit', $service->webhook(['event_id' => 'evt-1', 'status' => 'in_transit'])['status']);
        self::assertSame($shipment, $service->create(['destination' => ['country' => 'DE']], 'ship-key-1'));
    }

    public function testShipmentCreationRequiresIdempotency(): void
    {
        $carrier = new DhlCarrier(new DhlSandboxTransport());
        $service = new ShippingService(new TestShippingProviderRegistry($carrier), new InMemoryShippingEvidenceStore(), new InMemoryShippingShipmentStore());
        $this->expectException(\InvalidArgumentException::class);
        $service->create([], '');
    }

    public function testReturnLabelsAndUnknownWebhookStatusesAreReplaySafe(): void
    {
        $carrier = new DhlCarrier(new DhlSandboxTransport());
        $store = new InMemoryShippingEvidenceStore();
        $service = new ShippingService(new TestShippingProviderRegistry($carrier), $store, new InMemoryShippingShipmentStore());

        $label = $service->returnLabel(['provider' => 'dhl', 'destination' => ['country' => 'DE']], 'return-key-1');
        self::assertSame('label_created', $label['status']);
        self::assertSame($label, $service->returnLabel(['provider' => 'dhl', 'destination' => ['country' => 'DE']], 'return-key-1'));
        self::assertSame('unknown', $service->webhook(['event_id' => 'evt-unknown', 'status' => 'carrier_pending'])['status']);
        $this->expectException(\InvalidArgumentException::class);
        $service->webhook(['event_id' => 'evt-unknown', 'status' => 'delivered']);
    }

    public function testProductionDhlTransportRejectsSandboxOrIncompleteConfiguration(): void
    {
        $this->expectException(\RuntimeException::class);
        new DhlHttpTransport('http://dhl.test', 'key', 'secret');
    }

    public function testProductionDhlTransportRequiresBothCredentials(): void
    {
        $this->expectException(\RuntimeException::class);
        new DhlHttpTransport('https://dhl.test', 'key', '');
    }

    public function testShippingRejectsInvalidPackageWeightAndNormalizesRateCurrency(): void
    {
        $carrier = new DhlCarrier(new class implements \App\Modules\Shipping\Contracts\DhlTransport {
            public function request(string $operation, array $payload): array
            {
                return ['rates' => [['code' => 'x', 'amount_minor' => '12', 'currency' => 'usd']]];
            }
        });
        $service = new ShippingService(new TestShippingProviderRegistry($carrier), new InMemoryShippingEvidenceStore(), new InMemoryShippingShipmentStore());
        $rates = $service->quote(['origin' => ['country' => 'VN'], 'destination' => ['country' => 'DE'], 'packages' => [['weight_g' => 1]]]);
        self::assertSame(12, $rates[0]['amount_minor']);
        self::assertSame('USD', $rates[0]['currency']);
        $this->expectException(\InvalidArgumentException::class);
        $service->quote(['origin' => ['country' => 'VN'], 'destination' => ['country' => 'DE'], 'packages' => [['weight_g' => 0]]]);
    }

    public function testProductionDhlTransportRejectsMalformedHttpsUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        new DhlHttpTransport('https://', 'key', 'secret');
    }

    public function testShippingRejectsMalformedProviderRatesAndCurrencyMismatch(): void
    {
        $carrier = new DhlCarrier(new class implements \App\Modules\Shipping\Contracts\DhlTransport {
            public function request(string $operation, array $payload): array
            {
                return ['rates' => [['code' => 'x', 'amount_minor' => 10, 'currency' => 'USD']]];
            }
        });
        $service = new ShippingService(new TestShippingProviderRegistry($carrier), new InMemoryShippingEvidenceStore(), new InMemoryShippingShipmentStore());
        $this->expectException(\RuntimeException::class);
        $service->quote(['currency' => 'VND', 'origin' => ['country' => 'VN'], 'destination' => ['country' => 'DE'], 'packages' => [['weight_g' => 1]]]);
    }

    public function testShippingRejectsUnsupportedDestinationBeforeProviderCall(): void
    {
        $carrier = new DhlCarrier(new DhlSandboxTransport());
        $service = new ShippingService(new TestShippingProviderRegistry($carrier), new InMemoryShippingEvidenceStore(), new InMemoryShippingShipmentStore());
        $this->expectException(\InvalidArgumentException::class);
        $service->quote(['origin' => ['country' => 'VN'], 'destination' => ['country' => 'ZZ'], 'packages' => [['weight_g' => 1]]]);
    }

    public function testTrackingWebhookIsIdempotentAndDoesNotRegressDelivery(): void
    {
        $carrier = new DhlCarrier(new DhlSandboxTransport());
        $shipments = new InMemoryShippingShipmentStore();
        $service = new ShippingService(new TestShippingProviderRegistry($carrier), new InMemoryShippingEvidenceStore(), $shipments);
        $shipment = $service->create(['destination' => ['country' => 'DE']], 'webhook-key-1');
        $service->webhook(['event_id' => 'tracking-event-1', 'tracking_number' => $shipment['tracking_number'], 'status' => 'delivered']);
        $service->webhook(['event_id' => 'tracking-event-1', 'tracking_number' => $shipment['tracking_number'], 'status' => 'delivered']);
        $service->webhook(['event_id' => 'tracking-event-2', 'tracking_number' => $shipment['tracking_number'], 'status' => 'in_transit']);
        self::assertSame('delivered', $shipments->byTracking('dhl', (string) $shipment['tracking_number'])['state']);
    }

    public function testTrackingWebhookRejectsAChangedReplayPayload(): void
    {
        $carrier = new DhlCarrier(new DhlSandboxTransport());
        $service = new ShippingService(new TestShippingProviderRegistry($carrier), new InMemoryShippingEvidenceStore(), new InMemoryShippingShipmentStore());
        $service->webhook(['event_id' => 'replay-1', 'tracking_number' => 'JD0000000000', 'status' => 'in_transit']);
        $this->expectException(\InvalidArgumentException::class);
        $service->webhook(['event_id' => 'replay-1', 'tracking_number' => 'JD0000000000', 'status' => 'delivered']);
    }
}

final class InMemoryShippingEvidenceStore implements ShippingEvidenceStore
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    public function find(string $provider, string $operation, string $idempotencyKey): ?array
    {
        return $this->rows[$provider . ':' . $operation . ':' . $idempotencyKey] ?? null;
    }

    public function record(array $data): array
    {
        $key = $data['provider'] . ':' . $data['operation'] . ':' . $data['idempotency_key'];
        return $this->rows[$key] = $data;
    }
}

final class TestShippingProviderRegistry implements ShippingProviderRegistry
{
    public function __construct(private readonly DhlCarrier $carrier) {}
    public function carrier(string $provider): Carrier { return $this->carrier; }
    public function rates(string $provider): RateProvider { return $this->carrier; }
}

final class InMemoryShippingShipmentStore implements ShippingShipmentStore
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];
    public function byIdempotency(string $provider, string $key): ?array { return $this->rows[$provider . ':' . $key] ?? null; }
    public function create(array $data): array { $data['id'] = 'shipment-test'; return $this->rows[$data['provider'] . ':' . $data['idempotency_key']] = $data; }
    public function byTracking(string $provider, string $tracking): ?array
    {
        foreach ($this->rows as $row) if (($row['provider'] ?? null) === $provider && ($row['tracking_number'] ?? null) === $tracking) return $row;
        return null;
    }
    public function setState(string $id, string $state): void
    {
        foreach ($this->rows as $key => $row) if (($row['id'] ?? null) === $id) $this->rows[$key]['state'] = $state;
    }
    public function addTrackingEvent(array $data): void {}
}
