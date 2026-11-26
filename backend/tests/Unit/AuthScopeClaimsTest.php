<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Auth\Contracts\UserStore;
use App\Support\AuthScopeClaims;
use Siro\Core\Auth\JWT;
use Tests\TestCase;

final class AuthScopeClaimsTest extends TestCase
{
    public function testLoginAndRefreshProvisionOnlyPersistedScopeClaims(): void
    {
        $user = [
            'id' => 7, 'name' => 'Scoped', 'email' => 'scoped@example.test',
            'password' => password_hash('secret', PASSWORD_DEFAULT), 'role' => 'admin',
            'status' => 'active', 'token_version' => 3,
            'tenant_ids' => '["tenant-a"]', 'seller_ids' => ['seller-a'], 'site_ids' => ['site-a'],
        ];
        $store = new class($user) implements UserStore {
            /** @param array<string, mixed> $user */
            public function __construct(private array $user) {}
            public function findByEmail(string $email): ?array { return null; }
            public function findCredentialsByEmail(string $email): ?array { return $this->user; }
            public function create(array $data): array { return $data + ['id' => 7]; }
            public function update(int $id, array $data): array { return $data + ['id' => $id]; }
            public function storeRefresh(array $data): void {}
            public function findRefresh(string $jti): ?array { return null; }
            public function find(int $id): ?array { return $this->user; }
            public function revokeRefresh(string $jti): void {}
            public function revokeAllForUser(int $userId): void {}
            public function bumpTokenVersion(int $userId): void {}
        };

        $tokens = (new AuthService($store))->login('scoped@example.test', 'secret');
        $access = JWT::decode($tokens->access);
        self::assertSame(['tenant-a'], $access['tenant_ids']);
        self::assertSame(['seller-a'], $access['seller_ids']);
        self::assertSame(['site-a'], $access['site_ids']);
        self::assertSame(['tenant-a'], AuthScopeClaims::fromUser($user)['tenant_ids']);
    }

    public function testAccessTokenCannotBeUsedForRefresh(): void
    {
        $store = new class implements UserStore {
            public function findByEmail(string $email): ?array { return null; }
            public function findCredentialsByEmail(string $email): ?array { return null; }
            public function create(array $data): array { return $data; }
            public function update(int $id, array $data): array { return $data + ['id' => $id]; }
            public function storeRefresh(array $data): void {}
            public function findRefresh(string $jti): ?array { return null; }
            public function find(int $id): ?array { return null; }
            public function revokeRefresh(string $jti): void {}
            public function revokeAllForUser(int $userId): void {}
            public function bumpTokenVersion(int $userId): void {}
        };
        $this->expectExceptionMessage('Invalid refresh token');
        (new AuthService($store))->refresh(JWT::encodeAccess(7, 3));
    }
}
