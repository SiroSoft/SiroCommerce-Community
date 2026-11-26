<?php

declare(strict_types=1);

namespace App;

/**
 * Staff roles (spec 08§1). Capability checks live in services;
 * route-level role gating via `auth:<role>` middleware params.
 */
final class Role
{
    public const OWNER = 'owner';
    public const ADMIN = 'admin';
    public const MARKETER = 'marketer';
    public const WAREHOUSE = 'warehouse';
    public const SUPPORT = 'support';
    public const FINANCE = 'finance';
    public const VIEWER = 'viewer';

    /** @var array<string, array<int, string>> */
    private const CAPABILITIES = [
        self::OWNER => ['*'],
        self::ADMIN => ['products', 'orders', 'inventory', 'customers', 'coupons', 'campaigns', 'seo', 'cms', 'users', 'reports', 'search', 'analytics', 'exports', 'operations', 'subscriptions', 'ai_suggestions', 'admin_workbench'],
        self::MARKETER => ['products', 'coupons', 'campaigns', 'seo', 'cms', 'analytics', 'ai_suggestions'],
        self::WAREHOUSE => ['inventory', 'orders', 'operations'],
        self::SUPPORT => ['orders', 'customers', 'operations', 'subscriptions'],
        self::FINANCE => ['finance', 'invoices', 'payments', 'refunds', 'credit_memos', 'exports', 'operations', 'subscriptions', 'ai_suggestions'],
        self::VIEWER => [],
    ];

    /** @return string[] */
    public static function all(): array
    {
        return [self::OWNER, self::ADMIN, self::MARKETER, self::WAREHOUSE, self::SUPPORT, self::FINANCE, self::VIEWER];
    }

    /** @return string[] */
    public static function capabilities(string $role): array
    {
        return self::CAPABILITIES[$role] ?? [];
    }

    public static function can(string $role, string $capability): bool
    {
        $caps = self::CAPABILITIES[$role] ?? [];
        // Reporting/search are read-only extensions of finance access; keep the
        // public capability list stable for existing clients.
        if ($role === self::FINANCE && in_array($capability, ['reports', 'search'], true)) return true;
        return in_array('*', $caps, true) || in_array($capability, $caps, true);
    }
}
