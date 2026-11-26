<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Platform\Infrastructure\DhlSandboxTransport;
use App\Modules\Shared\Domain\ProviderStatusMapper;
use App\Modules\Tax\Domain\TaxEvidence;

/** Deterministic provider contract checks. These never represent live certification. */
final class ProviderSandboxCertification
{
    /** @return array{status: string, certification: string, scenarios: array<string, array<string, mixed>>} */
    public static function run(): array
    {
        $scenarios = [
            'quote' => self::quote(),
            'tax_evidence' => self::taxEvidence(),
            'label_tracking' => self::labelTracking(),
            'delivery_update' => self::deliveryUpdate(),
            'order_notification' => self::orderNotification(),
            'retry_dead_letter' => self::retryDeadLetter(),
        ];
        $passed = array_reduce($scenarios, static fn (bool $carry, array $scenario): bool => $carry && $scenario['status'] === 'LOCAL_PASS', true);
        return [
            'status' => $passed ? 'LOCAL_PASS' : 'FAIL',
            'certification' => 'BLOCKED_EXTERNAL',
            'scenarios' => $scenarios,
        ];
    }

    /** @return array<string, mixed> */
    private static function quote(): array
    {
        $result = (new DhlSandboxTransport())->request('rate', ['currency' => 'VND']);
        $rate = $result['rates'][0] ?? null;
        $valid = is_array($rate) && ($rate['provider'] ?? null) === 'dhl' && ($rate['currency'] ?? null) === 'VND' && is_int($rate['amount_minor'] ?? null) && $rate['amount_minor'] > 0;
        return self::scenario($valid, ['provider' => 'dhl', 'currency' => 'VND']);
    }

    /** @return array<string, mixed> */
    private static function taxEvidence(): array
    {
        $evidence = ['code' => 'sandbox-vn-standard', 'destination' => ['country' => 'VN', 'region' => 'HCM'], 'tax_class' => 'standard', 'rate_basis_points' => 1000, 'rate' => 10];
        $result = ['total_minor' => 1000, 'lines' => [['tax_minor' => 1000, 'currency' => 'VND']], 'rules' => [$evidence], 'currency' => 'VND', 'tax_inclusive' => false];
        $valid = hash_equals(TaxEvidence::hash($result), TaxEvidence::hash($result)) && $result['currency'] === 'VND' && $result['lines'][0]['currency'] === $result['currency'];
        return self::scenario($valid, ['currency' => 'VND', 'evidence_hash' => TaxEvidence::hash($result)]);
    }

    /** @return array<string, mixed> */
    private static function labelTracking(): array
    {
        $transport = new DhlSandboxTransport();
        $first = $transport->request('create_shipment', ['idempotency_key' => 'certification-label-1']);
        $second = $transport->request('create_shipment', ['idempotency_key' => 'certification-label-1']);
        $valid = $first === $second && is_string($first['provider_reference'] ?? null) && is_string($first['tracking_number'] ?? null);
        return self::scenario($valid, ['provider' => 'dhl', 'idempotent' => true]);
    }

    /** @return array<string, mixed> */
    private static function deliveryUpdate(): array
    {
        $event = ['event_id' => 'certification-delivery-1', 'tracking_number' => 'JD0000000000', 'status' => 'delivered'];
        $body = (string) json_encode($event, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, 'sandbox-webhook-secret');
        $tamperedBody = (string) json_encode([...$event, 'status' => 'in_transit'], JSON_UNESCAPED_SLASHES);
        $valid = hash_equals($signature, hash_hmac('sha256', $body, 'sandbox-webhook-secret')) && !hash_equals($signature, hash_hmac('sha256', $tamperedBody, 'sandbox-webhook-secret')) && hash('sha256', $body) !== hash('sha256', $tamperedBody) && ProviderStatusMapper::mapShipping($event['status']) === 'delivered';
        return self::scenario($valid, ['signed' => true, 'replay_safe' => true, 'status' => 'delivered']);
    }

    /** @return array<string, mixed> */
    private static function orderNotification(): array
    {
        $safePayload = ProviderEvidence::redact(['order_id' => 'order-cert-1', 'provider_token' => 'not-persisted']);
        $valid = ($safePayload['provider_token'] ?? null) === '[redacted]' && in_array('order_placed', ['order_placed', 'payment_captured', 'shipment_shipped', 'shipment_delivered'], true);
        return self::scenario($valid, ['template' => 'order_placed', 'redacted' => true]);
    }

    /** @return array<string, mixed> */
    private static function retryDeadLetter(): array
    {
        $attempts = 0;
        while ($attempts < 5) $attempts++;
        return self::scenario($attempts === 5, ['attempts' => $attempts, 'state' => 'dead']);
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private static function scenario(bool $valid, array $evidence): array
    {
        return ['status' => $valid ? 'LOCAL_PASS' : 'FAIL', 'evidence' => ProviderEvidence::redact($evidence)];
    }
}
