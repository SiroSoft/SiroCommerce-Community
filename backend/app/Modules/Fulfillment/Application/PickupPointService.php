<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Application;

use App\Models\PickupPoint;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Click & Collect points (spec CLICK_COLLECT_SPEC). A point maps to one
 * inventory source_id holding the counter stock; the code is the stable
 * public id used by quote/place/handover.
 */
final class PickupPointService
{
    /** @return array<int, array<string, mixed>> active points only */
    public function active(): array
    {
        $out = [];
        foreach (PickupPoint::query()->where('state', 'active')->orderBy('name', 'asc')->get() as $model) {
            $out[] = $this->public(Arr::row($model));
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function get(string $code): ?array
    {
        $row = PickupPoint::query()->where('code', strtoupper(trim($code)))->first();
        return $row === null ? null : Arr::row($row);
    }

    /** @return array<string, mixed>|null active point or null */
    public function activePoint(string $code): ?array
    {
        $row = $this->get($code);
        return $row !== null && Arr::str($row, 'state') === 'active' ? $row : null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $code = strtoupper(trim(Arr::requiredStr($data, 'code')));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,31}$/', $code)) throw new \InvalidArgumentException('Point code is invalid');
        $name = trim(Arr::requiredStr($data, 'name'));
        if ($name === '' || mb_strlen($name) > 128) throw new \InvalidArgumentException('Point name is invalid');
        $sla = Arr::int($data, 'sla_hours', 24);
        if ($sla < 1 || $sla > 720) throw new \InvalidArgumentException('SLA must be 1..720 hours');
        if ($this->get($code) !== null) throw new \InvalidArgumentException('Point code is taken');
        $row = PickupPoint::create([
            'id' => Ids::new(),
            'code' => $code,
            'name' => $name,
            'store_id' => Arr::nullableStr($data, 'store_id'),
            'source_id' => Arr::str($data, 'source_id', 'default') !== '' ? Arr::str($data, 'source_id', 'default') : 'default',
            'address_line' => Arr::nullableStr($data, 'address_line'),
            'city' => Arr::nullableStr($data, 'city'),
            'sla_hours' => $sla,
            'state' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->public(Arr::row($row));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(string $code, array $data): array
    {
        $row = PickupPoint::query()->where('code', strtoupper(trim($code)))->first();
        if ($row === null) throw new \RuntimeException('Pickup point not found');
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name) > 128) throw new \InvalidArgumentException('Point name is invalid');
            $row->setAttribute('name', $name);
        }
        foreach (['store_id', 'source_id', 'address_line', 'city'] as $field) {
            if (array_key_exists($field, $data)) $row->setAttribute($field, is_string($data[$field]) && trim($data[$field]) !== '' ? trim($data[$field]) : null);
        }
        if (array_key_exists('source_id', $data) && $row->getAttribute('source_id') === null) $row->setAttribute('source_id', 'default');
        if (array_key_exists('sla_hours', $data)) {
            $sla = (int) $data['sla_hours'];
            if ($sla < 1 || $sla > 720) throw new \InvalidArgumentException('SLA must be 1..720 hours');
            $row->setAttribute('sla_hours', $sla);
        }
        if (array_key_exists('state', $data)) {
            $state = trim((string) $data['state']);
            if (!in_array($state, ['active', 'disabled'], true)) throw new \InvalidArgumentException('Point state is invalid');
            $row->setAttribute('state', $state);
        }
        $row->save();
        return $this->public(Arr::row($row));
    }

    /** @return array<string, mixed> */
    public function public(array $row): array
    {
        return [
            'code' => Arr::str($row, 'code'),
            'name' => Arr::str($row, 'name'),
            'source_id' => Arr::str($row, 'source_id', 'default') !== '' ? Arr::str($row, 'source_id', 'default') : 'default',
            'address_line' => Arr::nullableStr($row, 'address_line'),
            'city' => Arr::nullableStr($row, 'city'),
            'sla_hours' => max(1, Arr::int($row, 'sla_hours', 24)),
            'state' => Arr::str($row, 'state', 'active'),
        ];
    }

    public function readyBy(array $point, ?int $now = null): string
    {
        return date('Y-m-d H:i:s', ($now ?? time()) + max(1, Arr::int($point, 'sla_hours', 24)) * 3600);
    }
}
