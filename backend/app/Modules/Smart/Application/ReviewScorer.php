<?php

declare(strict_types=1);

namespace App\Modules\Smart\Application;

/**
 * Review rollup seam (spec SMART_SKILLS_SPEC §5): the lexicon scorer is the
 * default; a PhoBERT microservice can replace it without touching callers.
 */
interface ReviewScorer
{
    /**
     * @param list<array{rating: int, body: string}> $reviews
     * @return array{rating_avg: float, pos_count: int, neg_count: int, aspects: array<string, array{pos: int, neg: int}>, excerpts: array{positive: ?string, negative: ?string}}
     */
    public function rollup(array $reviews): array;

    public function score(string $text): int;
}
