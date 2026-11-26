<?php

declare(strict_types=1);

namespace App\Modules\Shared\Hooks;

/**
 * One-line dispatch for application code. Validates the payload contract,
 * runs handlers with failure isolation, and returns the (possibly mutated)
 * payload. A handler veto surfaces as RuntimeException('Hook rejected: …')
 * which controllers map to 422 with the reason.
 *
 * Priority convention: core handlers 0-99, extensions 100+.
 */
final class Hooks
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function fire(HookName $hook, array $payload): array
    {
        HookSchemas::assert($hook, $payload);
        return HookBus::shared()->dispatch($hook, $payload)['payload'];
    }

    /**
     * Notification-style dispatch for post-commit hooks (delivered,
     * registered, saved): a veto can no longer stop anything, so a reject is
     * logged and swallowed instead of thrown.
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function notify(HookName $hook, array $payload): array
    {
        try {
            return self::fire($hook, $payload);
        } catch (\RuntimeException $e) {
            error_log('[hooks] notify veto swallowed on ' . $hook->value . ': ' . $e->getMessage());
            return $payload;
        }
    }
}
