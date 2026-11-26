<?php

declare(strict_types=1);

/**
 * Build signed Pro artifacts for the private feed.
 *
 * This is the release-side companion to the checked-in catalog overlay. It only
 * packs Pro extensions (the Affiliate reference module and any discovered
 * extension whose manifest declares a license requirement). Signing uses
 * EXTENSION_SIGNING_PRIVATE_KEY from the environment; the key is never printed,
 * persisted or copied into the artifact directory.
 *
 * Usage:
 *   php scripts/ecosystem-catalog/package-pro.php --json
 *   php scripts/ecosystem-catalog/package-pro.php --out=backend/storage/ecosystem/pro --key=key-1
 *   php scripts/ecosystem-catalog/package-pro.php --source=backend/app/Extensions/Siro/Affiliate --json
 *
 * Exit codes:
 *   0 success, 2 usage error, 3 signing prerequisites missing,
 *   4 a package failed to pack/verify.
 */

use App\Contracts\ExtensionInterface;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionPackageInstaller;

const EXIT_OK = 0;
const EXIT_USAGE = 2;
const EXIT_PREREQ = 3;
const EXIT_PACK = 4;

/**
 * @return array{out: string, key: ?string, ids: list<string>, sources: list<string>, json: bool, help: bool}
 */
function packageProArguments(array $argv): array
{
    $options = [
        'out' => '',
        'key' => null,
        'ids' => [],
        'sources' => [],
        'json' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--json') {
            $options['json'] = true;
            continue;
        }
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if (str_starts_with($argument, '--out=')) {
            $options['out'] = substr($argument, strlen('--out='));
            continue;
        }
        if (str_starts_with($argument, '--key=')) {
            $options['key'] = substr($argument, strlen('--key='));
            continue;
        }
        if (str_starts_with($argument, '--id=')) {
            $id = substr($argument, strlen('--id='));
            if ($id !== '') {
                $options['ids'][] = $id;
            }
            continue;
        }
        if (str_starts_with($argument, '--source=')) {
            $source = substr($argument, strlen('--source='));
            if ($source !== '') {
                $options['sources'][] = $source;
            }
            continue;
        }

        packageProFail(EXIT_USAGE, 'Unknown option: ' . $argument);
    }

    return $options;
}

function packageProUsage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php scripts/ecosystem-catalog/package-pro.php [--json]
  php scripts/ecosystem-catalog/package-pro.php --out=DIR --key=KEY_ID
  php scripts/ecosystem-catalog/package-pro.php --source=PATH [--source=PATH...]

Options:
  --out=DIR        Artifact directory. Default: PRO_ARTIFACT_DIR or
                   backend/storage/ecosystem/pro.
  --key=KEY_ID     Signature key id written to signature.json.
                   Default: EXTENSION_SIGNING_KEY_ID or "default".
  --id=ID          Only pack this extension id (repeatable).
  --source=PATH    Pack an explicit extension directory (repeatable) instead of
                   discovering Pro extensions.
  --json           Print machine-readable metadata on stdout.
  --help, -h       Show this help.

Signing uses EXTENSION_SIGNING_PRIVATE_KEY. The key is never printed or stored.
Exit codes: 0 ok, 2 usage, 3 signing/tooling prerequisite missing, 4 pack failed.

TXT);
}

function packageProFail(int $code, string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit($code);
}

function packageProRoot(): string
{
    return dirname(__DIR__, 2);
}

function packageProBackend(): string
{
    return packageProRoot() . DIRECTORY_SEPARATOR . 'backend';
}

function packageProOutDir(string $configured): string
{
    $raw = $configured;
    if ($raw === '') {
        $raw = (string) (getenv('PRO_ARTIFACT_DIR') ?: '');
    }
    if ($raw === '') {
        $raw = 'storage/ecosystem/pro';
    }
    if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $raw) !== 1) {
        $raw = packageProBackend() . DIRECTORY_SEPARATOR . $raw;
    }

    return rtrim($raw, '/\\');
}

/**
 * @return array<string, true>
 */
function packageProCatalogProIds(string $backend): array
{
    $file = $backend . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'ecosystem' . DIRECTORY_SEPARATOR . 'catalog.json';
    $raw = @file_get_contents($file);
    if (!is_string($raw)) {
        return [];
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    if (!is_array($data) || !is_array($data['items'] ?? null)) {
        return [];
    }
    $ids = [];
    foreach ($data['items'] as $item) {
        if (is_array($item) && ($item['edition'] ?? null) === 'pro' && is_string($item['id'] ?? null)) {
            $ids[$item['id']] = true;
        }
    }

    return $ids;
}

/**
 * @param array<string, true> $catalogProIds
 * @param list<string> $wantedIds
 * @return list<array{id: string, source: string, version: string, catalog: bool}>
 */
function packageProDiscoveredSources(string $backend, array $catalogProIds, array $wantedIds): array
{
    $wanted = array_fill_keys($wantedIds, true);
    $sources = [];
    foreach (ExtensionDiscovery::classes($backend) as $class) {
        if (!is_a($class, ExtensionInterface::class, true)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();
        if (!is_string($file)) {
            continue;
        }
        $dir = dirname($file);
        $manifestFile = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifestFile)) {
            continue;
        }
        $raw = @file_get_contents($manifestFile);
        if (!is_string($raw)) {
            continue;
        }
        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            continue;
        }
        if (!is_array($manifest)) {
            continue;
        }
        $id = $manifest['id'] ?? null;
        $version = $manifest['version'] ?? null;
        if (!is_string($id) || !is_string($version)) {
            continue;
        }
        if ($wanted !== [] && !isset($wanted[$id])) {
            continue;
        }
        $licenseRequired = is_array($manifest['license'] ?? null)
            && ($manifest['license']['required'] ?? false) === true;
        if (!$licenseRequired && !isset($catalogProIds[$id])) {
            continue;
        }
        $sources[$id] = ['id' => $id, 'source' => $dir, 'version' => $version, 'catalog' => isset($catalogProIds[$id])];
    }

    ksort($sources);

    return array_values($sources);
}

/**
 * @param list<string> $explicitSources
 * @param list<string> $wantedIds
 * @return list<array{id: string, source: string, version: string, catalog: bool}>
 */
function packageProExplicitSources(array $explicitSources, array $wantedIds): array
{
    $wanted = array_fill_keys($wantedIds, true);
    $sources = [];
    foreach ($explicitSources as $source) {
        $dir = realpath($source);
        if ($dir === false || !is_dir($dir)) {
            packageProFail(EXIT_USAGE, 'Source directory does not exist.');
        }
        $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json');
        if (!is_string($raw)) {
            packageProFail(EXIT_USAGE, 'Source directory is missing manifest.json.');
        }
        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            packageProFail(EXIT_USAGE, 'Source manifest.json is not valid JSON.');
        }
        if (!is_array($manifest) || !is_string($manifest['id'] ?? null) || !is_string($manifest['version'] ?? null)) {
            packageProFail(EXIT_USAGE, 'Source manifest must declare id and version.');
        }
        if ($wanted !== [] && !isset($wanted[$manifest['id']])) {
            continue;
        }
        $sources[$manifest['id']] = [
            'id' => $manifest['id'],
            'source' => $dir,
            'version' => $manifest['version'],
            'catalog' => false,
        ];
    }

    ksort($sources);

    return array_values($sources);
}

function main(array $argv): void
{
    $options = packageProArguments($argv);
    if ($options['help']) {
        packageProUsage();
        exit(EXIT_OK);
    }

    $backend = packageProBackend();
    $autoload = $backend . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoload)) {
        packageProFail(EXIT_PREREQ, 'backend/vendor/autoload.php is missing; run composer install.');
    }
    require $autoload;

    if (!function_exists('sodium_crypto_sign_detached')) {
        packageProFail(EXIT_PREREQ, 'Ed25519 signing is unavailable (ext-sodium missing).');
    }
    $privateKey = (string) (getenv('EXTENSION_SIGNING_PRIVATE_KEY') ?: '');
    if ($privateKey === '') {
        packageProFail(EXIT_PREREQ, 'EXTENSION_SIGNING_PRIVATE_KEY is required to sign Pro artifacts.');
    }
    $keyId = $options['key'];
    if ($keyId === null || $keyId === '') {
        $keyId = (string) (getenv('EXTENSION_SIGNING_KEY_ID') ?: 'default');
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $keyId) !== 1) {
        packageProFail(EXIT_USAGE, 'Signing key id is invalid.');
    }

    $sources = $options['sources'] !== []
        ? packageProExplicitSources($options['sources'], $options['ids'])
        : packageProDiscoveredSources($backend, packageProCatalogProIds($backend), $options['ids']);
    if ($sources === []) {
        packageProFail(EXIT_USAGE, 'No Pro extension sources were found to pack.');
    }

    $outDir = packageProOutDir($options['out']);
    if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        packageProFail(EXIT_PACK, 'Could not create the artifact directory.');
    }

    $installer = new ExtensionPackageInstaller($backend);
    $artifacts = [];
    foreach ($sources as $source) {
        $filename = str_replace('/', '-', $source['id']) . '-' . $source['version'] . '.zip';
        $output = $outDir . DIRECTORY_SEPARATOR . $filename;
        try {
            $packed = $installer->pack($source['source'], $output, $keyId);
        } catch (Throwable $exception) {
            packageProFail(EXIT_PACK, 'Packing ' . $source['id'] . ' failed: ' . $exception->getMessage());
        }
        if ($packed['signed'] !== true) {
            packageProFail(EXIT_PACK, 'Packing ' . $source['id'] . ' did not produce a signed artifact.');
        }
        $size = filesize($output);
        $artifacts[] = [
            'id' => $source['id'],
            'version' => $source['version'],
            'filename' => $filename,
            'sha256' => $packed['sha256'],
            'size' => is_int($size) ? $size : null,
            'signed' => true,
        ];
    }

    $metadata = ['schema' => 'siro.pro-artifacts/1', 'artifacts' => $artifacts, 'count' => count($artifacts)];
    if ($options['json']) {
        fwrite(STDOUT, (string) json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    } else {
        fwrite(STDOUT, sprintf('Packed %d signed Pro artifact(s) into %s', count($artifacts), $outDir) . PHP_EOL);
        foreach ($artifacts as $artifact) {
            fwrite(STDOUT, sprintf('  %s %s sha256=%s', $artifact['id'], $artifact['version'], $artifact['sha256']) . PHP_EOL);
        }
    }

    exit(EXIT_OK);
}

main($argv);
