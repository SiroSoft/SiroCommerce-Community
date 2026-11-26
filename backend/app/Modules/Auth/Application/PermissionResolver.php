<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Role;
use Siro\Core\Database;

/**
 * Resolves the effective admin capabilities for a user role.
 *
 * The precedence mirrors App\Middleware\CapabilityMiddleware: an explicit
 * admin_permission_sets row wins (even when empty), otherwise the static
 * Role map applies. Role::can() read-only extras (finance reports/search) are
 * folded in so the payload matches what route gates actually allow.
 */
final class PermissionResolver
{
    /** @return list<string> */
    public static function forRole(string $role): array
    {
        $role = strtolower(trim($role));
        if ($role === '') return [];
        if ($role === Role::OWNER) return ['*'];

        $configured = self::configured($role);
        if ($configured !== null) return self::normalize($configured);

        $capabilities = Role::capabilities($role);
        foreach (Role::capabilities(Role::ADMIN) as $capability) {
            if (!in_array($capability, $capabilities, true) && Role::can($role, $capability)) {
                $capabilities[] = $capability;
            }
        }

        return self::normalize($capabilities);
    }

    /** @return list<string>|null */
    private static function configured(string $role): ?array
    {
        try {
            $statement = Database::connection()->prepare('SELECT permissions_json FROM admin_permission_sets WHERE role=?');
            $statement->execute([$role]);
            $raw = $statement->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($raw)) return null;
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? self::normalize($decoded) : null;
    }

    /**
     * @param array<mixed> $capabilities
     * @return list<string>
     */
    private static function normalize(array $capabilities): array
    {
        $out = [];
        foreach ($capabilities as $capability) {
            if (!is_string($capability)) continue;
            $capability = trim($capability);
            if ($capability === '' || in_array($capability, $out, true)) continue;
            $out[] = $capability;
        }
        sort($out);

        return $out;
    }
}
