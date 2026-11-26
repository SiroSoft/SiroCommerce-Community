<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Application;

use App\Modules\Shipping\Contracts\ShippingEvidenceStore;
use App\Modules\Shipping\Contracts\ShippingProviderRegistry;
use App\Modules\Shipping\Contracts\ShippingShipmentStore;
use App\Jobs\ShippingOperationJob;
use Siro\Core\Queue;
use App\Support\ProviderEvidence;
use App\Modules\Shared\Domain\ProviderStatusMapper;
use App\Modules\Commerce\Domain\RegionalRules;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Shared\Domain\Money;

final class ShippingService
{
    public function __construct(private readonly ShippingProviderRegistry $providers, private readonly ShippingEvidenceStore $evidence, private readonly ShippingShipmentStore $shipments) {}

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function quote(array $context): array
    {
        $this->validateQuoteContext($context);
        $provider = is_string($context['provider'] ?? null) ? $context['provider'] : 'dhl';
        $expectedCurrency = $this->quoteCurrency($context);
        try {
            $result = $this->providers->rates($provider)->quote($context);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Shipping provider is unavailable; retry the quote', 0, $error);
        }
        if (!is_array($result) || $result === []) throw new \RuntimeException('No shipping rate available');
        $normalized = [];
        foreach ($result as $rate) {
            if (!is_array($rate) || !is_string($rate['code'] ?? null) || trim($rate['code']) === '' || !is_int($rate['amount_minor'] ?? null) && !(is_string($rate['amount_minor'] ?? null) && ctype_digit($rate['amount_minor'])) || (int) $rate['amount_minor'] < 0 || (int) $rate['amount_minor'] > Money::MAX_MINOR || !is_string($rate['currency'] ?? null) || !preg_match('/^[A-Z]{3}$/', strtoupper($rate['currency']))) {
                throw new \RuntimeException('Invalid shipping rate response');
            }
            $rate['code'] = trim($rate['code']);
            $rate['amount_minor'] = (int) $rate['amount_minor'];
            $rate['currency'] = strtoupper($rate['currency']);
            try { Currency::normalize($rate['currency']); } catch (\InvalidArgumentException $error) { throw new \RuntimeException('Invalid shipping rate currency', 0, $error); }
            if ($expectedCurrency !== null && $rate['currency'] !== $expectedCurrency) throw new \RuntimeException('Shipping rate currency mismatch');
            $rate['provider'] = $provider;
            $rate['regional_evidence'] = RegionalRules::evidence($context['origin'], $context['destination']);
            $normalized[] = $rate;
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    public function returnLabel(array $shipment, string $idempotencyKey): array
    {
        if (trim($idempotencyKey) === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $this->validateShipment($shipment);
        $provider = is_string($shipment['provider'] ?? null) ? $shipment['provider'] : 'dhl';
        $existing = $this->evidence->find($provider, 'create_return_label', $idempotencyKey);
        if ($existing !== null) return $this->decodeResponse($existing, ['provider' => $provider, 'status' => 'pending']);
        $result = $this->providers->carrier($provider)->createReturnLabel($shipment, $idempotencyKey);
        $reference = $result['provider_reference'] ?? $result['provider_ref'] ?? null;
        if (!is_string($reference) || trim($reference) === '') throw new \RuntimeException('Shipping provider returned no return-label reference');
        $response = ['provider' => $provider, 'status' => 'label_created'];
        foreach ($result as $key => $value) $response[$key] = $value;
        $this->evidence->record(['provider' => $provider, 'operation' => 'create_return_label', 'idempotency_key' => $idempotencyKey, 'provider_reference' => $reference, 'status' => 'label_created', 'request_json' => $this->encode($shipment), 'response_json' => $this->encode($response)]);
        return $response;
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    public function create(array $shipment, string $idempotencyKey): array
    {
        if (trim($idempotencyKey) === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $this->validateShipment($shipment);
        $provider = is_string($shipment['provider'] ?? null) ? $shipment['provider'] : 'dhl';
        $existingShipment = $this->shipments->byIdempotency($provider, $idempotencyKey);
        if ($existingShipment !== null && !in_array($existingShipment['state'] ?? null, ['pending', 'retryable'], true)) return $this->shipmentResponse($existingShipment);
        $existing = $this->evidence->find($provider, 'create_shipment', $idempotencyKey);
        if ($existing !== null) return $this->decodeResponse($existing, ['provider' => $provider, 'status' => 'label_created']);
        try {
            $result = $this->providers->carrier($provider)->create($shipment, $idempotencyKey);
            if (!is_array($result) || (isset($result['retryable']) && $result['retryable'] === true)) throw new \RuntimeException('Shipping provider is unavailable; shipment remains retryable');
         } catch (\Throwable $error) {
             if ($existingShipment !== null && method_exists($this->shipments, 'setState')) $this->shipments->setState((string) $existingShipment['id'], 'retryable');
             throw new \RuntimeException('Shipping provider is unavailable; shipment remains retryable', 0, $error);
         }
         $providerReference = $result['provider_reference'] ?? null;
         if (!is_string($providerReference) || trim($providerReference) === '') throw new \RuntimeException('Shipping provider returned no shipment reference');
        /** @var array<string, mixed> $response */
        $response = ['provider' => $provider, 'status' => 'label_created'] + $result;
        $this->evidence->record([
            'provider' => $provider, 'operation' => 'create_shipment', 'idempotency_key' => $idempotencyKey,
            'provider_reference' => $providerReference,
            'status' => 'label_created', 'request_json' => $this->encode($shipment), 'response_json' => $this->encode($response),
        ]);
        $data = [
            'provider' => $provider, 'provider_reference' => $providerReference,
            'tracking_number' => is_string($result['tracking_number'] ?? null) ? $result['tracking_number'] : null,
            'order_id' => is_string($shipment['order_id'] ?? null) ? $shipment['order_id'] : null,
            'fulfillment_id' => is_string($shipment['fulfillment_id'] ?? null) ? $shipment['fulfillment_id'] : null,
            'state' => 'label_created', 'idempotency_key' => $idempotencyKey, 'payload_json' => $this->encode($response),
        ];
        if ($existingShipment !== null && method_exists($this->shipments, 'update')) {
            $existingId = $existingShipment['id'] ?? null;
            if (!is_string($existingId)) throw new \RuntimeException('Pending shipment ID is missing');
            /** @var array<string, mixed> $created */
            $created = $this->shipments->update($existingId, $data);
        } else {
            $created = $this->shipments->create($data);
        }
        $response['shipment_id'] = $created['id'] ?? null;
        return $response;
    }

    /**
     * Queue the carrier call after durable pending state is written.
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    public function enqueueCreate(array $shipment, string $idempotencyKey): array
    {
        if (trim($idempotencyKey) === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $this->validateShipment($shipment);
        $provider = is_string($shipment['provider'] ?? null) ? $shipment['provider'] : 'dhl';
        $existing = $this->shipments->byIdempotency($provider, $idempotencyKey);
        if ($existing !== null) return $this->shipmentResponse($existing);
        $pending = $this->shipments->create(['provider' => $provider, 'provider_reference' => null, 'tracking_number' => null, 'order_id' => is_string($shipment['order_id'] ?? null) ? $shipment['order_id'] : null, 'fulfillment_id' => is_string($shipment['fulfillment_id'] ?? null) ? $shipment['fulfillment_id'] : null, 'state' => 'pending', 'idempotency_key' => $idempotencyKey, 'payload_json' => $this->encode(['provider' => $provider, 'status' => 'pending'])]);
        Queue::push(ShippingOperationJob::class, ['operation' => 'create', 'shipment' => $shipment, 'idempotency_key' => $idempotencyKey], 0, 0, 5);
        return $this->shipmentResponse($pending);
    }

    /** @return array<string, mixed>|null */
    public function shipment(string $provider, string $idempotencyKey): ?array
    {
        return $this->shipments->byIdempotency($provider, $idempotencyKey);
    }

    public function markRetryable(string $shipmentId): void { $this->shipments->setState($shipmentId, 'retryable'); }

    /** @return array<string, mixed> */
    public function track(string $providerShipmentId, string $provider = 'dhl'): array
    {
        if (trim($providerShipmentId) === '') throw new \InvalidArgumentException('Provider shipment ID is required');
        $provider = trim($provider) !== '' ? strtolower(trim($provider)) : 'dhl';
        $result = $this->providers->carrier($provider)->track($providerShipmentId);
        if (!is_array($result)) throw new \RuntimeException('Invalid shipping tracking response');
        $normalized = $this->normalizeTracking($result, $provider);
        $normalizedStatus = is_string($normalized['status'] ?? null) ? $normalized['status'] : 'unknown';
        $this->evidence->record(['provider' => $provider, 'operation' => 'track', 'idempotency_key' => hash('sha256', $providerShipmentId . ':' . $normalizedStatus), 'provider_reference' => $providerShipmentId, 'status' => $normalizedStatus, 'request_json' => $this->encode(['provider_shipment_id' => $providerShipmentId]), 'response_json' => $this->encode($normalized)]);
        return $normalized;
    }

    /** @return array<string, mixed> */
    public function cancel(string $providerShipmentId, string $idempotencyKey, string $provider = 'dhl'): array
    {
        if (trim($providerShipmentId) === '' || trim($idempotencyKey) === '') throw new \InvalidArgumentException('Shipment ID and Idempotency-Key are required');
        $provider = trim($provider) !== '' ? strtolower(trim($provider)) : 'dhl';
        $existing = $this->evidence->find($provider, 'cancel_shipment', $idempotencyKey);
        if ($existing !== null) return $this->decodeResponse($existing, ['provider' => $provider, 'status' => 'pending']);
        $result = $this->providers->carrier($provider)->cancel($providerShipmentId, $idempotencyKey);
        if (!is_array($result) || (isset($result['cancelled']) && !is_bool($result['cancelled']))) throw new \RuntimeException('Invalid shipping cancellation response');
        $response = ['provider' => $provider, 'status' => (($result['cancelled'] ?? false) === true ? 'cancelled' : 'pending')] + $result;
        $this->evidence->record(['provider' => $provider, 'operation' => 'cancel_shipment', 'idempotency_key' => $idempotencyKey, 'provider_reference' => $providerShipmentId, 'status' => $response['status'], 'request_json' => $this->encode(['provider_shipment_id' => $providerShipmentId]), 'response_json' => $this->encode($response)]);
        return $response;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function webhook(array $event): array
    {
        if (!is_string($event['event_id'] ?? null) || trim($event['event_id']) === '') throw new \InvalidArgumentException('Shipping webhook event_id is required');
        $provider = is_string($event['provider'] ?? null) ? $event['provider'] : 'dhl';
        if (isset($event['status']) && !is_string($event['status'])) throw new \InvalidArgumentException('Shipping webhook status must be a string');
        if (isset($event['tracking_number']) && !is_string($event['tracking_number'])) throw new \InvalidArgumentException('Shipping webhook tracking number must be a string');
        $rawStatus = $event['status'] ?? $event['description'] ?? 'exception';
        $status = strtolower(is_string($rawStatus) ? $rawStatus : 'exception');
        $canonical = ProviderStatusMapper::mapShipping($status) ?? 'unknown';
        /** @var array<string, mixed> $normalized */
        $normalized = ['provider' => $provider, 'provider_status' => $status, 'status' => $canonical, 'tracking_number' => $event['tracking_number'] ?? null];
        $eventId = $event['event_id'];
        $payloadHash = hash('sha256', $this->encode($event));
        $existing = $this->evidence->find($provider, 'webhook', $eventId);
        if ($existing !== null) {
            $storedHash = is_string($existing['payload_hash'] ?? null) ? $existing['payload_hash'] : '';
            if ($storedHash === '' || !hash_equals($storedHash, $payloadHash)) throw new \InvalidArgumentException('Shipping webhook replay payload mismatch');
            return $this->decodeResponse($existing, $normalized);
        }
        $this->evidence->record(['provider' => $provider, 'operation' => 'webhook', 'idempotency_key' => $eventId, 'provider_reference' => is_string($event['tracking_number'] ?? null) ? $event['tracking_number'] : null, 'status' => $normalized['status'], 'payload_hash' => $payloadHash, 'request_json' => $this->encode($event), 'response_json' => $this->encode($normalized)]);
        $tracking = $event['tracking_number'] ?? null;
        if (is_string($tracking)) {
            $shipment = $this->shipments->byTracking($provider, $tracking);
            if ($shipment !== null) {
                $shipmentId = $shipment['id'] ?? null;
                if (is_string($shipmentId)) {
                    $currentState = is_string($shipment['state'] ?? null) ? $shipment['state'] : null;
                    if ($this->canAdvanceTracking($currentState, $canonical)) $this->shipments->setState($shipmentId, $canonical);
                    $this->shipments->addTrackingEvent(['shipment_id' => $shipmentId, 'provider' => $provider, 'event_id' => $eventId, 'provider_status' => $status, 'state' => $canonical, 'payload_json' => $this->encode($event)]);
                }
            }
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalizeTracking(array $result, string $provider = 'dhl'): array
    {
        $rawStatus = $result['status'] ?? 'exception';
        $status = strtolower(is_string($rawStatus) ? $rawStatus : 'exception');
        $event = ['provider' => $provider, 'provider_status' => $status, 'status' => ProviderStatusMapper::mapShipping($status) ?? 'unknown', 'tracking_number' => $result['tracking_number'] ?? null];
        $event['provider_reference'] = is_string($result['provider_reference'] ?? null) ? $result['provider_reference'] : null;
        if (is_string($result['error_code'] ?? null)) $event['error_code'] = $result['error_code'];
        if (is_string($result['error_class'] ?? null)) $event['error_class'] = $result['error_class'];
        return $event;
    }

    /** @param array<string, mixed> $context */
    private function validateQuoteContext(array $context): void
    {
        foreach (['origin', 'destination', 'packages'] as $field) {
            if (!is_array($context[$field] ?? null) || $context[$field] === []) throw new \InvalidArgumentException('Shipping ' . $field . ' is required');
        }
        foreach ($context['packages'] as $package) {
            if (!is_array($package) || !is_numeric($package['weight_g'] ?? null) || (int) $package['weight_g'] <= 0) throw new \InvalidArgumentException('Shipping package weight must be positive');
        }
        RegionalRules::zone($context['origin'], $context['destination']);
    }

    /** @param array<string, mixed> $shipment */
    private function validateShipment(array $shipment): void
    {
        if (!is_array($shipment['destination'] ?? null) || $shipment['destination'] === []) throw new \InvalidArgumentException('Shipment destination is required');
        $origin = is_array($shipment['origin'] ?? null) ? $shipment['origin'] : ['country' => 'VN'];
        RegionalRules::zone($origin, $shipment['destination']);
        if (isset($shipment['packages']) && (!is_array($shipment['packages']) || $shipment['packages'] === [])) throw new \InvalidArgumentException('Shipment packages must not be empty');
    }

    /** @param array<string, mixed> $context */
    private function quoteCurrency(array $context): ?string
    {
        $scope = is_array($context['scope'] ?? null) ? $context['scope'] : [];
        $raw = $context['currency'] ?? ($scope['currency'] ?? null);
        if ($raw === null) return null;
        if (!is_string($raw)) throw new \InvalidArgumentException('Shipping currency must be a string');
        try { return Currency::normalize($raw); } catch (\InvalidArgumentException $error) { throw new \InvalidArgumentException('Unsupported shipping currency', 0, $error); }
    }

    private function canAdvanceTracking(?string $current, string $next): bool
    {
        if ($current === null || $current === '' || $current === $next) return true;
        $rank = ['pending' => 0, 'label_created' => 1, 'picked_up' => 2, 'in_transit' => 3, 'out_for_delivery' => 4, 'delivered' => 5, 'returned' => 6, 'cancelled' => 6, 'exception' => 1, 'unknown' => 0];
        return ($rank[$next] ?? 0) >= ($rank[$current] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    private function decodeResponse(array $row, array $fallback): array
    {
        $raw = $row['response_json'] ?? '';
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($decoded)) return $fallback;
        $result = [];
        foreach ($decoded as $key => $value) if (is_string($key)) $result[$key] = $value;
        return $result;
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return (string) json_encode(ProviderEvidence::redact($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    private function shipmentResponse(array $shipment): array
    {
        $payload = $shipment['payload_json'] ?? '';
        $decoded = is_string($payload) ? json_decode($payload, true) : null;
        if (!is_array($decoded)) $decoded = ['provider' => $shipment['provider'] ?? 'dhl', 'status' => $shipment['state'] ?? 'label_created'];
        $decoded = array_filter($decoded, static fn (mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
        $decoded['shipment_id'] = $shipment['id'] ?? null;
        return $decoded;
    }
}
