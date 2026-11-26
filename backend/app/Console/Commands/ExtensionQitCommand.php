<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Extension\Application\ExtensionQualityGate;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/** P1 QIT-lite gate for extension archives. */
final class ExtensionQitCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'extension:qit';
    public string $description = 'Run Siro QIT-lite checks on an extension package';

    public function __construct(private readonly string $basePath = '')
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $requireSignature = in_array('--require-signature', $args, true);
        $zip = '';
        foreach ($args as $arg) if (!str_starts_with($arg, '--') && $zip === '') $zip = $arg;
        if ($zip === '') {
            $this->write('Usage: php siro extension:qit <package.zip> [--require-signature]');
            return 1;
        }
        $base = $this->basePath !== '' ? $this->basePath : ((defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd()));
        $report = (new ExtensionQualityGate($base))->run($zip, $requireSignature);
        $this->write((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        foreach ($report['checks'] as $check) {
            if ($check['ok']) $this->success($check['name']);
            else $this->error($check['name'] . ': ' . implode('; ', $check['details']));
        }
        return $report['ok'] ? 0 : 1;
    }
}
