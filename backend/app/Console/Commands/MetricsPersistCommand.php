<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;
use Siro\Core\Metrics;

/**
 * Flush the persisted Prometheus metrics cache (storage/framework/metrics.json).
 *
 * The web process holds counters in memory and flushes on shutdown/flush
 * interval; this command reloads the on-disk snapshot and rewrites it so a
 * scheduled run can materialize the cache file even when no worker has
 * terminated cleanly. Registered through Console command discovery by its
 * signature and scheduled every 5 minutes in routes/schedule.php.
 */
final class MetricsPersistCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'metrics:persist';
    public string $description = 'Persist the in-memory metrics cache to storage/framework/metrics.json';

    public function __construct(private readonly string $basePath = '')
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $base = $this->basePath !== '' ? $this->basePath : ((defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd()));
        $previous = getcwd();
        try {
            // Metrics resolves its cache path relative to the working directory.
            if ($previous === false || !@chdir($base)) {
                $this->write('metrics:persist failed: cannot enter ' . $base);
                return 1;
            }
            Metrics::init('siro', true, 100);
            Metrics::persistNow();
            $file = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'metrics.json';
            if (!is_file($file)) {
                $this->write('metrics:persist failed: ' . $file . ' was not written');
                return 1;
            }
            $this->write('metrics persisted to ' . $file);
            return 0;
        } catch (\Throwable $e) {
            $this->write('metrics:persist failed: ' . $e->getMessage());
            return 1;
        } finally {
            if ($previous !== false) {
                @chdir($previous);
            }
        }
    }
}
