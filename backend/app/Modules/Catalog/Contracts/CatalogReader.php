<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Support\CommerceScope;

interface CatalogReader
{
    /** @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function activePage(int $page, int $perPage, ?CommerceScope $scope = null, array $filters = []): array;

    /** @return array<string, mixed>|null */
    public function bySlug(string $slug, ?CommerceScope $scope = null): ?array;
}
