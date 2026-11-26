<?php
declare(strict_types=1);
namespace App\Support;

/** Enforces that a principal can only address the resolved tenant/site scope. */
final class ScopeAuthorizer
{
    /** @param array<string, mixed> $claims */
    public static function assertAllowed(CommerceScope $scope, array $claims): void
    {
        foreach (['tenant_id' => $scope->tenantId, 'seller_id' => $scope->sellerId, 'site_id' => $scope->siteId] as $name => $value) {
            if ($value === null) continue;
            $allowed = $claims[$name . 's'] ?? $claims[$name] ?? null;
            // An omitted claim is not an authorization grant. This keeps a
            // partially populated principal from addressing an arbitrary scope.
            if ($allowed === null) throw new CommerceScopeException('Commerce scope is not authorized.');
            $values = is_array($allowed) ? $allowed : [$allowed];
            $normalized = [];
            foreach ($values as $item) if (is_scalar($item)) $normalized[] = (string) $item;
            $values = $normalized;
            if (!in_array($value, $values, true)) throw new CommerceScopeException('Commerce scope is not authorized.');
        }
    }
}
