<?php

declare(strict_types=1);

namespace App\Modules\Smart\Contracts;

interface SearchIndex
{
    /** @return array<string, mixed> */
    public function search(string $query): array;

    /** @return array{mode: string, lag_seconds: int, stale: bool} */
    public function status(): array;
}
