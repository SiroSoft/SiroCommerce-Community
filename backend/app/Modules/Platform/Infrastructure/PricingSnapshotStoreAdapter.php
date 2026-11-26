<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\PricingSnapshot;
use App\Modules\Pricing\Contracts\PricingSnapshotStore;
use App\Repositories\Concerns\CreatesModels;

final class PricingSnapshotStoreAdapter implements PricingSnapshotStore
{
    use CreatesModels;

    public function current(string $ownerType, string $ownerId): ?array { return $this->row(PricingSnapshot::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->first()); }
    public function save(array $data): array
    {
        $existing = PricingSnapshot::query()->where('owner_type', $data['owner_type'] ?? 'cart')->where('owner_id', $data['owner_id'] ?? '')->first();
        if ($existing !== null) { foreach ($data as $key => $value) $existing->setAttribute($key, $value); $existing->save(); return $this->row($existing) ?? []; }
        $result = self::createRow(PricingSnapshot::class, $data);
        return $this->map($result);
    }

    /** @return array<string, mixed>|null */
    private function row(mixed $model): ?array { return is_object($model) && method_exists($model, 'toArray') ? $this->map($model->toArray()) : null; }
    /** @return array<string, mixed> */
    private function map(mixed $value): array { $result = []; if (is_array($value)) foreach ($value as $key => $item) if (is_string($key)) $result[$key] = $item; return $result; }
}
