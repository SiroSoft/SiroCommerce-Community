<?php
declare(strict_types=1);
namespace App\Modules\Auth\Contracts;
interface UserStore
{
    /** @return array<string, mixed>|null */ public function findByEmail(string $email): ?array;
    /** @return array<string, mixed>|null */ public function findCredentialsByEmail(string $email): ?array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;
    /** @param array<string, mixed> $data */ public function storeRefresh(array $data): void;
    /** @return array<string, mixed>|null */ public function findRefresh(string $jti): ?array;
    /** @return array<string, mixed>|null */ public function find(int $id): ?array;
    /** @param array<string, mixed> $data @return array<string, mixed> */ public function update(int $id, array $data): array;
    public function revokeRefresh(string $jti): void;
    public function revokeAllForUser(int $userId): void;
    public function bumpTokenVersion(int $userId): void;
}
