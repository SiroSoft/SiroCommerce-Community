<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Siro\Core\Auth\JWT;
use Siro\Core\Database;
use Siro\Core\Request;

/**
 * Admin-console compatibility: the console speaks numeric ids and major-unit
 * money while commerce storage uses string ids and minor units. Numeric ids
 * are deterministic crc32 derivations of the stored string id.
 */
final class Skeleton
{
    public static function num(string $id): int
    {
        return (int) sprintf('%u', crc32($id));
    }

    public static function resolveId(string $table, int $num): ?string
    {
        $ids = Database::connection()->query('SELECT id FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            if (is_string($id) && self::num($id) === $num) return $id;
        }
        return null;
    }

    public static function major(int $minor): float
    {
        return round($minor / 100, 2);
    }

    public static function minor(mixed $major): ?int
    {
        if (!is_numeric($major)) return null;
        $minor = (int) round((float) $major * 100);
        return $minor < 0 ? null : $minor;
    }

    public static function date(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') return substr($value, 0, 19);
        if (is_array($value) && is_string($value['date'] ?? null)) return substr($value['date'], 0, 19);
        return null;
    }

    public static function isAdminConsole(?array $user): bool
    {
        return $user !== null && in_array($user['role'] ?? '', ['admin', 'owner'], true);
    }

    /**
     * Optional authentication for public routes shared with the console:
     * verifies a Bearer token like AuthMiddleware but returns null instead
     * of failing when no (valid) token is present.
     *
     * @return array<string, mixed>|null
     */
    public static function optionalUser(Request $request): ?array
    {
        $existing = $request->user();
        if (is_array($existing)) return $existing;
        $header = (string) $request->header('authorization', '');
        if (!str_starts_with(strtolower($header), 'bearer ')) return null;
        try {
            /** @var array<string, mixed> $claims */
            $claims = JWT::decode(trim(substr($header, 7)));
            $userId = isset($claims['sub']) && is_numeric($claims['sub']) ? (int) $claims['sub'] : 0;
            $version = isset($claims['ver']) && is_numeric($claims['ver']) ? (int) $claims['ver'] : 0;
            if (($claims['type'] ?? null) !== JWT::TYPE_ACCESS || $userId <= 0 || $version <= 0) return null;
            $model = User::find($userId);
            if ($model === null) return null;
            /** @var array<string, mixed> $row */
            $row = $model->toArray();
            if (($row['status'] ?? '') !== 'active' || (int) ($row['token_version'] ?? 0) !== $version) return null;
            return $row;
        } catch (\Throwable) { return null; }
    }
}
