<?php
declare(strict_types=1);
namespace App\Modules\Auth\Application;
use App\Modules\Auth\Contracts\UserStore;
use App\Support\Ids;
use App\Modules\Auth\Domain\AuthTokens;
use App\Role;
use App\Support\Arr;
use App\Support\AuthScopeClaims;
use Siro\Core\Auth\JWT;
use Siro\Core\Database;
final class AuthService
{
    public function __construct(private readonly UserStore $users) {}
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function register(array $data): array
    {
        $email = Arr::requiredStr($data, 'email');
        if ($this->users->findByEmail($email) !== null) throw new \RuntimeException('Email already taken');
        $role = Arr::str($data, 'role', Role::VIEWER);
        if (!in_array($role, Role::all(), true)) $role = Role::VIEWER;
        return $this->users->create(['name' => Arr::str($data, 'name'), 'email' => $email, 'password' => password_hash(Arr::requiredStr($data, 'password'), PASSWORD_DEFAULT), 'role' => $role, 'status' => 'active', 'token_version' => 1]);
    }
    public function login(string $email, string $password): AuthTokens
    {
        $user = $this->users->findCredentialsByEmail($email);
        if ($user === null || !password_verify($password, Arr::str($user, 'password'))) throw new \RuntimeException('Invalid credentials');
        if (Arr::str($user, 'status') !== 'active') throw new \RuntimeException('Account disabled');
        $id = Arr::requiredInt($user, 'id'); $version = Arr::int($user, 'token_version', 1); $jti = bin2hex(random_bytes(16));
        $this->users->storeRefresh(['user_id' => $id, 'jti' => $jti, 'revoked' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + 604800)]);
        $scopes = AuthScopeClaims::fromUser($user);
        return new AuthTokens($this->encodeAccess($id, $version, $scopes), $this->encodeRefresh($id, $version, $jti, $scopes), $this->publicUser($user));
    }
    public function refresh(string $token): AuthTokens
    {
        /** @var array<string, mixed> $claims */ $claims = JWT::decode($token);
        if (($claims['type'] ?? null) !== JWT::TYPE_REFRESH) throw new \RuntimeException('Invalid refresh token');
        $jti = Arr::str($claims, 'jti'); $stored = $this->users->findRefresh($jti);
        if ($stored === null || Arr::int($stored, 'revoked') !== 0) throw new \RuntimeException('Refresh token revoked');
        $id = Arr::requiredInt($stored, 'user_id'); $user = $this->users->find($id);
        if ($user === null) throw new \RuntimeException('User not found');
        if (!is_int($claims['ver'] ?? null) || $claims['ver'] !== Arr::int($user, 'token_version', 1) || !is_int($claims['sub'] ?? null) || $claims['sub'] !== $id) throw new \RuntimeException('Token version mismatch');
        $this->users->revokeRefresh($jti); $newJti = bin2hex(random_bytes(16)); $version = Arr::int($user, 'token_version', 1);
        $this->users->storeRefresh(['user_id' => $id, 'jti' => $newJti, 'revoked' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + 604800)]);
        $scopes = AuthScopeClaims::fromUser($user);
        return new AuthTokens($this->encodeAccess($id, $version, $scopes), $this->encodeRefresh($id, $version, $newJti, $scopes));
    }
    public function logout(int $id): void { $this->users->revokeAllForUser($id); $this->users->bumpTokenVersion($id); }

    /**
     * Issue a password-reset token. Returns the raw token, or null when the
     * email is unknown (callers must respond identically either way).
     */
    public function forgotPassword(string $email): ?string
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if ($user === null) return null;
        return $this->issueRecoveryToken(Arr::requiredInt($user, 'id'), Arr::str($user, 'email'), 'password_reset', 3600);
    }

    public function resetPassword(string $token, string $new): void
    {
        if (strlen($new) < 8) throw new \InvalidArgumentException('Password must be at least 8 characters');
        $record = $this->consumeRecoveryToken($token, 'password_reset');
        $this->users->update(Arr::requiredInt($record, 'user_id'), ['password' => password_hash($new, PASSWORD_DEFAULT)]);
        $this->users->bumpTokenVersion(Arr::requiredInt($record, 'user_id'));
    }

    /**
     * Issue an email-verification token for the user. Returns the raw token.
     */
    public function resendVerificationEmail(int $id): string
    {
        $user = $this->users->find($id);
        if ($user === null) throw new \RuntimeException('User not found');
        return $this->issueRecoveryToken($id, Arr::str($user, 'email'), 'email_verification', 86400);
    }

    /** @return array<string, mixed> */
    public function verifyEmail(string $token): array
    {
        $record = $this->consumeRecoveryToken($token, 'email_verification');
        return $this->users->update(Arr::requiredInt($record, 'user_id'), ['email_verified_at' => date('Y-m-d H:i:s')]);
    }

    private function issueRecoveryToken(int $userId, string $email, string $purpose, int $ttlSeconds): string
    {
        $raw = bin2hex(random_bytes(32));
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM auth_recovery_tokens WHERE user_id=? AND purpose=? AND consumed_at IS NULL')->execute([$userId, $purpose]);
        $pdo->prepare('INSERT INTO auth_recovery_tokens (id,user_id,email,purpose,token_hash,expires_at,created_at) VALUES (?,?,?,?,?,?,?)')->execute([Ids::new(), $userId, $email, $purpose, hash('sha256', $raw), date('Y-m-d H:i:s', time() + $ttlSeconds), date('Y-m-d H:i:s')]);
        return $raw;
    }

    /** @return array<string, mixed> */
    private function consumeRecoveryToken(string $token, string $purpose): array
    {
        if ($token === '') throw new \RuntimeException('Recovery token is invalid');
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM auth_recovery_tokens WHERE token_hash=? AND purpose=? AND consumed_at IS NULL');
        $statement->execute([hash('sha256', $token), $purpose]);
        $record = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($record)) throw new \RuntimeException('Recovery token is invalid');
        if (($record['expires_at'] ?? '') < date('Y-m-d H:i:s')) throw new \RuntimeException('Recovery token has expired');
        $pdo->prepare('UPDATE auth_recovery_tokens SET consumed_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $record['id']]);
        /** @var array<string, mixed> $out */
        $out = $record;
        return $out;
    }
    public function changePassword(int $id, string $current, string $new): void
    {
        if (strlen($new) < 8) throw new \InvalidArgumentException('Password must be at least 8 characters');
        $user = $this->users->find($id);
        if ($user === null) throw new \RuntimeException('User not found');
        $credentials = $this->users->findCredentialsByEmail(Arr::str($user, 'email'));
        if ($credentials === null || !password_verify($current, Arr::str($credentials, 'password'))) throw new \RuntimeException('Current password is incorrect');
        $this->users->update($id, ['password' => password_hash($new, PASSWORD_DEFAULT)]);
        $this->users->revokeAllForUser($id);
        $this->users->bumpTokenVersion($id);
    }
    /** @return array<string, mixed> */
    public function updateProfile(int $id, string $name, ?string $birthdate = null): array
    {
        $name = trim($name);
        if ($name === '') throw new \InvalidArgumentException('Name is required');
        $patch = ['name' => $name];
        if ($birthdate !== null) {
            $clean = \App\Modules\Promotion\Application\MemberDealService::normalizeBirthdate($birthdate);
            if ($clean === null) throw new \InvalidArgumentException('Birthdate must be a past YYYY-MM-DD date');
            $patch['birthdate'] = $clean;
        }
        return $this->users->update($id, $patch);
    }
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function publicUser(array $user): array { $role = Arr::str($user, 'role'); return ['id' => Arr::int($user, 'id'), 'name' => Arr::str($user, 'name'), 'email' => Arr::str($user, 'email'), 'role' => $role, 'capabilities' => Role::capabilities($role), 'status' => Arr::str($user, 'status'), 'birthdate' => Arr::nullableStr($user, 'birthdate')]; }

    /** @param array<string, list<string>> $scopes */
    private function encodeAccess(int $id, int $version, array $scopes): string
    {
        return JWT::encode($this->payload($id, $version, JWT::TYPE_ACCESS, 3600, null, $scopes));
    }

    /** @param array<string, list<string>> $scopes */
    private function encodeRefresh(int $id, int $version, string $jti, array $scopes): string
    {
        return JWT::encode($this->payload($id, $version, JWT::TYPE_REFRESH, 604800, $jti, $scopes));
    }

    /**
     * @param array<string, list<string>> $scopes
     * @return array<string, mixed>
     */
    private function payload(int $id, int $version, string $type, int $ttl, ?string $jti, array $scopes): array
    {
        $now = time();
        return ['sub' => $id, 'ver' => max(1, $version), 'iat' => $now, 'exp' => $now + $ttl, 'type' => $type, 'jti' => $jti ?? bin2hex(random_bytes(16)), 'iss' => rtrim((string) \Siro\Core\Env::get('APP_URL', ''), '/') ?: 'siro-api'] + $scopes;
    }
}
