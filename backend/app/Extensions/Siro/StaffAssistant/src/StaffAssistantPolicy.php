<?php

declare(strict_types=1);

namespace App\Extensions\Siro\StaffAssistant;

use App\Modules\Platform\Application\AdminWorkbenchService;
use App\Role;
use Siro\Core\AuthorizationException;

/**
 * Server-side read policy for the staff assistant's structured context.
 *
 * The policy only looks at the authenticated principal supplied by the HTTP
 * layer, never at prompt text. Owners have broad read-only access; every other
 * role is limited to the capabilities already used by the admin routes.
 * Customer PII is an explicit owner-only permission.
 */
final class StaffAssistantPolicy
{
    public const SCOPE_ORDER = 'order';
    public const SCOPE_PRODUCT = 'product';
    public const SCOPE_CUSTOMER = 'customer';

    /** @var array<string, string> */
    private const SCOPE_CAPABILITY = [
        self::SCOPE_ORDER => 'orders',
        self::SCOPE_PRODUCT => 'products',
        self::SCOPE_CUSTOMER => 'customers',
    ];

    /** @param array<string, mixed>|null $user */
    public function allows(string $scope, ?array $user): bool
    {
        $capability = self::SCOPE_CAPABILITY[$scope] ?? null;
        if ($capability === null) {
            return false;
        }
        $role = $this->role($user);
        if ($role === Role::OWNER) {
            return true;
        }
        if ($role === '') {
            return false;
        }
        $configured = AdminWorkbenchService::allowsConfigured($role, $capability);

        return $configured ?? Role::can($role, $capability);
    }

    /** @param array<string, mixed>|null $user */
    public function assertAllowed(string $scope, ?array $user): void
    {
        if (!$this->allows($scope, $user)) {
            throw new AuthorizationException('Requested assistant context is not permitted for this role.');
        }
    }

    /** @param array<string, mixed>|null $user */
    public function mayExposeCustomerPii(?array $user): bool
    {
        return $this->role($user) === Role::OWNER;
    }

    /** @param array<string, mixed>|null $user */
    private function role(?array $user): string
    {
        $role = is_array($user) ? ($user['role'] ?? null) : null;

        return is_string($role) ? strtolower(trim($role)) : '';
    }
}
