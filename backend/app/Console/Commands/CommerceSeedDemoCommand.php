<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

final class CommerceSeedDemoCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'commerce:seed-demo';
    public string $description = 'Seed repeatable B2C, B2B, catalog and operations demo data';

    public function __construct(private readonly string $basePath) {}

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $command = [PHP_BINARY, $this->basePath . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'seed-demo.php'];
        if (in_array('--reset', $args, true)) $command[] = '--reset';
        foreach ($args as $arg) if (str_starts_with($arg, '--count=')) $command[] = $arg;
        $descriptor = [0 => ['file', 'php://stdin', 'r'], 1 => ['file', 'php://stdout', 'w'], 2 => ['file', 'php://stderr', 'w']];
        $handle = proc_open($command, $descriptor, $pipes, $this->basePath);
        return is_resource($handle) ? proc_close($handle) : 1;
    }
}
