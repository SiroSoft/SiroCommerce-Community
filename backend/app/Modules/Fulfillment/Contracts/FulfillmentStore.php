<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Contracts;

interface FulfillmentStore
{
    /** @return array<string, mixed>|null */
    public function byIdempotency(string $key): ?array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /** @param array<string, mixed> $data */
    public function addLine(array $data): void;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<int, array<string, mixed>> */
    public function lines(string $id): array;

    /** @return array<string, mixed>|null */
    public function orderLine(string $orderId, string $lineId): ?array;

    public function lockOrderLine(string $orderId, string $lineId): void;

    public function allocatedQty(string $orderLineId): int;

    public function deliveredQty(string $fulfillmentId, string $orderLineId): int;
    public function lockForUpdate(string $fulfillmentId): void;
    public function lockForReturn(string $fulfillmentId): void;

    public function setState(string $id, string $state): void;

    public function markReady(string $id, string $pointCode, string $readyAt, string $readyBy): void;

    public function markCollected(string $id, string $pickedUpAt): void;

    /** @return array<string, mixed>|null */
    public function packageByIdempotency(string $key): ?array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createPackage(array $data): array;

    /** @param array<string, mixed> $data */
    public function addPackageLine(array $data): void;

    public function setPackageState(string $id, string $state, ?string $tracking = null): void;

    /** @return array<string, mixed>|null */
    public function package(string $fulfillmentId): ?array;

    public function packagedQty(string $fulfillmentLineId): int;

    /** @return array<int, array<string, mixed>> */
    public function packages(string $fulfillmentId): array;

    public function setBackorderQty(string $fulfillmentId, int $qty): void;
}
