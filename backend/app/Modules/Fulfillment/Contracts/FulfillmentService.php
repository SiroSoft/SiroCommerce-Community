<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Contracts;

interface FulfillmentService
{
    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function allocate(string $orderId, array $lines, string $idempotencyKey): array;

    /**
     * @param array<int, array<string, mixed>>|null $lines
     * @return array<string, mixed>
     */
    public function ship(string $fulfillmentId, string $idempotencyKey, ?array $lines = null): array;

    /** @return array<string, mixed> */
    public function deliver(string $fulfillmentId, string $idempotencyKey): array;

    /** Click & Collect handover: counter staging. Replay returns the row. @return array<string, mixed> */
    public function markReady(string $fulfillmentId, string $idempotencyKey, ?string $pointCode = null): array;

    /** Click & Collect handover: customer collected the goods. Replay returns the row. @return array<string, mixed> */
    public function collect(string $fulfillmentId, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function cancel(string $fulfillmentId, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function track(string $fulfillmentId): array;
}
