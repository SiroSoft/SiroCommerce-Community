<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\RefreshToken;
use App\Models\User;

final class UserRepository
{
    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $row = User::query()->where('email', $email)->first();
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        return $out;
    }

    /**
     * Login-only reader: includes the password hash that $hidden strips
     * from toArray(). Never return this row to HTTP responses.
     *
     * @return array<string, mixed>|null
     */
    public function findCredentialsByEmail(string $email): ?array
    {
        $row = User::query()->where('email', $email)->first();
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        $hash = $row->getAttribute('password');
        $out['password'] = is_string($hash) ? $hash : '';
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = User::find($id);
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        return $out;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(int $id, array $data): array
    {
        $row = User::find($id);
        if ($row === null) throw new \RuntimeException('User not found');
        $row->update($data);
        return $row->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        /** @var array<string, mixed> $out */
        $out = User::create($data)->toArray();
        return $out;
    }

    public function bumpTokenVersion(int $id): void
    {
        $row = User::find($id);
        if ($row === null) {
            return;
        }
        /** @var array<string, mixed> $current */
        $current = $row->toArray();
        $version = $current['token_version'] ?? 1;
        $row->update(['token_version' => is_int($version) ? $version + 1 : 1]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function storeRefresh(array $data): array
    {
        /** @var array<string, mixed> $out */
        $out = RefreshToken::create($data)->toArray();
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function findRefresh(string $jti): ?array
    {
        $row = RefreshToken::query()->where('jti', $jti)->first();
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        return $out;
    }

    public function revokeRefresh(string $jti): void
    {
        $row = RefreshToken::query()->where('jti', $jti)->first();
        if ($row !== null) {
            $row->update(['revoked' => 1]);
        }
    }

    public function revokeAllForUser(int $userId): void
    {
        $rows = RefreshToken::query()->where('user_id', $userId)->get();
        foreach ($rows as $row) {
            $row->update(['revoked' => 1]);
        }
    }
}
