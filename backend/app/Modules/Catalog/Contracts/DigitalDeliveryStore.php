<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

interface DigitalDeliveryStore
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function issue(array $data): array;

    /** @return array<string, mixed>|null */
    public function byTokenHash(string $hash): ?array;

    public function increment(string $id): void;

    /** Atomically consumes one download slot; returns false when exhausted. */
    public function consume(string $id, ?int $limit): bool;
}
