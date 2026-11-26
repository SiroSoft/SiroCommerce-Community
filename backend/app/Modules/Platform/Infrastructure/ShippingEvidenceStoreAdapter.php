<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\ShippingProviderEvidence;
use App\Modules\Shipping\Contracts\ShippingEvidenceStore;
use App\Support\Arr;
use App\Support\Ids;

final class ShippingEvidenceStoreAdapter implements ShippingEvidenceStore
{
    public function find(string $provider, string $operation, string $idempotencyKey): ?array
    {
        $row = ShippingProviderEvidence::query()->where('provider', $provider)->where('operation', $operation)->where('idempotency_key', $idempotencyKey)->first();
        return $row === null ? null : Arr::row($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function record(array $data): array
    {
        return ShippingProviderEvidence::create(array_merge(['id' => Ids::new(), 'payload_hash' => null], $data))->toArray();
    }
}
