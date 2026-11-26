<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

interface ShippingEvidenceStore
{
    /** @return array<string, mixed>|null */
    public function find(string $provider, string $operation, string $idempotencyKey): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function record(array $data): array;
}
