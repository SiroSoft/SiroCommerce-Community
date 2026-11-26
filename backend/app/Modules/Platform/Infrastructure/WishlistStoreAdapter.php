<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\Wishlist;
use App\Modules\Wishlist\Contracts\WishlistStore;
use App\Support\Arr;
use App\Support\Ids;

final class WishlistStoreAdapter implements WishlistStore
{
    public function list(string $customerId): array { return array_map(static fn ($row): array => Arr::row($row), Wishlist::query()->where('customer_id', $customerId)->orderBy('created_at', 'desc')->get()); }
    public function find(string $customerId, string $productId): ?array { $row = Wishlist::query()->where('customer_id', $customerId)->where('product_id', $productId)->first(); return $row === null ? null : Arr::row($row); }
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array { $id = Ids::new(); $row = Wishlist::create(['id' => $id, 'created_at' => date('Y-m-d H:i:s')] + $data)->toArray(); $row['id'] = $id; return $row; }
    public function delete(string $customerId, string $productId): bool { $row = Wishlist::query()->where('customer_id', $customerId)->where('product_id', $productId)->first(); return $row !== null && $row->delete(); }
}
