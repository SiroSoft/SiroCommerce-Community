<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Extension\Application\ExtensionPackageInstaller;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/** Verify a signed Siro extension archive without changing state. */
final class ExtensionVerifyCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'extension:verify';
    public string $description = 'Verify an extension package (manifest, checksums, signature, paths)';

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
            $this->write('Usage: php siro extension:verify <package.zip> [--require-signature]');
            return 1;
        }
        $report = (new ExtensionPackageInstaller($this->base()))->verify($zip, $requireSignature);
        $this->write((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (!$report['ok']) {
            $this->error('Package verification failed');
            return 1;
        }
        $this->success('Package verified: ' . (is_string($report['id'] ?? null) ? $report['id'] : '') . ' ' . (is_string($report['version'] ?? null) ? $report['version'] : ''));
        return 0;
    }

    private function base(): string
    {
        return $this->basePath !== '' ? $this->basePath : ((defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd()));
    }
}
