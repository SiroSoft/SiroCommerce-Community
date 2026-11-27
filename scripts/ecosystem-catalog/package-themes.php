<?php

declare(strict_types=1);

/**
 * Build signed Pro theme archives for the private feed.
 *
 * Release-side companion to package-pro.php. It packs siro.theme/1 theme
 * directories as theme.json + assets/** + checksums.json + signature.json and
 * verifies the result with the same installer used by the runtime import path.
 * Signing uses EXTENSION_SIGNING_PRIVATE_KEY from the environment; the key is
 * never printed, persisted or copied into the artifact directory.
 *
 * Usage:
 *   php scripts/ecosystem-catalog/package-themes.php --json
 *   php scripts/ecosystem-catalog/package-themes.php --theme=examples/themes/atelier-noir --out=backend/storage/ecosystem/pro --key=key-1
 *
 * Exit codes:
 *   0 success, 2 usage error, 3 signing prerequisites missing,
 *   4 a package failed to pack/verify.
 */

use App\Modules\StorefrontConfig\Application\ThemePackageArchiveInstaller;

const THEME_EXIT_OK = 0;
const THEME_EXIT_USAGE = 2;
const THEME_EXIT_PREREQ = 3;
const THEME_EXIT_PACK = 4;

const THEME_ASSET_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'otf'];

/**
 * @return array{themes: list<string>, out: string, key: ?string, json: bool, help: bool}
 */
function packageThemesArguments(array $argv): array
{
    $options = [
        'themes' => [],
        'out' => '',
        'key' => null,
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
        if (str_starts_with($argument, '--theme=')) {
            $theme = substr($argument, strlen('--theme='));
            if ($theme !== '') {
                $options['themes'][] = $theme;
            }
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

        packageThemesFail(THEME_EXIT_USAGE, 'Unknown option: ' . $argument);
    }

    return $options;
}

function packageThemesUsage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php scripts/ecosystem-catalog/package-themes.php [--json]
  php scripts/ecosystem-catalog/package-themes.php --theme=PATH [--theme=PATH...] --out=DIR --key=KEY_ID

Options:
  --theme=PATH     Theme directory containing theme.json and assets/ (repeatable).
                   Default: examples/themes/atelier-noir and
                   examples/themes/apothecary-calm.
  --out=DIR        Artifact directory. Default: PRO_ARTIFACT_DIR or
                   backend/storage/ecosystem/pro.
  --key=KEY_ID     Signature key id written to signature.json.
                   Default: EXTENSION_SIGNING_KEY_ID or "default".
  --json           Print machine-readable metadata on stdout.
  --help, -h       Show this help.

Signing uses EXTENSION_SIGNING_PRIVATE_KEY. The key is never printed or stored.
Exit codes: 0 ok, 2 usage, 3 signing/tooling prerequisite missing, 4 pack failed.

TXT);
}

function packageThemesFail(int $code, string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit($code);
}

function packageThemesRoot(): string
{
    return dirname(__DIR__, 2);
}

function packageThemesBackend(): string
{
    return packageThemesRoot() . DIRECTORY_SEPARATOR . 'backend';
}

function packageThemesOutDir(string $configured): string
{
    $raw = $configured;
    if ($raw === '') {
        $raw = (string) (getenv('PRO_ARTIFACT_DIR') ?: '');
    }
    if ($raw === '') {
        $raw = 'storage/ecosystem/pro';
    }
    if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $raw) !== 1) {
        $raw = packageThemesBackend() . DIRECTORY_SEPARATOR . $raw;
    }

    return rtrim($raw, '/\\');
}

function packageThemesResolve(string $path): string
{
    if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) !== 1) {
        $path = packageThemesRoot() . DIRECTORY_SEPARATOR . $path;
    }
    $real = realpath($path);
    if ($real === false || !is_dir($real)) {
        packageThemesFail(THEME_EXIT_USAGE, 'Theme directory does not exist.');
    }

    return $real;
}

/**
 * @return array<string, string> relative asset path => raw bytes
 */
function packageThemesAssets(string $themeDir): array
{
    $assetsDir = $themeDir . DIRECTORY_SEPARATOR . 'assets';
    if (!is_dir($assetsDir)) {
        packageThemesFail(THEME_EXIT_USAGE, 'Theme directory is missing an assets/ directory.');
    }
    $realAssets = realpath($assetsDir);
    if ($realAssets === false) {
        packageThemesFail(THEME_EXIT_USAGE, 'Theme assets directory cannot be resolved.');
    }

    $assets = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realAssets, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }
        $path = $item->getRealPath();
        if ($path === false) {
            packageThemesFail(THEME_EXIT_PACK, 'Theme asset cannot be resolved.');
        }
        if (!str_starts_with($path, $realAssets . DIRECTORY_SEPARATOR)) {
            packageThemesFail(THEME_EXIT_PACK, 'Theme asset escapes the assets/ directory.');
        }
        $relative = 'assets/' . str_replace('\\', '/', substr($path, strlen($realAssets) + 1));
        if (str_contains($relative, '..') || preg_match('#^assets/[A-Za-z0-9_./-]+$#', $relative) !== 1) {
            packageThemesFail(THEME_EXIT_PACK, 'Unsafe theme asset path: ' . $relative);
        }
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, THEME_ASSET_EXTENSIONS, true)) {
            packageThemesFail(THEME_EXIT_PACK, 'Unsupported theme asset type: ' . $relative);
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            packageThemesFail(THEME_EXIT_PACK, 'Could not read theme asset: ' . $relative);
        }
        if (strlen($bytes) > 5 * 1024 * 1024) {
            packageThemesFail(THEME_EXIT_PACK, 'Theme asset is too large: ' . $relative);
        }
        $assets[$relative] = $bytes;
    }
    if ($assets === []) {
        packageThemesFail(THEME_EXIT_USAGE, 'Theme assets directory is empty.');
    }
    ksort($assets);

    return $assets;
}

/**
 * @return array{id: string, key: string, version: string, filename: string, sha256: string, size: int, signed: bool}
 */
function packageThemesPack(string $themeDir, string $outDir, string $keyId, string $privateKey): array
{
    $themeFile = $themeDir . DIRECTORY_SEPARATOR . 'theme.json';
    $themeRaw = @file_get_contents($themeFile);
    if (!is_string($themeRaw)) {
        packageThemesFail(THEME_EXIT_USAGE, 'Theme directory is missing theme.json.');
    }
    try {
        $package = json_decode($themeRaw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        packageThemesFail(THEME_EXIT_USAGE, 'theme.json is not valid JSON.');
    }
    if (!is_array($package)) {
        packageThemesFail(THEME_EXIT_USAGE, 'theme.json must contain an object.');
    }
    $id = $package['id'] ?? null;
    $key = $package['key'] ?? null;
    $version = $package['version'] ?? null;
    if (!is_string($id) || preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#', $id) !== 1) {
        packageThemesFail(THEME_EXIT_USAGE, 'theme.json.id must be a lowercase vendor/key identifier.');
    }
    if (!is_string($key) || preg_match('#^[a-z0-9][a-z0-9._-]{2,39}$#', $key) !== 1) {
        packageThemesFail(THEME_EXIT_USAGE, 'theme.json.key is invalid.');
    }
    if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
        packageThemesFail(THEME_EXIT_USAGE, 'theme.json.version must be major.minor.patch.');
    }

    $assets = packageThemesAssets($themeDir);
    $checksums = [];
    foreach ($assets as $relative => $bytes) {
        $checksums[$relative] = hash('sha256', $bytes);
    }
    ksort($checksums);
    $checksumsRaw = json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $secret = base64_decode($privateKey, true);
    if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        packageThemesFail(THEME_EXIT_PREREQ, 'EXTENSION_SIGNING_PRIVATE_KEY is invalid.');
    }
    $payload = hash('sha256', $themeRaw) . "\n" . hash('sha256', $checksumsRaw);
    $signature = [
        'algorithm' => 'ed25519',
        'key_id' => $keyId,
        'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secret)),
    ];
    $signatureRaw = json_encode($signature, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $filename = 'siro-' . $key . '-' . $version . '.zip';
    $output = $outDir . DIRECTORY_SEPARATOR . $filename;
    $zip = new ZipArchive();
    if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        packageThemesFail(THEME_EXIT_PACK, 'Could not create theme archive for ' . $id . '.');
    }
    try {
        $zip->addFromString('theme.json', $themeRaw);
        $zip->addFromString('checksums.json', $checksumsRaw);
        foreach ($assets as $relative => $bytes) {
            $zip->addFromString($relative, $bytes);
        }
        $zip->addFromString('signature.json', $signatureRaw);
    } finally {
        $zip->close();
    }

    packageThemesVerify($output, $secret, $keyId);

    $sha256 = hash_file('sha256', $output);
    $size = filesize($output);

    return [
        'id' => $id,
        'key' => $key,
        'version' => $version,
        'filename' => $filename,
        'sha256' => is_string($sha256) ? $sha256 : '',
        'size' => is_int($size) ? $size : 0,
        'signed' => true,
    ];
}

/**
 * Verify the freshly written archive with the runtime installer. The public key
 * is derived in memory from the signing key so verification needs no extra
 * configuration; it is never written or printed.
 */
function packageThemesVerify(string $zipPath, string $secret, string $keyId): void
{
    if (!function_exists('sodium_crypto_sign_publickey_from_secretkey')) {
        packageThemesFail(THEME_EXIT_PREREQ, 'Ed25519 signing is unavailable (ext-sodium missing).');
    }
    $existing = [];
    $configured = getenv('EXTENSION_SIGNING_PUBLIC_KEYS');
    if (is_string($configured) && $configured !== '') {
        try {
            $decoded = json_decode($configured, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach ($decoded as $id => $publicKey) {
                    if (is_string($id) && is_string($publicKey)) {
                        $existing[$id] = $publicKey;
                    }
                }
            }
        } catch (JsonException) {
            $existing = [];
        }
    }
    $existing[$keyId] = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    $_ENV['EXTENSION_SIGNING_PUBLIC_KEYS'] = json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    putenv('EXTENSION_SIGNING_PUBLIC_KEYS=' . $_ENV['EXTENSION_SIGNING_PUBLIC_KEYS']);

    try {
        $report = (new ThemePackageArchiveInstaller())->verify($zipPath, true);
    } catch (Throwable $exception) {
        @unlink($zipPath);
        packageThemesFail(THEME_EXIT_PACK, 'Theme archive failed verification: ' . $exception->getMessage());
    }
    if (($report['signed'] ?? false) !== true) {
        @unlink($zipPath);
        packageThemesFail(THEME_EXIT_PACK, 'Theme archive is unsigned.');
    }
}

function main(array $argv): void
{
    $options = packageThemesArguments($argv);
    if ($options['help']) {
        packageThemesUsage();
        exit(THEME_EXIT_OK);
    }

    $backend = packageThemesBackend();
    $autoload = $backend . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoload)) {
        packageThemesFail(THEME_EXIT_PREREQ, 'backend/vendor/autoload.php is missing; run composer install.');
    }
    require $autoload;

    if (!function_exists('sodium_crypto_sign_detached')) {
        packageThemesFail(THEME_EXIT_PREREQ, 'Ed25519 signing is unavailable (ext-sodium missing).');
    }
    $privateKey = (string) (getenv('EXTENSION_SIGNING_PRIVATE_KEY') ?: '');
    if ($privateKey === '') {
        packageThemesFail(THEME_EXIT_PREREQ, 'EXTENSION_SIGNING_PRIVATE_KEY is required to sign theme archives.');
    }
    $keyId = $options['key'];
    if ($keyId === null || $keyId === '') {
        $keyId = (string) (getenv('EXTENSION_SIGNING_KEY_ID') ?: 'default');
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $keyId) !== 1) {
        packageThemesFail(THEME_EXIT_USAGE, 'Signing key id is invalid.');
    }

    $themes = $options['themes'] !== []
        ? $options['themes']
        : ['examples/themes/atelier-noir', 'examples/themes/apothecary-calm'];

    $outDir = packageThemesOutDir($options['out']);
    if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        packageThemesFail(THEME_EXIT_PACK, 'Could not create the artifact directory.');
    }

    $artifacts = [];
    $seen = [];
    foreach ($themes as $theme) {
        $themeDir = packageThemesResolve($theme);
        $artifact = packageThemesPack($themeDir, $outDir, $keyId, $privateKey);
        if (isset($seen[$artifact['id']])) {
            packageThemesFail(THEME_EXIT_USAGE, 'Duplicate theme id: ' . $artifact['id']);
        }
        $seen[$artifact['id']] = true;
        $artifacts[] = $artifact;
    }

    $metadata = ['schema' => 'siro.theme-artifacts/1', 'artifacts' => $artifacts, 'count' => count($artifacts)];
    if ($options['json']) {
        fwrite(STDOUT, (string) json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    } else {
        fwrite(STDOUT, sprintf('Packed %d signed theme artifact(s) into %s', count($artifacts), $outDir) . PHP_EOL);
        foreach ($artifacts as $artifact) {
            fwrite(STDOUT, sprintf('  %s %s sha256=%s', $artifact['id'], $artifact['version'], $artifact['sha256']) . PHP_EOL);
        }
    }

    exit(THEME_EXIT_OK);
}

main($argv);
