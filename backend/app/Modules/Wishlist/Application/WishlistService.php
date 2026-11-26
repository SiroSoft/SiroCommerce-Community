<?php

declare(strict_types=1);

namespace App\Modules\Wishlist\Application;

use App\Modules\Wishlist\Contracts\WishlistStore;
use App\Support\Arr;

final class WishlistService
{
    public function __construct(private readonly WishlistStore $store) {}
    /** @return array<int, array<string, mixed>> */
    public function list(string $customerId): array
    {
        return $this->store->list($customerId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function add(string $customerId, array $data): array
    {
        $productId = Arr::requiredStr($data, 'product_id');
        $existing = $this->store->find($customerId, $productId);
        if ($existing !== null) return $existing;
        /** @var array<string, mixed> $result */
        $result = $this->store->create(['customer_id' => $customerId, 'product_id' => $productId, 'variant_id' => Arr::nullableStr($data, 'variant_id')]);
        return $result;
    }

    public function remove(string $customerId, string $productId): bool
    {
        return $this->store->delete($customerId, $productId);
    }
}
