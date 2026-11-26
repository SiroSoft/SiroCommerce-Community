<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Coupon;
use App\Repositories\Concerns\CreatesModels;
use App\Support\Arr;
use App\Support\Ids;

final class CouponRepository
{
    use CreatesModels;

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $rows = Coupon::query()->get();
        return array_map(static function ($m): array {
            /** @var array<string, mixed> $out */
            $out = $m->toArray();
            return $out;
        }, $rows);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        $data['id'] = Arr::str($data, 'id', Ids::new());
        $data['version'] = 1;
        $row = self::createRow(Coupon::class, $data);
        // version 1
        try {
            \App\Models\CouponVersion::create([
                'id' => Ids::new(),
                'coupon_id' => Arr::str($row, 'id'),
                'version' => 1,
                'code' => Arr::str($row, 'code'),
                'rules' => Arr::str($row, 'rules', '{}'),
                'actor_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}
        return $row;
    }

    public function setState(string $id, string $state): bool
    {
        $row = Coupon::query()->where('id', $id)->first();
        if ($row === null) {
            return false;
        }
        $row->setAttribute('state', $state);
        $row->save();
        return true;
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): bool
    {
        $row = Coupon::query()->where('id', $id)->first();
        if ($row === null) return false;
        $currentVersion = (int) Arr::int(Arr::row($row), 'version', 1);
        foreach ($data as $k => $v) $row->setAttribute($k, $v);
        $row->setAttribute('version', $currentVersion + 1);
        $row->save();
        try {
            \App\Models\CouponVersion::create([
                'id' => Ids::new(),
                'coupon_id' => $id,
                'version' => $currentVersion + 1,
                'code' => Arr::str($row->toArray(), 'code', ''),
                'rules' => Arr::str($row->toArray(), 'rules', '{}'),
                'actor_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}
        return true;
    }
}
