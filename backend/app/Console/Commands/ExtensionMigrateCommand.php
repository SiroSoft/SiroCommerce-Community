<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Extension;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionMigrationRunner;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/** Run pending migrations of enabled extensions (spec RULE-E5). */
final class ExtensionMigrateCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'extension:migrate';
    public string $description = 'Run pending migrations of enabled extensions';

    public function __construct(private readonly string $basePath = '')
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $base = $this->basePath !== '' ? $this->basePath : ((defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd()));
        $enabled = [];
        try {
            $rows = Extension::query()->where('state', 'enabled')->get();
            foreach ($rows as $row) {
                $data = $row instanceof \Siro\Core\Model ? $row->toArray() : (array) $row;
                if (is_string($data['id'] ?? null)) $enabled[] = $data['id'];
            }
        } catch (\Throwable $exception) {
            $this->write('Extension migration skipped: ' . $exception->getMessage());
            return 0;
        }
        $classes = ExtensionDiscovery::classes($base);
        $ran = 0;
        foreach ($classes as $class) {
            try {
                $extension = new $class();
                if (!in_array($extension::id(), $enabled, true)) continue;
                $dir = ExtensionMigrationRunner::extensionDir($class);
                if ($dir === null) continue;
                $result = ExtensionMigrationRunner::migrate($extension::id(), $dir);
                foreach ($result['migrated'] as $name) {
                    $this->write('Migrated: ' . $name);
                    $ran++;
                }
            } catch (\Throwable $exception) {
                $this->write('Extension migration failed: ' . $exception->getMessage());
                return 1;
            }
        }
        if ($ran === 0) $this->write('Nothing to migrate.');
        return 0;
    }
}
