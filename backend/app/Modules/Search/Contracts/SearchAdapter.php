<?php

declare(strict_types=1);

namespace App\Modules\Search\Contracts;

interface SearchAdapter
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function search(array $request): array;

    /** @return array<string, mixed> */
    public function status(): array;

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function enqueueReindex(array $request): array;
}
