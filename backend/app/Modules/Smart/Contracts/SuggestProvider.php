<?php

declare(strict_types=1);

namespace App\Modules\Smart\Contracts;

/**
 * As-you-type suggestions (spec SEARCH_AUTOCOMPLETE_SPEC). Native ships
 * first; Meili/ES later through HttpSearchTransport behind this contract.
 */
interface SuggestProvider
{
    /**
     * @return array{queries: list<array{query: string, submits: int, clicks: int}>, products: list<array<string, mixed>>}
     */
    public function suggest(string $query, int $limit, ?string $viewerTier): array;
}
