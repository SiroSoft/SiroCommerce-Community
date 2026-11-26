<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

interface PricingSnapshotStore
{
    /** @return array<string, mixed>|null */
    public function current(string $ownerType, string $ownerId): ?array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(array $data): array;
}
