<?php

declare(strict_types=1);

namespace App\Modules\Smart\Application;

use App\Modules\Smart\Contracts\SearchIndex;

/** Search orchestration keeps index freshness evidence beside result facets. */
final class SearchService
{
    public function __construct(private readonly SearchIndex $index)
    {
    }

    /** @return array<string, mixed> */
    public function query(string $query): array
    {
        $result = $this->index->search($query);
        $result['index'] = $this->index->status();
        $result['facets'] = is_array($result['facets'] ?? null) ? $result['facets'] : [];
        return $result;
    }
}
