<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Platform\Infrastructure\ConfiguredShippingProviderRegistry;
use App\Modules\Platform\Infrastructure\DhlCarrier;
use App\Modules\Platform\Infrastructure\DhlSandboxTransport;
use App\Modules\Shipping\Contracts\ShippingTransport;
use App\Modules\Shipping\Infrastructure\GhnCarrier;
use App\Modules\Shipping\Infrastructure\ViettelPostCarrier;
use PHPUnit\Framework\TestCase;

final class VnShippingCarrierTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['GHN_TOKEN', 'GHN_SHOP_ID', 'GHN_ENV', 'VIETTELPOST_API_URL', 'VIETTELPOST_USERNAME', 'VIETTELPOST_PASSWORD'] as $key) {
            $this->env[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testGhnRatesMapToNormalizedContract(): void
    {
        $transport = new FakeShippingTransport([
            'rates' => ['code' => 200, 'data' => ['total' => 32000, 'service_id' => 53321, 'service_name' => 'GHN Standard']],
        ]);
        $carrier = new GhnCarrier($transport, 'shop-1', 'token-1');

        $rates = $carrier->rates([
            'origin' => ['province_id' => 202, 'district_id' => 1442, 'ward_code' => '20101'],
            'destination' => ['province_id' => 201, 'district_id' => 1444, 'ward_code' => '20202'],
            'packages' => [['weight_g' => 700], ['weight_g' => 300]],
        ]);

        self::assertSame('53321', $rates[0]['code']);
        self::assertSame('GHN Standard', $rates[0]['name']);
        self::assertSame(32000, $rates[0]['amount_minor']);
        self::assertSame('VND', $rates[0]['currency']);
        self::assertSame(0, $rates[0]['eta_days_min']);
        self::assertSame(0, $rates[0]['eta_days_max']);
        self::assertSame('rates', $transport->calls[0]['operation']);
        self::assertSame('shop-1', $transport->calls[0]['payload']['shop_id']);
        self::assertSame(1000, $transport->calls[0]['payload']['weight']);
        self::assertSame(1442, $transport->calls[0]['payload']['from_district_id']);
        self::assertSame(1444, $transport->calls[0]['payload']['to_district_id']);
    }

    public function testGhnCreateCancelAndTrackMapProviderIdentifiers(): void
    {
        $transport = new FakeShippingTransport([
            'create_shipment' => ['code' => 200, 'data' => ['order_code' => 'GHN123', 'status' => 'ready_to_pick']],
            'cancel' => ['code' => 200, 'data' => true],
            'track' => ['data' => ['order_code' => 'GHN123', 'status' => 'delivering']],
        ]);
        $carrier = new GhnCarrier($transport, 'shop-1', 'token-1');

        $created = $carrier->create(['destination' => ['name' => 'A', 'district_id' => 1], 'packages' => [['weight_g' => 500]]], 'idem-ghn');
        self::assertSame('GHN123', $created['provider_reference']);
        self::assertSame('GHN123', $created['tracking_number']);
        self::assertSame('label_created', $created['status']);
        self::assertSame('idem-ghn', $transport->calls[0]['payload']['client_order_code']);

        $cancelled = $carrier->cancel('GHN123', 'cancel-ghn');
        self::assertTrue($cancelled['cancelled']);
        self::assertSame('GHN123', $cancelled['provider_reference']);

        $tracking = $carrier->track('GHN123');
        self::assertSame('out_for_delivery', $tracking['status']);
        self::assertSame('delivering', $tracking['provider_status']);
        self::assertSame('GHN123', $tracking['tracking_number']);
    }

    public function testViettelPostRatesCreateCancelAndTrackMapProviderIdentifiers(): void
    {
        $transport = new FakeShippingTransport([
            'rates' => ['status' => 200, 'data' => [['MA_DV' => 'VTK', 'TEN_DV' => 'Viettel Post', 'GIA_CUOC' => '45000', 'THOI_GIAN' => '2-3 ngày']]],
            'create_shipment' => ['status' => 200, 'data' => ['ORDER_NUMBER' => 'VT123', 'ORDER_REFERENCE' => 'idem-vt']],
            'cancel' => ['status' => 200, 'error' => false],
            'track' => ['status' => 200, 'data' => [['ORDER_NUMBER' => 'VT123', 'STATUS' => 201]]],
        ]);
        $carrier = new ViettelPostCarrier($transport, 'https://partner.viettelpost.vn', 'user', 'pass');

        $rates = $carrier->rates([
            'origin' => ['province_id' => 10, 'district_id' => 20],
            'destination' => ['province_id' => 30, 'district_id' => 40],
            'packages' => [['weight_g' => 1000]],
        ]);
        self::assertSame('VTK', $rates[0]['code']);
        self::assertSame('Viettel Post', $rates[0]['name']);
        self::assertSame(45000, $rates[0]['amount_minor']);
        self::assertSame('VND', $rates[0]['currency']);
        self::assertSame(2, $rates[0]['eta_days_min']);
        self::assertSame(3, $rates[0]['eta_days_max']);
        self::assertSame(1000, $transport->calls[0]['payload']['PRODUCT_WEIGHT']);

        $created = $carrier->create(['destination' => ['name' => 'B'], 'packages' => [['weight_g' => 1000]]], 'idem-vt');
        self::assertSame('VT123', $created['provider_reference']);
        self::assertSame('VT123', $created['tracking_number']);
        self::assertSame('idem-vt', $transport->calls[1]['payload']['ORDER_REFERENCE']);

        self::assertTrue($carrier->cancel('VT123', 'cancel-vt')['cancelled']);
        self::assertSame('delivered', $carrier->track('VT123')['status']);
    }

    public function testMissingCarrierConfigurationThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        (new GhnCarrier())->rates(['packages' => [['weight_g' => 100]]]);
    }

    public function testMissingViettelPostConfigurationThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ViettelPostCarrier())->rates(['packages' => [['weight_g' => 100]]]);
    }

    public function testRegistrySelectsConfiguredCarrierAndRejectsUnknown(): void
    {
        $ghn = new GhnCarrier(new FakeShippingTransport([]), 'shop-1', 'token-1');
        $viettelPost = new ViettelPostCarrier(new FakeShippingTransport([]), 'https://partner.viettelpost.vn', 'user', 'pass');
        $registry = new ConfiguredShippingProviderRegistry(new DhlCarrier(new DhlSandboxTransport()), $ghn, $viettelPost);

        self::assertInstanceOf(DhlCarrier::class, $registry->carrier('dhl'));
        self::assertSame($ghn, $registry->carrier('ghn'));
        self::assertSame($ghn, $registry->rates('ghn'));
        self::assertSame($viettelPost, $registry->rates('viettelpost'));
        $this->expectException(\InvalidArgumentException::class);
        $registry->carrier('ups');
    }
}

final class FakeShippingTransport implements ShippingTransport
{
    /** @var array<int, array{operation: string, payload: array<string, mixed>}> */
    public array $calls = [];

    /** @param array<string, array<string, mixed>> $responses */
    public function __construct(private readonly array $responses = [])
    {
    }

    public function request(string $operation, array $payload): array
    {
        $this->calls[] = ['operation' => $operation, 'payload' => $payload];
        return $this->responses[$operation] ?? [];
    }
}
