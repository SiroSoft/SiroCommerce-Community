<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Auth\Contracts\UserStore;
use App\Repositories\UserRepository;
final class UserStoreAdapter implements UserStore
{
    public function __construct(private readonly UserRepository $users) {}
    public function findByEmail(string $email): ?array { return $this->users->findByEmail($email); }
    public function findCredentialsByEmail(string $email): ?array { return $this->users->findCredentialsByEmail($email); }
    /** @return array<string, mixed> */ public function create(array $data): array { return $this->users->create($data); }
    public function storeRefresh(array $data): void { $this->users->storeRefresh($data); }
    public function findRefresh(string $jti): ?array { return $this->users->findRefresh($jti); }
    public function find(int $id): ?array { return $this->users->find($id); }
    public function update(int $id, array $data): array { return $this->users->update($id, $data); }
    public function revokeRefresh(string $jti): void { $this->users->revokeRefresh($jti); }
    public function revokeAllForUser(int $userId): void { $this->users->revokeAllForUser($userId); }
    public function bumpTokenVersion(int $userId): void { $this->users->bumpTokenVersion($userId); }
}
