<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure;

use App\Modules\Search\Contracts\CatalogSearchProvider;

/** Keeps the repository on its native SQL path when Meilisearch is disabled. */
final class NullCatalogSearchProvider implements CatalogSearchProvider
{
    /**
     * @param array<string, mixed> $filters
     * @return array{ids: list<string>, total: int}|null
     */
    public function search(string $q, array $filters, int $limit, int $offset): ?array
    {
        return null;
    }
}
