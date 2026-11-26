<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Shipping\Application\ShippingService;
use App\Support\Arr;
use Siro\Core\Container;
use Siro\Core\QueueInterface;

/** Executes carrier calls outside the request and lets the queue retry transient failures. */
final class ShippingOperationJob implements QueueInterface
{
    public int $maxAttempts = 5;

    /** @param array<string, mixed> $data */
    public function handle(array $data = []): void
    {
        $operation = Arr::requiredStr($data, 'operation');
        $service = Container::getInstance()->make(ShippingService::class);
        if (!$service instanceof ShippingService) throw new \RuntimeException('Shipping service binding is invalid');
        if ($operation === 'create') {
            $shipment = [];
            if (is_array($data['shipment'] ?? null)) foreach ($data['shipment'] as $field => $value) if (is_string($field)) $shipment[$field] = $value;
            $key = Arr::requiredStr($data, 'idempotency_key');
            try {
                $service->create($shipment, $key);
            } catch (\Throwable $error) {
                $provider = is_string($shipment['provider'] ?? null) ? $shipment['provider'] : 'dhl';
                $row = $service->shipment($provider, $key);
                if ($row !== null && is_string($row['id'] ?? null)) $service->markRetryable($row['id']);
                throw $error;
            }
            return;
        }
        if ($operation === 'cancel') {
            $service->cancel(Arr::requiredStr($data, 'provider_shipment_id'), Arr::requiredStr($data, 'idempotency_key'));
            return;
        }
        throw new \InvalidArgumentException('Unsupported shipping operation');
    }
}
