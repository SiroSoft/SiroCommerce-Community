<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use Siro\Core\Database;

/**
 * Extension schema runner (spec RULE-E5): runs an extension's migrations
 * after core, tracking them as ext::<id>::<file> in the shared migrations
 * table. Rollback runs down() in reverse and deletes the rows.
 */
final class ExtensionMigrationRunner
{
    /**
     * @return array{migrated: list<string>, skipped: list<string>}
     */
    public static function migrate(string $extensionId, string $extensionDir): array
    {
        $migrated = [];
        $skipped = [];
        $pdo = Database::connection();
        self::ensureTable($pdo);
        $batch = self::nextBatch($pdo);
        foreach (self::files($extensionDir) as $file) {
            $name = 'ext::' . $extensionId . '::' . basename($file);
            if (self::isRecorded($pdo, $name)) {
                $skipped[] = $name;
                continue;
            }
            $migration = require $file;
            if (!is_object($migration) || !method_exists($migration, 'up')) {
                throw new \RuntimeException('Invalid extension migration: ' . basename($file));
            }
            $migration->up();
            $stmt = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
            $stmt->execute([$name, $batch]);
            $migrated[] = $name;
        }
        return ['migrated' => $migrated, 'skipped' => $skipped];
    }

    /**
     * @return array{rolled_back: list<string>}
     */
    public static function rollback(string $extensionId, string $extensionDir): array
    {
        $rolledBack = [];
        $pdo = Database::connection();
        self::ensureTable($pdo);
        $files = array_reverse(self::files($extensionDir));
        foreach ($files as $file) {
            $name = 'ext::' . $extensionId . '::' . basename($file);
            if (!self::isRecorded($pdo, $name)) continue;
            $migration = require $file;
            if (!is_object($migration) || !method_exists($migration, 'down')) {
                throw new \RuntimeException('Extension migration has no down(): ' . basename($file) . ' — data kept, state left disabled');
            }
            $migration->down();
            $stmt = $pdo->prepare('DELETE FROM migrations WHERE migration = ?');
            $stmt->execute([$name]);
            $rolledBack[] = $name;
        }
        return ['rolled_back' => $rolledBack];
    }

    /** @return list<string> */
    public static function files(string $extensionDir): array
    {
        $dir = rtrim($extensionDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($dir)) return [];
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);
        return array_values($files);
    }

    public static function extensionDir(string $class): ?string
    {
        try {
            $file = (new \ReflectionClass($class))->getFileName();
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($file)) return null;
        $dir = dirname($file);
        // Single-file extensions keep migrations/ next to the class file;
        // vendor/name packages keep them at the package root.
        if (is_dir($dir . DIRECTORY_SEPARATOR . 'migrations')) return $dir;
        $parent = dirname($dir);
        if (basename($dir) === 'src' && is_dir($parent . DIRECTORY_SEPARATOR . 'migrations')) return $parent;
        return $dir;
    }

    private static function isRecorded(\PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
        $stmt->execute([$name]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    private static function nextBatch(\PDO $pdo): int
    {
        $max = $pdo->query('SELECT MAX(batch) FROM migrations')->fetchColumn();
        return ((int) $max) + 1;
    }

    private static function ensureTable(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id BIGSERIAL PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1)');
        } elseif ($driver === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1)');
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1)');
        }
    }
}
