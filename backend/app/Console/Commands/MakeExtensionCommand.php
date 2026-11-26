<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ExtensionScaffolder;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/** Scaffold an extension package under app/Extensions (spec RULE-E1). */
final class MakeExtensionCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'make:extension';
    public string $description = 'Scaffold a vendor/name extension package under app/Extensions';

    public function __construct(private readonly string $basePath = '')
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $force = in_array('--force', $args, true);
        $vendor = '';
        $name = '';
        $positionals = [];

        foreach ($args as $arg) {
            if ($arg === '--force') continue;
            if (str_starts_with($arg, '--vendor=')) {
                $vendor = substr($arg, strlen('--vendor='));
                continue;
            }
            if (str_starts_with($arg, '--name=')) {
                $name = substr($arg, strlen('--name='));
                continue;
            }
            if (str_starts_with($arg, '--')) continue;
            $positionals[] = $arg;
        }

        if ($vendor === '' && $name === '') {
            $vendor = $positionals[0] ?? '';
            $name = $positionals[1] ?? '';
        } elseif ($vendor === '') {
            $vendor = $positionals[0] ?? '';
        } elseif ($name === '') {
            $name = $positionals[0] ?? '';
        }

        if ($vendor === '' || $name === '') {
            $this->write('Usage: php siro make:extension <Vendor> <Name> [--vendor=Vendor] [--name=Name] [--force]');
            return 1;
        }

        $root = $this->basePath !== ''
            ? $this->basePath
            : ((defined('BASE_PATH') && is_string(BASE_PATH)) ? BASE_PATH : (string) getcwd());

        try {
            $result = ExtensionScaffolder::create($vendor, $name, $root, $force);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return 1;
        }

        foreach ($result['created'] as $path) {
            $this->success('Created ' . $this->relativePath($root, $path));
        }
        foreach ($result['skipped'] as $path) {
            $this->warn('Skipped existing ' . $this->relativePath($root, $path));
        }

        if ($result['created'] === []) {
            if ($result['skipped'] !== []) {
                $this->write('Extension already exists. Re-run with --force to overwrite generated files.');
            }
            return 0;
        }

        $id = strtolower($vendor) . '/' . strtolower($name);
        $this->write('');
        $this->write('Next steps:');
        $this->write('  php siro extension:check');
        $this->write('  php siro extension:migrate');
        $this->write('  Enable in Admin -> Extensions -> ' . $id);
        $this->write('  Test: GET /api/ext/' . $id . '/status');

        return 0;
    }

    private function relativePath(string $root, string $path): string
    {
        $prefix = rtrim($root, "\\/") . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
