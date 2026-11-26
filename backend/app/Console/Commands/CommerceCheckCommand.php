<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

final class CommerceCheckCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'commerce:check';
    public string $description = 'Run SiroCommerce quality gates';

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $quick = in_array('--quick', $args, true);
        $continue = in_array('--continue', $args, true);
        $json = in_array('--json', $args, true);

        $phpstanCommand = ['vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G'];
        if (PHP_OS_FAMILY === 'Windows') {
            // Avoid PHPStan worker processes exhausting the Windows paging file.
            $phpstanCommand[] = '--debug';
        }

        /** @var list<array{name: string, command: list<string>}> $checks */
        $checks = [
            ['name' => 'phpstan', 'command' => $phpstanCommand],
            ['name' => 'mapping', 'command' => ['scripts/check-mapping.php']],
            ['name' => 'i18n', 'command' => ['scripts/check-i18n.php']],
            ['name' => 'audit', 'command' => ['scripts/check-audit.php']],
            ['name' => 'api-coverage', 'command' => ['scripts/check-api-coverage.php']],
            ['name' => 'module-boundaries', 'command' => ['scripts/check-v2-boundaries.php']],
            ['name' => 'native-runtime', 'command' => ['scripts/check-native-runtime.php']],
            ['name' => 'production-proof', 'command' => ['scripts/check-production-proof.php']],
        ];

        if (!$quick) {
            $checks[] = ['name' => 'tests', 'command' => ['vendor/bin/phpunit', '--no-coverage', '-c', 'phpunit.xml']];
        }

        $results = [];
        $failed = false;
        foreach ($checks as $check) {
            $this->write('[' . $check['name'] . ']');
            $exitCode = $this->runProcess($check['command']);
            $passed = $exitCode === 0;
            $results[] = ['name' => $check['name'], 'passed' => $passed, 'exit_code' => $exitCode];
            $failed = $failed || !$passed;

            if (!$passed && !$continue) {
                break;
            }
        }

        if ($json) {
            $this->write(json_encode(['passed' => !$failed, 'checks' => $results], JSON_THROW_ON_ERROR));
        }

        return $failed ? 1 : 0;
    }

    /** @param list<string> $command */
    private function runProcess(array $command): int
    {
        array_unshift($command, PHP_BINARY);
        $descriptor = [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ];
        $handle = proc_open($command, $descriptor, $pipes, $this->basePath);
        if (!is_resource($handle)) {
            return 1;
        }

        return proc_close($handle);
    }
}
