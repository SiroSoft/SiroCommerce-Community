<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Extension\Application\ExtensionPackageInstaller;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/** Install a verified extension archive into app/Extensions (never enables it). */
final class ExtensionInstallCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'extension:install';
    public string $description = 'Install a signed extension package; enable it from Admin afterwards';

    public function __construct(private readonly string $basePath = '')
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $force = in_array('--force', $args, true);
        $requireSignature = in_array('--require-signature', $args, true);
        $zip = '';
        foreach ($args as $arg) if (!str_starts_with($arg, '--') && $zip === '') $zip = $arg;
        if ($zip === '') {
            $this->write('Usage: php siro extension:install <package.zip> [--force] [--require-signature]');
            return 1;
        }
        try {
            $result = (new ExtensionPackageInstaller($this->base()))->install($zip, $force, $requireSignature);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return 1;
        }
        $this->success('Installed ' . $result['id'] . ' ' . $result['version'] . ' at ' . $result['path']);
        if ($result['backup'] !== null) $this->warn('Previous version backed up at ' . $result['backup']);
        $this->write('');
        $this->write('Next steps:');
        $this->write('  php siro extension:check');
        $this->write('  php siro migrate');
        $this->write('  Admin -> Extensions -> ' . $result['id'] . ' -> Enable');
        return 0;
    }

    private function base(): string
    {
        return $this->basePath !== '' ? $this->basePath : ((defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd()));
    }
}
