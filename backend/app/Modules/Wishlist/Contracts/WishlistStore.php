<?php

declare(strict_types=1);

namespace App\Modules\Wishlist\Contracts;

interface WishlistStore
{
    /** @return array<int, array<string, mixed>> */
    public function list(string $customerId): array;
    /** @return array<string, mixed>|null */
    public function find(string $customerId, string $productId): ?array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;
    public function delete(string $customerId, string $productId): bool;
}
