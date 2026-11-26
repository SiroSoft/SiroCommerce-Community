<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Extension\Application\BagistoCompatibilityAnalyzer;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/** Report how a Bagisto extension/theme maps to Siro and draft a manifest. */
final class ExtensionCompatCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'extension:compat';
    public string $description = 'Analyze a Bagisto extension/theme and produce a Siro compatibility report';

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $path = '';
        foreach ($args as $arg) if (!str_starts_with($arg, '--') && $path === '') $path = $arg;
        if ($path === '') {
            $this->write('Usage: php siro extension:compat <bagisto-package-dir> [--json]');
            return 1;
        }
        try {
            $report = (new BagistoCompatibilityAnalyzer())->analyze($path);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return 1;
        }
        $this->write((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->write('');
        $this->info('Level: ' . $report['level'] . ' (' . $report['score'] . '/100)');
        $this->info('Port required: ' . (count($report['port_required']) > 0 ? implode(', ', $report['port_required']) : 'none'));
        if ($report['unsupported'] !== []) $this->warn('Unsupported without port: ' . implode(', ', $report['unsupported']));
        return 0;
    }
}
