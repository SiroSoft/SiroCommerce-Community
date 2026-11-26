<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductVariant;
use Siro\Core\Container;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ShippingEstimateTest extends TestCase
{
    private Router $router;
    /** @var array<string, array{real: string|false, env: mixed}> */
    private array $envSnapshots = [];

    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(null);
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshots as $key => $snapshot) {
            $snapshot['real'] === false ? putenv($key) : putenv($key . '=' . $snapshot['real']);
            if ($snapshot['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $snapshot['env'];
            }
        }
        $this->envSnapshots = [];
        parent::tearDown();
    }

    public function testNativeDomesticReturnsStandardAndExpressFeesWithEta(): void
    {
        $this->nativeEnv();
        $this->seedVariant('v-est-dom', 500);

        $result = $this->call('POST', '/api/storefront/shipping-estimate', [
            'variant_id' => 'v-est-dom',
            'destination' => ['country' => 'VN', 'region' => 'HN', 'postal_code' => '100000'],
        ], $this->scope());

        self::assertSame(200, $result['status']);
        $data = $result['json']['data'];
        self::assertTrue($data['estimated']);
        self::assertSame('native', $data['provider']);
        self::assertSame('VND', $data['currency']);
        self::assertSame(['country' => 'VN', 'region' => 'HN', 'postal_code' => '100000'], $data['origin']);
        self::assertSame([['weight_g' => 500, 'qty' => 1]], $data['packages']);
        self::assertSame(1500000, $data['free_shipping_threshold_minor']);
        self::assertSame('estimated', $data['disclaimer']);
        self::assertNull($data['fallback_reason']);

        $methods = $this->methodsByCode($data['methods']);
        self::assertCount(2, $data['methods']);
        self::assertSame(30000, $methods['standard']['amount_minor']);
        self::assertSame('VND', $methods['standard']['currency']);
        self::assertSame(2, $methods['standard']['eta_days_min']);
        self::assertSame(4, $methods['standard']['eta_days_max']);
        self::assertSame(60000, $methods['express']['amount_minor']);
        self::assertSame(1, $methods['express']['eta_days_min']);
        self::assertSame(2, $methods['express']['eta_days_max']);
    }

    public function testInternationalUsesMultiplierAndOnlyStandard(): void
    {
        $this->nativeEnv();
        $this->seedVariant('v-est-int', 500);

        $result = $this->call('POST', '/api/storefront/shipping-estimate', [
            'variant_id' => 'v-est-int',
            'destination' => ['country' => 'DE', 'region' => 'Berlin', 'postal_code' => '10115'],
        ], $this->scope('USD'));

        self::assertSame(200, $result['status']);
        $data = $result['json']['data'];
        self::assertSame('USD', $data['currency']);
        self::assertCount(1, $data['methods']);
        self::assertSame('standard', $data['methods'][0]['code']);
        self::assertSame(90000, $data['methods'][0]['amount_minor']);
        self::assertSame('USD', $data['methods'][0]['currency']);
        self::assertSame(5, $data['methods'][0]['eta_days_min']);
        self::assertSame(10, $data['methods'][0]['eta_days_max']);
    }

    public function testQuantityMultipliesPackageWeight(): void
    {
        $this->nativeEnv();
        $this->seedVariant('v-est-qty', 500);

        $result = $this->call('POST', '/api/storefront/shipping-estimate', [
            'variant_id' => 'v-est-qty',
            'qty' => 3,
            'destination' => ['country' => 'VN', 'region' => 'HN'],
        ], $this->scope());

        self::assertSame(200, $result['status']);
        self::assertSame([['weight_g' => 1500, 'qty' => 3]], $result['json']['data']['packages']);
    }

    public function testZeroVariantWeightFallsBackToDefaultPackageWeight(): void
    {
        $this->nativeEnv();
        $this->seedVariant('v-est-zero', 0);

        $result = $this->call('POST', '/api/storefront/shipping-estimate', [
            'variant_id' => 'v-est-zero',
            'destination' => ['country' => 'VN', 'region' => 'HN'],
        ], $this->scope());

        self::assertSame(200, $result['status']);
        self::assertSame([['weight_g' => 300, 'qty' => 1]], $result['json']['data']['packages']);
    }

    public function testInvalidQuantityCountryAndVariantAreRejected(): void
    {
        $this->nativeEnv();
        $this->seedVariant('v-est-bad', 500);

        foreach ([0, 100] as $qty) {
            $result = $this->call('POST', '/api/storefront/shipping-estimate', [
                'variant_id' => 'v-est-bad',
                'qty' => $qty,
                'destination' => ['country' => 'VN', 'region' => 'HN'],
            ], $this->scope());
            self::assertSame(422, $result['status'], 'qty ' . $qty);
        }

        $badCountry = $this->call('POST', '/api/storefront/shipping-estimate', [
            'variant_id' => 'v-est-bad',
            'destination' => ['country' => 'XX', 'region' => 'HN'],
        ], $this->scope());
        self::assertSame(422, $badCountry['status']);

        $missing = $this->call('POST', '/api/storefront/shipping-estimate', [
            'variant_id' => 'v-est-missing',
            'destination' => ['country' => 'VN', 'region' => 'HN'],
        ], $this->scope());
        self::assertSame(422, $missing['status']);
    }

    public function testProviderFailureFallsBackToNativeWithReason(): void
    {
        $this->nativeEnv();
        $this->env([
            'SHIPPING_PROVIDER' => 'dhl',
            'APP_ENV' => 'testing',
            'DHL_MODE' => 'api',
            'DHL_API_URL' => '',
            'DHL_API_KEY' => '',
            'DHL_API_SECRET' => '',
        ]);
        $this->seedVariant('v-est-fail', 500);

        $result = $this->call('POST', '/api/storefront/shipping-estimate', [
            'variant_id' => 'v-est-fail',
            'destination' => ['country' => 'VN', 'region' => 'HN'],
        ], $this->scope());

        self::assertSame(200, $result['status']);
        $data = $result['json']['data'];
        self::assertTrue($data['estimated']);
        self::assertSame('native', $data['provider']);
        self::assertSame('provider_unavailable', $data['fallback_reason']);
        self::assertSame('estimated', $data['disclaimer']);
        self::assertSame(['standard', 'express'], array_column($data['methods'], 'code'));
    }

    public function testFreeShippingThresholdIsNullWhenDisabled(): void
    {
        $this->nativeEnv(['SHIPPING_FREE_OVER_MINOR' => '0']);
        $this->seedVariant('v-est-free', 500);

        $result = $this->call('POST', '/api/storefront/shipping-estimate', [
            'variant_id' => 'v-est-free',
            'destination' => ['country' => 'VN', 'region' => 'HN'],
        ], $this->scope());

        self::assertSame(200, $result['status']);
        self::assertNull($result['json']['data']['free_shipping_threshold_minor']);
    }

    /** @param array<string, string> $overrides */
    private function nativeEnv(array $overrides = []): void
    {
        $this->env($overrides + [
            'SHIPPING_PROVIDER' => 'native',
            'SHIPPING_DEFAULT_WEIGHT_G' => '300',
            'SHIPPING_ORIGIN_COUNTRY' => 'VN',
            'SHIPPING_ORIGIN_REGION' => 'HN',
            'SHIPPING_ORIGIN_POSTAL_CODE' => '100000',
            'SHIPPING_FLAT_RATE_MINOR' => '30000',
            'SHIPPING_EXPRESS_RATE_MINOR' => '60000',
            'SHIPPING_ETA_MIN_DAYS' => '2',
            'SHIPPING_ETA_MAX_DAYS' => '4',
            'SHIPPING_INTL_MULTIPLIER' => '3',
            'SHIPPING_FREE_OVER_MINOR' => '1500000',
        ]);
    }

    /** @param array<string, string> $values */
    private function env(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->envSnapshots)) {
                $this->envSnapshots[$key] = ['real' => getenv($key), 'env' => $_ENV[$key] ?? null];
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    private function seedVariant(string $id, int $weightG, string $status = 'active'): void
    {
        ProductVariant::create([
            'id' => $id,
            'product_id' => 'p-' . $id,
            'sku' => 'SKU-' . $id,
            'price_minor' => 15000,
            'weight_g' => $weightG,
            'currency' => 'VND',
            'status' => $status,
        ]);
    }

    /** @return array<string, string> */
    private function scope(string $currency = 'VND'): array
    {
        return ['X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => $currency];
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     * @return array<string, array<string, mixed>>
     */
    private function methodsByCode(array $methods): array
    {
        $out = [];
        foreach ($methods as $method) {
            $code = is_string($method['code'] ?? null) ? $method['code'] : '';
            if ($code !== '') $out[$code] = $method;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, json: mixed}
     */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        /** @var array{status: int, json: mixed} $out */
        $out = ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
        return $out;
    }
}
