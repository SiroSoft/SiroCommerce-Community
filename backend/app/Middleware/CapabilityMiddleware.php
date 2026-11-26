<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Role;
use App\Modules\Platform\Application\AdminWorkbenchService;
use Siro\Core\Gate;
use Siro\Core\Middleware\MiddlewareInterface;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Route capability gate, backed by Siro\Core\Gate (core >=1.2).
 *
 * Each `cap:<name>` registers a Gate ability of the same name on first use,
 * so controllers can also check imperatively:
 *   Gate::authorize('reports', $request->user());
 * Tests can use Gate::actingAs([...]) / Gate::reset() per core TESTING.md.
 *
 * Semantics (unchanged): a configured permission-set override wins, else the
 * static Role map, else extension-declared permissions (spec RULE-E7:
 * owner/admin hold every enabled extension's permissions). First allowed
 * capability short-circuits; otherwise the stable 403 envelope is returned.
 */
final class CapabilityMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, string ...$capabilities): mixed
    {
        $user = $request->user();
        $userArg = is_array($user) ? $user : null;
        foreach ($capabilities as $capability) {
            $ability = trim($capability);
            if ($ability === '') continue;
            if (!Gate::has($ability)) {
                Gate::define($ability, static fn(mixed $u): bool => self::check($u, $ability));
            }
            if (Gate::allows($ability, $userArg)) {
                return $next($request);
            }
        }

        return Response::error('Forbidden', 403, [
            'capability' => ['Insufficient permissions.'],
        ], 'FORBIDDEN');
    }

    private static function check(mixed $user, string $capability): bool
    {
        if (!is_array($user)) return false;
        $role = is_string($user['role'] ?? null) ? $user['role'] : '';
        if ($role === '') return false;
        $configured = AdminWorkbenchService::allowsConfigured($role, $capability);
        if ($configured ?? Role::can($role, $capability)) return true;
        // Extension-declared permissions (spec RULE-E7): owner/admin hold
        // every enabled extension's permissions.
        return \App\Extension\ExtensionRegistry::grants($role, $capability);
    }
}
