<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Contracts;

interface ReturnStore
{
    /** @param list<string> $aliasIds extra order-owner ids (member user id alongside the profile id)
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function paginateForCustomer(string $customerId, int $page, int $perPage, array $aliasIds = []): array;

    /** @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function paginateForOrder(string $orderId, int $page, int $perPage): array;

    /** @return array<string, mixed>|null */
    public function findForOrder(string $orderId, string $id): ?array;

    /** @param list<string> $aliasIds extra order-owner ids
     * @return array<string, mixed>|null */
    public function findForCustomer(string $customerId, string $id, array $aliasIds = []): ?array;

    /** @return array<string, mixed>|null */
    public function byIdempotency(string $key): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /** @param array<string, mixed> $data */
    public function addLine(array $data): void;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function paymentForOrder(string $orderId): ?array;

    /** @return array<int, array<string, mixed>> */
    public function lines(string $id): array;

    public function returnedQty(string $orderLineId): int;
    /** @return array<string, mixed>|null */
    public function findForUpdate(string $id): ?array;

    /** @param array<string,mixed> $metadata */
    public function setState(string $id, string $state, ?string $completedAt = null, array $metadata = []): void;
    /**
     * @param array<int, string> $from
     * @param array<string, mixed> $metadata
     */
    public function transition(string $id, array $from, string $state, ?string $completedAt = null, array $metadata = []): bool;
    public function inventoryPosted(string $returnId, string $lineId): bool;
    /** @param array<string,mixed> $data */ public function recordInventoryPosting(array $data): void;
    /** @param array<string,mixed> $data */ public function recordEvent(array $data): void;
    /** @return array<string,mixed>|null */ public function byLabelIdempotency(string $key): ?array;
    /** @param array<string,mixed> $metadata */ public function setLogistics(string $id, array $metadata): void;
    public function setLineDisposition(string $returnId, string $disposition): void;
    public function claimLabel(string $returnId, string $idempotencyKey): bool;
    public function queueLabel(string $returnId, string $idempotencyKey): void;
    /** @param array<string,mixed> $metadata */ public function completeLabel(string $returnId, array $metadata): void;
    public function failLabel(string $returnId, string $error): void;
}
