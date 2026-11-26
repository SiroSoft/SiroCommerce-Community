<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Infrastructure;

use App\Modules\Shipping\Contracts\Carrier;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Modules\Shipping\Contracts\ShippingTransport;
use App\Support\ProviderEvidence;
use Siro\Core\Env;

final class ViettelPostCarrier implements Carrier, RateProvider
{
    public function __construct(
        private readonly ?ShippingTransport $transport = null,
        private readonly ?string $apiUrl = null,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function rates(array $context): array
    {
        $this->config();
        $response = $this->request('rates', $this->ratePayload($context));
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
        $this->config();
        $response = $this->request('create_shipment', $this->shipmentPayload($shipment, $idempotencyKey));
        return $this->shipmentResponse($response, $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    public function createReturnLabel(array $shipment, string $idempotencyKey): array
    {
        $this->config();
        $response = $this->request('create_return_label', $this->shipmentPayload($shipment, $idempotencyKey));
        $result = $this->shipmentResponse($response, $idempotencyKey);
        $result['return_label'] = true;
        return $result;
    }

    /** @return array<string, mixed> */
    public function cancel(string $providerShipmentId, string $idempotencyKey): array
    {
        $this->config();
        $response = $this->request('cancel', [
            'ORDER_NUMBER' => $providerShipmentId,
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
        $response = $this->request('track', ['ORDER_NUMBER' => $providerShipmentId]);
        $record = $this->trackingRecord($response);
        $rawStatus = $this->pickString($record, ['STATUS', 'status', 'ORDER_STATUS'], 'unknown');
        return [
            'provider_reference' => $providerShipmentId,
            'tracking_number' => $this->pickString($record, ['ORDER_NUMBER', 'tracking_number'], $providerShipmentId),
            'status' => $this->mapStatus($rawStatus),
            'provider_status' => $rawStatus,
        ];
    }

    /** @return array{api_url: string, username: string, password: string} */
    private function config(): array
    {
        $apiUrl = trim($this->apiUrl ?? (string) Env::get('VIETTELPOST_API_URL', ''));
        if ($apiUrl === '') $apiUrl = 'https://partner.viettelpost.vn';
        $username = trim($this->username ?? (string) Env::get('VIETTELPOST_USERNAME', ''));
        $password = trim($this->password ?? (string) Env::get('VIETTELPOST_PASSWORD', ''));
        if ($username === '' || $password === '') {
            throw new \RuntimeException('ViettelPost is not configured: VIETTELPOST_USERNAME and VIETTELPOST_PASSWORD are required');
        }
        return ['api_url' => $apiUrl, 'username' => $username, 'password' => $password];
    }

    private function transport(): ShippingTransport
    {
        return $this->transport ?? ViettelPostHttpTransport::fromEnvironment();
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
            throw new \RuntimeException('ViettelPost request failed', 0, $error);
        }
        if ($response === []) {
            throw new \RuntimeException('ViettelPost returned an invalid response');
        }
        return $this->redact($response);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function ratePayload(array $context): array
    {
        return [
            'SENDER_PROVINCE' => $this->addressInt($context, 'origin', 'province_id'),
            'SENDER_DISTRICT' => $this->addressInt($context, 'origin', 'district_id'),
            'SENDER_WARD' => $this->addressString($context, 'origin', 'ward_code'),
            'RECEIVER_PROVINCE' => $this->addressInt($context, 'destination', 'province_id'),
            'RECEIVER_DISTRICT' => $this->addressInt($context, 'destination', 'district_id'),
            'RECEIVER_WARD' => $this->addressString($context, 'destination', 'ward_code'),
            'PRODUCT_WEIGHT' => $this->weight($context),
            'PRODUCT_LENGTH' => $this->contextInt($context, 'length', 0),
            'PRODUCT_WIDTH' => $this->contextInt($context, 'width', 0),
            'PRODUCT_HEIGHT' => $this->contextInt($context, 'height', 0),
            'PRODUCT_TYPE' => $this->contextString($context, 'product_type', 'HH'),
            'PRODUCT_PRICE' => $this->contextInt($context, 'insurance_value', 0),
            'ORDER_SERVICE' => $this->contextString($context, 'service_code', ''),
        ];
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    private function shipmentPayload(array $shipment, string $idempotencyKey): array
    {
        $destination = is_array($shipment['destination'] ?? null) ? $shipment['destination'] : [];
        return [
            'ORDER_REFERENCE' => $idempotencyKey,
            'idempotency_key' => $idempotencyKey,
            'RECEIVER_NAME' => $this->pickString($destination, ['name', 'contact_name', 'recipient_name']),
            'RECEIVER_PHONE' => $this->pickString($destination, ['phone', 'contact_phone', 'recipient_phone']),
            'RECEIVER_ADDRESS' => $this->pickString($destination, ['address', 'address_line_1', 'line1']),
            'RECEIVER_WARD' => $this->pickString($destination, ['ward_code', 'ward']),
            'RECEIVER_DISTRICT' => $this->pickInt($destination, ['district_id', 'district']),
            'RECEIVER_PROVINCE' => $this->pickInt($destination, ['province_id', 'province']),
            'PRODUCT_WEIGHT' => $this->shipmentWeight($shipment),
            'PRODUCT_LENGTH' => $this->shipmentInt($shipment, 'length'),
            'PRODUCT_WIDTH' => $this->shipmentInt($shipment, 'width'),
            'PRODUCT_HEIGHT' => $this->shipmentInt($shipment, 'height'),
            'PRODUCT_TYPE' => $this->contextString($shipment, 'product_type', 'HH'),
            'PRODUCT_PRICE' => $this->shipmentInt($shipment, 'insurance_value'),
            'ORDER_NOTE' => $this->contextString($shipment, 'note', ''),
            'ORDER_SERVICE' => $this->contextString($shipment, 'service_code', ''),
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function shipmentResponse(array $response, string $idempotencyKey): array
    {
        $data = $this->data($response);
        $reference = $this->pickString($data, ['ORDER_NUMBER', 'order_number', 'provider_reference', 'provider_ref']);
        if ($reference === '') {
            $reference = $this->pickString($response, ['ORDER_NUMBER', 'order_number', 'provider_reference', 'provider_ref']);
        }
        if ($reference === '') {
            throw new \RuntimeException('ViettelPost returned no shipment reference');
        }
        return [
            'provider_reference' => $reference,
            'tracking_number' => $this->pickString($data, ['ORDER_NUMBER', 'ORDER_REFERENCE', 'tracking_number'], $reference),
            'status' => 'label_created',
            'provider_status' => $this->pickString($data, ['status', 'STATUS'], 'label_created'),
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
            } else {
                $rates = [];
            }
        }
        $normalized = [];
        foreach ($rates as $rate) {
            if (!is_array($rate)) continue;
            $amount = $this->pickNumber($rate, ['amount_minor', 'GIA_CUOC', 'MONEY_FEE', 'price']);
            if ($amount === null) continue;
            [$min, $max] = $this->eta($rate);
            $normalized[] = [
                'code' => $this->pickString($rate, ['code', 'MA_DV', 'MAVUNG', 'SERVICE_CODE'], 'viettelpost-standard'),
                'name' => $this->pickString($rate, ['name', 'TEN_DV', 'SERVICE_NAME'], 'ViettelPost Standard'),
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
        $estimate = $rate['THOI_GIAN'] ?? $rate['estimated_days'] ?? null;
        if (is_array($estimate)) {
            $min ??= $this->pickNullableInt($estimate, ['min', 'min_days']);
            $max ??= $this->pickNullableInt($estimate, ['max', 'max_days']);
        } elseif (is_string($estimate) && preg_match('/(\d+)\s*-\s*(\d+)/', $estimate, $matches) === 1) {
            $min ??= (int) $matches[1];
            $max ??= (int) $matches[2];
        } elseif ($estimate !== null) {
            $value = $this->positiveInt($estimate);
            if ($value !== null) $min ??= $value;
            if ($value !== null) $max ??= $value;
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
        if (array_key_exists('error', $response)) return $response['error'] === false;
        $status = $response['status'] ?? $response['code'] ?? null;
        return is_numeric($status) && (int) $status === 200;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function trackingRecord(array $response): array
    {
        $data = $response['data'] ?? null;
        if (is_array($data) && array_is_list($data)) {
            $first = $data[0] ?? null;
            if (is_array($first)) return $this->stringKeys($first);
        }
        if (is_array($data)) return $this->stringKeys($data);
        return $response;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function data(array $response): array
    {
        $data = $response['data'] ?? null;
        if (!is_array($data)) return $response;
        return $this->stringKeys($data);
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array<string, mixed>
     */
    private function stringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) if (is_string($key)) $result[$key] = $value;
        return $result;
    }

    private function mapStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        if (preg_match('/^\d+$/', $normalized) === 1) {
            return [
                '100' => 'picked_up',
                '101' => 'exception',
                '102' => 'picked_up',
                '103' => 'picked_up',
                '104' => 'in_transit',
                '105' => 'in_transit',
                '106' => 'in_transit',
                '107' => 'in_transit',
                '200' => 'out_for_delivery',
                '201' => 'delivered',
                '202' => 'returned',
                '300' => 'returned',
                '400' => 'cancelled',
                '500' => 'exception',
            ][$normalized] ?? 'unknown';
        }
        return [
            'ready_to_pick' => 'label_created',
            'picking' => 'picked_up',
            'picked' => 'picked_up',
            'transporting' => 'in_transit',
            'sorting' => 'in_transit',
            'delivering' => 'out_for_delivery',
            'delivered' => 'delivered',
            'cancel' => 'cancelled',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
            'return' => 'returned',
            'returned' => 'returned',
            'exception' => 'exception',
            'failed' => 'exception',
        ][$normalized] ?? 'unknown';
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
        $parsed = $this->positiveInt($context[$key] ?? null);
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
