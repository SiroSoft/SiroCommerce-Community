<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\User;
use App\Support\AuthScopeClaims;
use Siro\Core\Auth\JWT;
use Siro\Core\Logger;
use Siro\Core\Middleware\MiddlewareInterface;
use Siro\Core\Request;
use Siro\Core\Response;
use Throwable;

/**
 * JWT auth (spec 08§1). Verifies sub/ver claims + live token_version,
 * then role-gates via `auth:<role,...>` params.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, string ...$roles): mixed
    {
        $header = (string) $request->header('authorization', '');
        if (!str_starts_with(strtolower($header), 'bearer ')) {
            return Response::error('Unauthorized', 401, ['token' => ['Invalid or expired token']], 'UNAUTHORIZED');
        }
        $token = trim(substr($header, 7));
        try {
            /** @var array<string, mixed> $claims */
            $claims = JWT::decode($token);
            $sub = $claims['sub'] ?? 0;
            $ver = $claims['ver'] ?? 0;
            $userId = is_numeric($sub) ? (int) $sub : 0;
            $tokenVersion = is_numeric($ver) ? (int) $ver : 0;
            if (($claims['type'] ?? null) !== JWT::TYPE_ACCESS || $userId <= 0 || $tokenVersion <= 0) {
                return Response::error('Unauthorized', 401, ['token' => ['Invalid or expired token']], 'UNAUTHORIZED');
            }
            $userData = self::resolveUser($userId, $tokenVersion);
            if ($userData === null) {
                return Response::error('Unauthorized', 401, ['token' => ['Invalid or expired token']], 'UNAUTHORIZED');
            }
            $role = $userData['role'];
            if (!is_string($role)) {
                return Response::error('Unauthorized', 401, ['token' => ['Invalid or expired token']], 'UNAUTHORIZED');
            }
            /** @var array<string, mixed> $principal */
            $principal = $userData + AuthScopeClaims::fromUser($claims);
            $request->setUser($principal);
            if ($roles !== []) {
                $allowed = false;
                foreach ($roles as $required) {
                    if (strtolower($role) === strtolower(trim($required))) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    return Response::error('Forbidden', 403, [
                        'role' => ['Insufficient permissions. Required: ' . implode(', ', $roles)],
                    ], 'FORBIDDEN');
                }
            }
        } catch (Throwable $e) {
            Logger::warning('JWT auth failed: ' . $e->getMessage());
            return Response::error('Unauthorized', 401, ['token' => ['Invalid or expired token']], 'UNAUTHORIZED');
        }
        return $next($request);
    }

    /** @return array<string, mixed>|null */
    private static function resolveUser(int $userId, int $tokenVersion): ?array
    {
        $model = User::find($userId);
        if ($model === null) {
            return null;
        }
        /** @var array<string, mixed> $row */
        $row = $model->toArray();
        $status = $row['status'] ?? '';
        $dbVersion = $row['token_version'] ?? 0;
        if (!is_string($status) || $status !== 'active') {
            return null;
        }
        if (!is_numeric($dbVersion) || (int) $dbVersion !== $tokenVersion) {
            return null;
        }
        $id = $row['id'] ?? 0;
        $name = $row['name'] ?? '';
        $email = $row['email'] ?? '';
        $role = $row['role'] ?? 'viewer';
        $verifiedAt = $row['email_verified_at'] ?? null;
        return [
            'id' => is_numeric($id) ? (int) $id : 0,
            'name' => is_string($name) ? $name : '',
            'email' => is_string($email) ? $email : '',
            'role' => is_string($role) ? $role : 'viewer',
            'status' => $status,
            'token_version' => (int) $dbVersion,
            'email_verified_at' => is_string($verifiedAt) ? substr($verifiedAt, 0, 19) : null,
        ];
    }
}
