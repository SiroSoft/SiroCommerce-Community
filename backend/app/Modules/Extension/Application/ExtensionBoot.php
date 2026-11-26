<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use App\Contracts\ExtensionInterface;
use App\Extension\ExtensionRegistry;
use App\Models\Extension;
use Siro\Core\Database;

/**
 * Boot-time wiring (spec RULE-E3/E6): registers every DB-enabled extension
 * and calls boot(). Best effort by design — a missing extensions table (fresh
 * install, tests) or a broken extension must never break core boot.
 */
final class ExtensionBoot
{
    public static function boot(?string $basePath = null): void
    {
        try {
            $classes = ExtensionDiscovery::classes($basePath ?? self::basePath());
            if ($classes === []) return;
            $enabled = self::enabledIds();
            if ($enabled === []) return;
            foreach ($classes as $class) {
                try {
                    $extension = new $class();
                    if (!in_array($extension::id(), $enabled, true)) continue;
                    if (ExtensionRegistry::extension($extension::id()) !== null) continue;
                    ExtensionRegistry::register($extension);
                    $extension->boot();
                } catch (\Throwable $e) {
                    error_log('[extensions] boot failed for ' . $class . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log('[extensions] boot skipped: ' . $e->getMessage());
        }
    }

    /** @return list<string> */
    private static function enabledIds(): array
    {
        $pdo = Database::connection();
        $rows = $pdo->query("SELECT id FROM extensions WHERE state = 'enabled'")->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_string'));
    }

    private static function basePath(): string
    {
        return defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd();
    }
}
