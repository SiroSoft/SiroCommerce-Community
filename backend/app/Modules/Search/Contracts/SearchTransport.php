<?php

declare(strict_types=1);

namespace App\Modules\Search\Contracts;

interface SearchTransport
{
    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function search(string $index, array $body): array;
}
