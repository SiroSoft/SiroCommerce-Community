<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

interface IdempotencyStore
{
    /** @return array<string, mixed>|null */
    public function find(string $scope, string $key): ?array;

    /** @param array<string, mixed> $response */
    public function store(string $scope, string $key, array $response): void;
}
