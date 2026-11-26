<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Launch\Application\LaunchStatusService;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;
use Siro\Core\Database;

/** Launch readiness checks shared with GET /api/admin/launch/status. */
final class LaunchCheckCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'launch:check';
    public string $description = 'Run the storefront launch readiness checklist';

    public function __construct(private readonly string $basePath = '')
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $base = $this->basePath !== '' ? $this->basePath : (string) getcwd();
        try {
            $configFile = $base . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
            /** @var array<string, mixed> $config */
            $config = require $configFile;
            Database::configure($config);
        } catch (\Throwable $error) {
            $this->write('launch:check failed to configure the database: ' . $error->getMessage());
            return 1;
        }

        $status = (new LaunchStatusService())->status();
        $rows = [];
        $failed = false;
        foreach ($status['checks'] as $check) {
            $failed = $failed || $check['state'] === 'fail';
            $rows[] = [(string) $check['key'], (string) $check['state'], (string) $check['hint'], (string) $check['detail']];
        }
        $this->table(['Check', 'State', 'Hint', 'Detail'], $rows);
        $this->write(sprintf(
            'Score: %d/100 — %s',
            $status['score'],
            $status['ready'] ? 'READY' : ($failed ? 'BLOCKED' : 'NEEDS ATTENTION'),
        ));

        return $failed ? 1 : 0;
    }
}
