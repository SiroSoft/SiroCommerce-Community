<?php
declare(strict_types=1);
namespace App\Middleware;

use App\Models\User;
use App\Support\AuthScopeClaims;
use Siro\Core\Auth\JWT;
use Siro\Core\Middleware\MiddlewareInterface;
use Siro\Core\Request;
use Throwable;

/**
 * Optional JWT auth for guest-capable routes (quote, place): attaches the
 * user when a valid Bearer token is present so member pricing can apply,
 * but never rejects guests.
 */
final class OptionalAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, string ...$params): mixed
    {
        $header = (string) $request->header('authorization', '');
        if (!str_starts_with(strtolower($header), 'bearer ')) return $next($request);
        $token = trim(substr($header, 7));
        if ($token === '') return $next($request);
        try {
            /** @var array<string, mixed> $claims */
            $claims = JWT::decode($token);
            $sub = $claims['sub'] ?? 0;
            $ver = $claims['ver'] ?? 0;
            $userId = is_numeric($sub) ? (int) $sub : 0;
            $tokenVersion = is_numeric($ver) ? (int) $ver : 0;
            if (($claims['type'] ?? null) !== JWT::TYPE_ACCESS || $userId <= 0 || $tokenVersion <= 0) return $next($request);
            $model = User::find($userId);
            if ($model === null) return $next($request);
            /** @var array<string, mixed> $row */
            $row = $model->toArray();
            if (($row['status'] ?? '') !== 'active') return $next($request);
            if (!is_numeric($row['token_version'] ?? 0) || (int) $row['token_version'] !== $tokenVersion) return $next($request);
            /** @var array<string, mixed> $principal */
            $principal = ['id' => $userId, 'role' => is_string($row['role'] ?? null) ? $row['role'] : 'viewer'] + AuthScopeClaims::fromUser($claims);
            $request->setUser($principal);
        } catch (Throwable $e) {
        }
        return $next($request);
    }
}
