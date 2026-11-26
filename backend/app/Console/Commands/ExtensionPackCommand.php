<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Extension\Application\ExtensionPackageInstaller;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/** Build a checksummed (optionally signed) extension archive. */
final class ExtensionPackCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'extension:pack';
    public string $description = 'Pack an extension directory into a checksummed/signed .zip';

    public function __construct(private readonly string $basePath = '')
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $source = '';
        $out = null;
        $key = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--out=')) { $out = substr($arg, strlen('--out=')); continue; }
            if (str_starts_with($arg, '--key=')) { $key = substr($arg, strlen('--key=')); continue; }
            if (!str_starts_with($arg, '--') && $source === '') $source = $arg;
        }
        if ($source === '') {
            $this->write('Usage: php siro extension:pack <extension-dir> [--out=dist/pkg.zip] [--key=key-id]');
            return 1;
        }
        $base = $this->basePath !== '' ? $this->basePath : ((defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd()));
        $path = str_starts_with($source, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:#', $source) === 1 ? $source : $base . DIRECTORY_SEPARATOR . $source;
        try {
            $result = (new ExtensionPackageInstaller($base))->pack($path, $out, $key);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return 1;
        }
        $this->success('Packed ' . $result['path']);
        $this->write('Files: ' . $result['files'] . ' | sha256: ' . $result['sha256'] . ' | signed: ' . ($result['signed'] ? 'yes' : 'no'));
        return 0;
    }
}
