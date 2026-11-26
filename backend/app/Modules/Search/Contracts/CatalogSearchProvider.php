<?php

declare(strict_types=1);

namespace App\Modules\Search\Contracts;

/**
 * Storefront catalog search boundary. Returning null means the provider is
 * unavailable (unconfigured, timeout, or provider error) so the caller can fall
 * back to the native SQL catalog search. An empty id list is a real "no hits"
 * answer and must not trigger typo fallback.
 */
interface CatalogSearchProvider
{
    /**
     * @param array<string, mixed> $filters
     * @return array{ids: list<string>, total: int}|null
     */
    public function search(string $q, array $filters, int $limit, int $offset): ?array;
}
