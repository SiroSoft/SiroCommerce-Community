<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Application;

use App\Models\ProductVariant;
use App\Modules\Commerce\Domain\RegionalRules;
use App\Modules\Platform\Infrastructure\ConfiguredShippingProviderRegistry;
use App\Modules\Platform\Infrastructure\DhlCarrier;
use App\Modules\Platform\Infrastructure\DhlHttpTransport;
use App\Modules\Platform\Infrastructure\DhlSandboxTransport;
use App\Modules\Platform\Infrastructure\ShippingEvidenceStoreAdapter;
use App\Modules\Platform\Infrastructure\ShippingShipmentStoreAdapter;
use App\Modules\Shipping\Contracts\DhlTransport;
use App\Modules\Shipping\Infrastructure\GhnCarrier;
use App\Modules\Shipping\Infrastructure\ViettelPostCarrier;
use App\Support\Arr;
use App\Support\CommerceScope;
use Siro\Core\Env;
use Siro\Core\Logger;

/** Deterministic PDP shipping estimate with a truthful native fallback. */
final class ShippingEstimateService
{
    public function __construct(private readonly ?ShippingService $shipping = null) {}

    /**
     * @param array<string, mixed> $destination
     * @return array<string, mixed>
     */
    public function estimate(string $variantId, int $qty, array $destination, CommerceScope $scope): array
    {
        $variantId = trim($variantId);
        if ($variantId === '' || strlen($variantId) > 64) throw new \InvalidArgumentException('Variant is required');
        if ($qty < 1 || $qty > 99) throw new \InvalidArgumentException('Quantity must be between 1 and 99');

        $destinationAddress = $this->destination($destination);
        $variant = $this->activeVariant($variantId);
        $unitWeight = max($this->intEnv('SHIPPING_DEFAULT_WEIGHT_G', 300, 1, 1000000), Arr::int($variant, 'weight_g', 0));
        $packageWeight = $unitWeight * $qty;
        if ($packageWeight <= 0) throw new \InvalidArgumentException('Shipping estimate could not be completed');

        $packages = [['weight_g' => $packageWeight, 'qty' => $qty]];
        $origin = $this->origin();
        $currency = $scope->currency;
        $freeThreshold = $this->freeThreshold();
        $provider = strtolower(trim((string) Env::get('SHIPPING_PROVIDER', 'native')));
        $fallbackReason = null;

        if (in_array($provider, ['dhl', 'ghn', 'viettelpost'], true)) {
            try {
                $methods = $this->mapMethods($this->liveRates($provider, $origin, $destinationAddress, $packages, $scope, $currency));
                if ($methods === []) throw new \RuntimeException('No shipping rate available');
                $result = $this->response(false, $provider, (string) $methods[0]['currency'], $origin, $packages, $freeThreshold, $methods, 'live', null);
                $this->audit($variantId, $destinationAddress['country'], count($methods), $provider, false, null);
                return $result;
            } catch (\Throwable) {
                $fallbackReason = 'provider_unavailable';
            }
        }

        $methods = $this->nativeMethods($origin, $destinationAddress, $currency);
        $result = $this->response(true, 'native', $currency, $origin, $packages, $freeThreshold, $methods, 'estimated', $fallbackReason);
        $this->audit($variantId, $destinationAddress['country'], count($methods), 'native', true, $fallbackReason);
        return $result;
    }

    /**
     * @param array<string, mixed> $destination
     * @return array<string, string>
     */
    private function destination(array $destination): array
    {
        $address = Arr::row(RegionalRules::address($destination, 'destination'));
        $region = Arr::str($address, 'region');
        if ($region === '' || mb_strlen($region) > 64) throw new \InvalidArgumentException('Invalid destination region');
        $postal = Arr::str($address, 'postal_code');
        if (mb_strlen($postal) > 16) throw new \InvalidArgumentException('Invalid destination postal code');
        return [
            'country' => Arr::str($address, 'country'),
            'region' => $region,
            'postal_code' => $postal,
        ];
    }

    /** @return array<string, mixed> */
    private function activeVariant(string $variantId): array
    {
        $variant = ProductVariant::query()->where('id', $variantId)->first();
        if ($variant === null) throw new \InvalidArgumentException('Variant is not available');
        $row = Arr::row($variant);
        if (strtolower(Arr::str($row, 'status', 'active')) !== 'active') throw new \InvalidArgumentException('Variant is not available');
        return $row;
    }

    /** @return array<string, string> */
    private function origin(): array
    {
        $origin = [
            'country' => strtoupper($this->envString('SHIPPING_ORIGIN_COUNTRY', 'VN')),
            'region' => $this->envString('SHIPPING_ORIGIN_REGION', 'HN'),
            'postal_code' => $this->envString('SHIPPING_ORIGIN_POSTAL_CODE', '100000'),
        ];
        try {
            return RegionalRules::address($origin, 'origin');
        } catch (\Throwable) {
            return RegionalRules::address(['country' => 'VN', 'region' => 'HN', 'postal_code' => '100000'], 'origin');
        }
    }

    /**
     * @param array<string, string> $origin
     * @param array<string, string> $destination
     * @param array<int, array{weight_g: int, qty: int}> $packages
     * @return array<int, array<string, mixed>>
     */
    private function liveRates(string $provider, array $origin, array $destination, array $packages, CommerceScope $scope, string $currency): array
    {
        return $this->shippingService()->quote([
            'origin' => $origin,
            'destination' => $destination,
            'packages' => $packages,
            'scope' => $scope->metadata(),
            'currency' => $currency,
            'provider' => $provider,
        ]);
    }

    private function shippingService(): ShippingService
    {
        if ($this->shipping !== null) return $this->shipping;
        return new ShippingService(
            new ConfiguredShippingProviderRegistry(
                new DhlCarrier($this->dhlTransport()),
                new GhnCarrier(),
                new ViettelPostCarrier(),
            ),
            new ShippingEvidenceStoreAdapter(),
            new ShippingShipmentStoreAdapter(),
        );
    }

    private function dhlTransport(): DhlTransport
    {
        $environment = strtolower((string) Env::get('APP_ENV', 'local'));
        $mode = strtolower((string) Env::get('DHL_MODE', $environment === 'production' ? 'api' : 'sandbox'));
        if ($environment === 'production' && $mode !== 'api') throw new \RuntimeException('Sandbox DHL transport is forbidden in production');
        if ($mode === 'sandbox') return new DhlSandboxTransport();
        return new DhlHttpTransport(
            (string) Env::get('DHL_API_URL', ''),
            (string) Env::get('DHL_API_KEY', ''),
            (string) Env::get('DHL_API_SECRET', ''),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rates
     * @return array<int, array<string, mixed>>
     */
    private function mapMethods(array $rates): array
    {
        $methods = [];
        foreach ($rates as $rate) {
            if (!is_array($rate)) continue;
            $code = trim(is_string($rate['code'] ?? null) ? $rate['code'] : '');
            $amount = $rate['amount_minor'] ?? null;
            $currency = strtoupper(is_string($rate['currency'] ?? null) ? $rate['currency'] : '');
            if ($code === '' || !is_numeric($amount) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) continue;
            [$etaMin, $etaMax] = $this->rateEta($rate);
            $label = trim(is_string($rate['name'] ?? null) ? $rate['name'] : '');
            $methods[] = [
                'code' => $code,
                'label' => $label !== '' ? $label : $code,
                'amount_minor' => (int) $amount,
                'currency' => $currency,
                'eta_days_min' => $etaMin,
                'eta_days_max' => $etaMax,
            ];
        }
        return $methods;
    }

    /**
     * @param array<string, mixed> $rate
     * @return array{0: int, 1: int}
     */
    private function rateEta(array $rate): array
    {
        $min = $this->numericDay($rate['eta_days_min'] ?? null);
        $max = $this->numericDay($rate['eta_days_max'] ?? null);
        $estimated = $rate['estimated_days'] ?? null;
        if (is_array($estimated)) {
            $estimateMin = $this->numericDay($estimated['min'] ?? $estimated['min_days'] ?? ($estimated[0] ?? null));
            $estimateMax = $this->numericDay($estimated['max'] ?? $estimated['max_days'] ?? ($estimated[1] ?? null));
            $min ??= $estimateMin;
            $max ??= $estimateMax;
        } else {
            $estimate = $this->numericDay($estimated);
            if ($estimate !== null) {
                $min ??= $estimate;
                $max ??= $estimate;
            }
        }
        if ($min === null && $max === null) return [0, 0];
        $min ??= $max;
        $max ??= $min;
        return $min <= $max ? [$min, $max] : [$max, $min];
    }

    private function numericDay(mixed $value): ?int
    {
        if (is_int($value)) return max(0, min(365, $value));
        if (is_float($value) && is_finite($value)) return max(0, min(365, (int) $value));
        if (is_string($value) && preg_match('/^\d{1,3}$/', trim($value)) === 1) return max(0, min(365, (int) trim($value)));
        return null;
    }

    /**
     * @param array<string, string> $origin
     * @param array<string, string> $destination
     * @return array<int, array<string, mixed>>
     */
    private function nativeMethods(array $origin, array $destination, string $currency): array
    {
        $flat = $this->intEnv('SHIPPING_FLAT_RATE_MINOR', 30000, 0, 1000000000);
        if ($origin['country'] !== $destination['country']) {
            $multiplier = $this->intEnv('SHIPPING_INTL_MULTIPLIER', 3, 1, 20);
            return [[
                'code' => 'standard',
                'label' => 'Standard',
                'amount_minor' => $flat * $multiplier,
                'currency' => $currency,
                'eta_days_min' => 5,
                'eta_days_max' => 10,
            ]];
        }

        [$etaMin, $etaMax] = $this->etaPair();
        return [
            [
                'code' => 'standard',
                'label' => 'Standard',
                'amount_minor' => $flat,
                'currency' => $currency,
                'eta_days_min' => $etaMin,
                'eta_days_max' => $etaMax,
            ],
            [
                'code' => 'express',
                'label' => 'Express',
                'amount_minor' => $this->intEnv('SHIPPING_EXPRESS_RATE_MINOR', 60000, 0, 1000000000),
                'currency' => $currency,
                'eta_days_min' => 1,
                'eta_days_max' => 2,
            ],
        ];
    }

    /** @return array{0: int, 1: int} */
    private function etaPair(): array
    {
        $min = $this->intEnv('SHIPPING_ETA_MIN_DAYS', 2, 0, 365);
        $max = $this->intEnv('SHIPPING_ETA_MAX_DAYS', 4, 0, 365);
        return $min <= $max ? [$min, $max] : [$max, $min];
    }

    private function freeThreshold(): ?int
    {
        $value = $this->intEnv('SHIPPING_FREE_OVER_MINOR', 1500000, 0, 1000000000);
        return $value > 0 ? $value : null;
    }

    private function envString(string $key, string $default): string
    {
        $value = Env::get($key);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function intEnv(string $key, int $default, int $min, int $max): int
    {
        $value = Env::get($key);
        if (!is_string($value) || trim($value) === '' || !is_numeric($value)) return $default;
        return max($min, min($max, (int) $value));
    }

    /**
     * @param array<string, string> $origin
     * @param array<int, array{weight_g: int, qty: int}> $packages
     * @param array<int, array<string, mixed>> $methods
     * @return array<string, mixed>
     */
    private function response(bool $estimated, string $provider, string $currency, array $origin, array $packages, ?int $freeThreshold, array $methods, string $disclaimer, ?string $fallbackReason): array
    {
        return [
            'estimated' => $estimated,
            'provider' => $provider,
            'currency' => $currency,
            'origin' => $origin,
            'packages' => $packages,
            'free_shipping_threshold_minor' => $freeThreshold,
            'methods' => $methods,
            'disclaimer' => $disclaimer,
            'fallback_reason' => $fallbackReason,
        ];
    }

    private function audit(string $variantId, string $country, int $methodCount, string $provider, bool $estimated, ?string $fallbackReason): void
    {
        $context = [
            'variant_id' => $variantId,
            'country' => $country,
            'methods' => $methodCount,
            'provider' => $provider,
            'estimated' => $estimated,
            'fallback_reason' => $fallbackReason,
        ];
        try {
            if (method_exists(Logger::class, 'info')) {
                Logger::info('shipping.estimate', $context);
                return;
            }
            Logger::debug('shipping.estimate', $context);
        } catch (\Throwable) {
        }
    }
}
