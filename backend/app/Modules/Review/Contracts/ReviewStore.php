<?php

declare(strict_types=1);

namespace App\Modules\Review\Contracts;

interface ReviewStore
{
    /** @return array<int, array<string, mixed>> */
    public function published(string $productId): array;
    /** @return array<string, mixed>|null */
    public function ownedOrderLine(string $customerId, string $orderId, string $lineId): ?array;
    public function alreadySubmitted(string $customerId, string $lineId): bool;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;
    /** @return array<int, array<string, mixed>> */
    public function all(): array;
    /** @return array<string, mixed>|null */
    public function moderate(string $id, string $status, string $note): ?array;
}
