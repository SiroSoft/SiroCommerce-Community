<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Fulfillment\Contracts\ReturnService;
use App\Support\Arr;
use Siro\Core\Container;
use Siro\Core\QueueInterface;

/** Processes a carrier label outside the HTTP request. */
final class ReturnLabelJob implements QueueInterface
{
    /** Native queue retries transient provider failures up to this limit. */
    public int $maxAttempts = 5;

    /** @param array<string, mixed> $data */
    public function handle(array $data = []): void
    {
        $returnId = Arr::requiredStr($data, 'return_id');
        $idempotencyKey = Arr::requiredStr($data, 'idempotency_key');
        $shipping = [];
        if (is_array($data['shipping'] ?? null)) {
            foreach ($data['shipping'] as $key => $value) {
                if (is_string($key)) $shipping[$key] = $value;
            }
        }
        $service = Container::getInstance()->make(ReturnService::class);
        if (!$service instanceof \App\Modules\Fulfillment\Application\ReturnManager) {
            throw new \RuntimeException('Return service binding is invalid');
        }
        $service->processLabel($returnId, $shipping, $idempotencyKey);
    }
}
