<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Repositories\ProductRepository;
use App\Support\CommerceScope;

final class ProductCatalogReader implements CatalogReader
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    /** @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int, facets?: array<string, mixed>}}
     */
    public function activePage(int $page, int $perPage, ?CommerceScope $scope = null, array $filters = []): array
    {
        return $this->products->paginateActive($page, $perPage, $scope, $filters);
    }

    public function bySlug(string $slug, ?CommerceScope $scope = null): ?array
    {
        return $this->products->findBySlug($slug, $scope);
    }
}
