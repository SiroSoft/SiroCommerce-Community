<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Product;
use App\Models\Vendor;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\PaginationBounds;
use Siro\Core\Cache;

final class VendorRepository
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function paginate(?string $status, string $q, int $page, int $perPage): array
    {
        $page = PaginationBounds::page($page);
        $perPage = PaginationBounds::perPage($perPage);
        $query = Vendor::query();
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        $search = strtolower(trim($q));
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            $query->whereRaw('(LOWER(name) LIKE :vendor_q OR LOWER(slug) LIKE :vendor_q)', ['vendor_q' => $pattern]);
        }
        $total = $query->count();
        $rows = [];
        foreach ($query->orderBy('name')->orderBy('id')->limit($perPage)->offset(($page - 1) * $perPage)->get() as $model) {
            $rows[] = Arr::row($model);
        }
        $counts = $this->productCounts(array_values(array_filter(array_map(
            static fn (array $row): mixed => $row['id'] ?? null,
            $rows,
        ), static fn (mixed $id): bool => is_string($id) && $id !== '')));
        foreach ($rows as &$row) {
            $id = Arr::str($row, 'id');
            $row['products_count'] = $counts[$id] ?? 0;
        }
        unset($row);
        return [
            'data' => $rows,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $row = Vendor::find($id);
        return $row === null ? null : Arr::row($row);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $row = Vendor::query()->where('slug', strtolower(trim($slug)))->first();
        return $row === null ? null : Arr::row($row);
    }

    /** @return array<string, mixed>|null */
    public function findByOwnerCustomerId(string $customerId): ?array
    {
        $row = Vendor::query()->where('owner_customer_id', $customerId)->first();
        return $row === null ? null : Arr::row($row);
    }

    public function slugExists(string $slug, ?string $exceptId = null): bool
    {
        $query = Vendor::query()->where('slug', $slug);
        if ($exceptId !== null && $exceptId !== '') $query->whereRaw('id <> :vendor_except_id', ['vendor_except_id' => $exceptId]);
        return $query->first() !== null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        /** @var array<string, mixed> $out */
        $out = Vendor::create($data)->toArray();
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data): ?array
    {
        $row = Vendor::find($id);
        if ($row === null) return null;
        $row->update($data);
        return Arr::row($row);
    }

    public function delete(string $id): bool
    {
        $row = Vendor::find($id);
        return $row !== null && $row->delete();
    }

    public function hasProducts(string $vendorId): bool
    {
        return Product::query()->where('vendor_id', $vendorId)->first() !== null;
    }

    public function productCount(string $vendorId, ?CommerceScope $scope = null): int
    {
        $query = Product::query()->where('vendor_id', $vendorId)->where('status', 'active')->whereNull('archived_at');
        if ($scope !== null) {
            $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency);
        }
        return $query->count();
    }

    /**
     * @param array<int, string> $vendorIds
     * @return array<string, int>
     */
    private function productCounts(array $vendorIds): array
    {
        if ($vendorIds === []) return [];
        $counts = [];
        foreach (Product::query()->whereIn('vendor_id', $vendorIds)->selectRaw('vendor_id, COUNT(*) AS products_count')->groupBy('vendor_id')->get() as $model) {
            $row = Arr::row($model);
            $id = Arr::str($row, 'vendor_id');
            if ($id !== '') $counts[$id] = Arr::int($row, 'products_count');
        }
        return $counts;
    }

    public function invalidateCatalogCaches(): void
    {
        Cache::flush('catalog:');
    }
}
