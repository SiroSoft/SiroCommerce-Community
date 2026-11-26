<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Smart\Application\SmartAggregator;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/** Nightly rollups for no-model smart skills (spec SMART_SKILLS_SPEC §0). */
final class SmartAggregateCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'smart:aggregate';
    public string $description = 'Recompute smart affinities, search terms and review rollups';

    public function __construct(private readonly string $basePath = '')
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        try {
            $base = $this->basePath !== '' ? $this->basePath : ((defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd()));
            // Same database wiring as `siro migrate` (config file + env).
            $configFile = $base . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
            if (!is_file($configFile)) $configFile = dirname($base) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
            /** @var array<string, mixed> $config */
            $config = require $configFile;
            \Siro\Core\Database::configure($config);
            $result = (new SmartAggregator())->aggregateAll();
            $this->write(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (\Throwable $e) {
            $this->write('smart:aggregate failed: ' . $e->getMessage());
            return 1;
        }
    }
}
