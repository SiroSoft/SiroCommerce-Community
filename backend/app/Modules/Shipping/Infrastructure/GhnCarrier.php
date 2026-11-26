<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Infrastructure;

use App\Modules\Shipping\Contracts\Carrier;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Modules\Shipping\Contracts\ShippingTransport;
use App\Support\ProviderEvidence;
use Siro\Core\Env;

final class GhnCarrier implements Carrier, RateProvider
{
    public function __construct(
        private readonly ?ShippingTransport $transport = null,
        private readonly ?string $shopId = null,
        private readonly ?string $token = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function rates(array $context): array
    {
        $config = $this->config();
        $response = $this->request('rates', $this->ratePayload($context, $config['shop_id']));
        return $this->normalizeRates($response);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function quote(array $context): array
    {
        return $this->rates($context);
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    public function create(array $shipment, string $idempotencyKey): array
    {
        $config = $this->config();
        $response = $this->request('create_shipment', $this->shipmentPayload($shipment, $config['shop_id'], $idempotencyKey));
        return $this->shipmentResponse($response, $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    public function createReturnLabel(array $shipment, string $idempotencyKey): array
    {
        $config = $this->config();
        $response = $this->request('create_return_label', $this->shipmentPayload($shipment, $config['shop_id'], $idempotencyKey));
        $result = $this->shipmentResponse($response, $idempotencyKey);
        $result['return_label'] = true;
        return $result;
    }

    /** @return array<string, mixed> */
    public function cancel(string $providerShipmentId, string $idempotencyKey): array
    {
        $this->config();
        $response = $this->request('cancel', [
            'order_code' => $providerShipmentId,
            'idempotency_key' => $idempotencyKey,
        ]);
        $cancelled = $this->cancelled($response);
        return [
            'cancelled' => $cancelled,
            'provider_reference' => $providerShipmentId,
            'provider_status' => $cancelled ? 'cancelled' : 'unknown',
        ];
    }

    /** @return array<string, mixed> */
    public function track(string $providerShipmentId): array
    {
        $this->config();
        $response = $this->request('track', ['order_code' => $providerShipmentId]);
        $data = $this->data($response);
        $rawStatus = $this->pickString($data, ['status', 'order_status', 'current_status'], 'unknown');
        return [
            'provider_reference' => $providerShipmentId,
            'tracking_number' => $this->pickString($data, ['tracking_number', 'order_code'], $providerShipmentId),
            'status' => $this->mapStatus($rawStatus),
            'provider_status' => $rawStatus,
        ];
    }

    /** @return array{shop_id: string, token: string} */
    private function config(): array
    {
        $shopId = trim($this->shopId ?? (string) Env::get('GHN_SHOP_ID', ''));
        $token = trim($this->token ?? (string) Env::get('GHN_TOKEN', ''));
        if ($shopId === '' || $token === '') {
            throw new \RuntimeException('GHN is not configured: GHN_TOKEN and GHN_SHOP_ID are required');
        }
        return ['shop_id' => $shopId, 'token' => $token];
    }

    private function transport(): ShippingTransport
    {
        return $this->transport ?? GhnHttpTransport::fromEnvironment();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $operation, array $payload): array
    {
        try {
            $response = $this->transport()->request($operation, $payload);
        } catch (\RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new \RuntimeException('GHN request failed', 0, $error);
        }
        if ($response === []) {
            throw new \RuntimeException('GHN returned an invalid response');
        }
        return $this->redact($response);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function ratePayload(array $context, string $shopId): array
    {
        return [
            'shop_id' => $shopId,
            'from_district_id' => $this->addressInt($context, 'origin', 'district_id'),
            'from_ward_code' => $this->addressString($context, 'origin', 'ward_code'),
            'from_province_id' => $this->addressInt($context, 'origin', 'province_id'),
            'to_district_id' => $this->addressInt($context, 'destination', 'district_id'),
            'to_ward_code' => $this->addressString($context, 'destination', 'ward_code'),
            'to_province_id' => $this->addressInt($context, 'destination', 'province_id'),
            'weight' => $this->weight($context),
            'length' => $this->contextInt($context, 'length', 0),
            'width' => $this->contextInt($context, 'width', 0),
            'height' => $this->contextInt($context, 'height', 0),
            'service_type_id' => $this->contextInt($context, 'service_type_id', 2),
            'insurance_value' => $this->contextInt($context, 'insurance_value', 0),
            'coupon' => $this->contextString($context, 'coupon', ''),
        ];
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    private function shipmentPayload(array $shipment, string $shopId, string $idempotencyKey): array
    {
        $destination = is_array($shipment['destination'] ?? null) ? $shipment['destination'] : [];
        return [
            'shop_id' => $shopId,
            'client_order_code' => $idempotencyKey,
            'idempotency_key' => $idempotencyKey,
            'to_name' => $this->pickString($destination, ['name', 'contact_name', 'recipient_name']),
            'to_phone' => $this->pickString($destination, ['phone', 'contact_phone', 'recipient_phone']),
            'to_address' => $this->pickString($destination, ['address', 'address_line_1', 'line1']),
            'to_ward_code' => $this->pickString($destination, ['ward_code', 'ward']),
            'to_district_id' => $this->pickInt($destination, ['district_id', 'district']),
            'to_province_id' => $this->pickInt($destination, ['province_id', 'province']),
            'weight' => $this->shipmentWeight($shipment),
            'length' => $this->shipmentInt($shipment, 'length'),
            'width' => $this->shipmentInt($shipment, 'width'),
            'height' => $this->shipmentInt($shipment, 'height'),
            'service_type_id' => $this->shipmentInt($shipment, 'service_type_id'),
            'payment_type_id' => $this->shipmentInt($shipment, 'payment_type_id'),
            'required_note' => $this->contextString($shipment, 'required_note', ''),
            'note' => $this->contextString($shipment, 'note', ''),
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function shipmentResponse(array $response, string $idempotencyKey): array
    {
        $data = $this->data($response);
        $reference = $this->pickString($data, ['order_code', 'orderCode', 'provider_reference', 'provider_ref']);
        if ($reference === '' && $this->pickString($response, ['order_code', 'orderCode']) !== '') {
            $data = $response;
            $reference = $this->pickString($data, ['order_code', 'orderCode', 'provider_reference', 'provider_ref']);
        }
        if ($reference === '') {
            throw new \RuntimeException('GHN returned no shipment reference');
        }
        return [
            'provider_reference' => $reference,
            'tracking_number' => $this->pickString($data, ['tracking_number', 'order_code', 'orderCode'], $reference),
            'status' => 'label_created',
            'provider_status' => $this->pickString($data, ['status'], 'label_created'),
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRates(array $response): array
    {
        $rates = $response['rates'] ?? null;
        if (!is_array($rates)) {
            $candidate = $response['data'] ?? null;
            if (is_array($candidate)) {
                $rates = array_is_list($candidate) ? $candidate : [$candidate];
            } elseif (array_key_exists('total', $response) || array_key_exists('service_fee', $response)) {
                $rates = [$response];
            } else {
                $rates = [];
            }
        }
        $normalized = [];
        foreach ($rates as $rate) {
            if (!is_array($rate)) continue;
            $amount = $this->pickNumber($rate, ['amount_minor', 'total', 'service_fee']);
            if ($amount === null) continue;
            [$min, $max] = $this->eta($rate);
            $normalized[] = [
                'code' => $this->pickString($rate, ['code', 'service_code', 'service_id'], 'ghn-standard'),
                'name' => $this->pickString($rate, ['name', 'service_name'], 'GHN Standard'),
                'amount_minor' => $amount,
                'currency' => $this->currency($rate),
                'eta_days_min' => $min,
                'eta_days_max' => $max,
            ];
        }
        return $normalized;
    }

    /**
     * @param array<mixed, mixed> $rate
     * @return array{0: int, 1: int}
     */
    private function eta(array $rate): array
    {
        $min = $this->pickNullableInt($rate, ['eta_days_min']);
        $max = $this->pickNullableInt($rate, ['eta_days_max']);
        $estimated = $rate['estimated_days'] ?? null;
        if (is_array($estimated)) {
            $min ??= $this->pickNullableInt($estimated, ['min', 'min_days']);
            $max ??= $this->pickNullableInt($estimated, ['max', 'max_days']);
        } elseif ($estimated !== null) {
            $value = $this->positiveInt($estimated);
            if ($value !== null) $min ??= $value;
            if ($value !== null) $max ??= $value;
        }
        if ($min === null && $max === null) {
            $leadtime = $rate['leadtime'] ?? null;
            $seconds = $this->positiveInt($leadtime);
            if ($seconds !== null) {
                $days = max(1, (int) ceil($seconds / 86400));
                $min = $days;
                $max = $days;
            }
        }
        if ($min === null && $max === null) return [0, 0];
        $min ??= $max;
        $max ??= $min;
        return $min <= $max ? [$min, $max] : [$max, $min];
    }

    /** @param array<string, mixed> $response */
    private function cancelled(array $response): bool
    {
        if (is_bool($response['cancelled'] ?? null)) return $response['cancelled'];
        $data = $response['data'] ?? null;
        if (is_bool($data)) return $data;
        if (is_array($data) && is_bool($data['cancelled'] ?? null)) return $data['cancelled'];
        $code = $response['code'] ?? null;
        return is_numeric($code) && (int) $code === 200;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function data(array $response): array
    {
        $data = $response['data'] ?? null;
        if (!is_array($data)) return $response;
        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($data as $key => $value) if (is_string($key)) $result[$key] = $value;
        return $result;
    }

    private function mapStatus(string $status): string
    {
        return [
            'ready_to_pick' => 'label_created',
            'picking' => 'picked_up',
            'money_collect_picking' => 'picked_up',
            'picked' => 'picked_up',
            'storing' => 'in_transit',
            'transporting' => 'in_transit',
            'sorting' => 'in_transit',
            'delivering' => 'out_for_delivery',
            'delivered' => 'delivered',
            'cancel' => 'cancelled',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
            'return' => 'returned',
            'returned' => 'returned',
            'waiting_to_return' => 'returned',
            'lost' => 'exception',
            'damage' => 'exception',
            'exception' => 'exception',
        ][strtolower(trim($status))] ?? 'unknown';
    }

    /** @param array<string, mixed> $context */
    private function addressInt(array $context, string $side, string $key): int
    {
        $address = $context[$side] ?? null;
        return is_array($address) ? $this->pickInt($address, [$key]) : 0;
    }

    /** @param array<string, mixed> $context */
    private function addressString(array $context, string $side, string $key): string
    {
        $address = $context[$side] ?? null;
        return is_array($address) ? $this->pickString($address, [$key]) : '';
    }

    /** @param array<string, mixed> $context */
    private function weight(array $context): int
    {
        $weight = 0;
        $packages = $context['packages'] ?? null;
        if (is_array($packages)) {
            foreach ($packages as $package) {
                if (is_array($package)) $weight += $this->pickInt($package, ['weight_g', 'weight']);
            }
        }
        if ($weight <= 0) $weight = $this->contextInt($context, 'weight_g', 0);
        return max(1, $weight);
    }

    /** @param array<string, mixed> $shipment */
    private function shipmentWeight(array $shipment): int
    {
        $weight = 0;
        $packages = $shipment['packages'] ?? null;
        if (is_array($packages)) {
            foreach ($packages as $package) {
                if (is_array($package)) $weight += $this->pickInt($package, ['weight_g', 'weight']);
            }
        }
        if ($weight <= 0) $weight = $this->shipmentInt($shipment, 'weight_g');
        return max(1, $weight);
    }

    /** @param array<string, mixed> $shipment */
    private function shipmentInt(array $shipment, string $key): int
    {
        return $this->contextInt($shipment, $key, 0);
    }

    /** @param array<string, mixed> $context */
    private function contextInt(array $context, string $key, int $default): int
    {
        $value = $context[$key] ?? null;
        $parsed = $this->positiveInt($value);
        return $parsed ?? $default;
    }

    /** @param array<string, mixed> $context */
    private function contextString(array $context, string $key, string $default): string
    {
        $value = $context[$key] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) return max(0, $value);
        if (is_float($value) && is_finite($value)) return max(0, (int) $value);
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) return (int) trim($value);
        return null;
    }

    /**
     * @param array<mixed, mixed> $row
     * @param array<int, string> $keys
     */
    private function pickInt(array $row, array $keys): int
    {
        return $this->pickNullableInt($row, $keys) ?? 0;
    }

    /**
     * @param array<mixed, mixed> $row
     * @param array<int, string> $keys
     */
    private function pickNullableInt(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) continue;
            $parsed = $this->positiveInt($row[$key]);
            if ($parsed !== null) return $parsed;
        }
        return null;
    }

    /**
     * @param array<mixed, mixed> $row
     * @param array<int, string> $keys
     */
    private function pickNumber(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_int($value)) return max(0, $value);
            if (is_float($value) && is_finite($value)) return max(0, (int) $value);
            if (is_string($value) && is_numeric(trim($value))) return max(0, (int) trim($value));
        }
        return null;
    }

    /**
     * @param array<mixed, mixed> $row
     * @param array<int, string> $keys
     */
    private function pickString(array $row, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && trim($value) !== '') return trim($value);
            if (is_int($value) || (is_float($value) && is_finite($value))) return (string) $value;
        }
        return $default;
    }

    /** @param array<mixed, mixed> $row */
    private function currency(array $row): string
    {
        $currency = strtoupper($this->pickString($row, ['currency'], 'VND'));
        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'VND';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        $safe = ProviderEvidence::redact($data);
        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($safe as $key => $value) if (is_string($key)) $result[$key] = $value;
        return $result;
    }
}
