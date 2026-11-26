<?php

declare(strict_types=1);

namespace App\Modules\Shared\Hooks;

final class HookCatalog
{
    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (HookName $hook): string => $hook->value, HookName::catalog());
    }

    public static function contains(string|HookName $hook): bool
    {
        return in_array($hook instanceof HookName ? $hook->value : $hook, self::names(), true);
    }
}
