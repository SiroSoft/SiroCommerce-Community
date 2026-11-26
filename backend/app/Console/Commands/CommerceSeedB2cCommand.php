<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

final class CommerceSeedB2cCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'commerce:seed-b2c';
    public string $description = 'Seed the Vietnamese B2C catalog for the default web store';

    public function __construct(private readonly string $basePath) {}

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $handle = proc_open([PHP_BINARY, $this->basePath . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'seed-b2c.php'], [0 => ['file', 'php://stdin', 'r'], 1 => ['file', 'php://stdout', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes, $this->basePath);
        return is_resource($handle) ? proc_close($handle) : 1;
    }
}
