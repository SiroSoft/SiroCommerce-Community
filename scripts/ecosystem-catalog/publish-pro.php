<?php

declare(strict_types=1);

/**
 * Publish one Pro catalog item safely, offline.
 *
 * The command only edits the checked-in overlay. It never fetches a URL, never
 * reads environment secrets and never installs or enables anything. It refuses
 * incomplete input, verifies the checksum of a local artifact and validates the
 * whole overlay with build.php before replacing it.
 *
 * Usage:
 *   php scripts/ecosystem-catalog/publish-pro.php \
 *     --id=siro/affiliate \
 *     --version=1.0.0 \
 *     --artifact=dist/siro-affiliate-1.0.0.siro.zip \
 *     --artifact-url=https://cdn.example.com/siro-affiliate-1.0.0.siro.zip \
 *     --sha256=<64 hex characters> \
 *     --signature=<detached signature token> \
 *     [--signature-url=https://cdn.example.com/siro-affiliate-1.0.0.sig] \
 *     [--catalog=backend/resources/ecosystem/catalog.json] \
 *     [--dry-run]
 *
 * --artifact may be a local file or an HTTPS/root-relative URL. When it is a
 * local file the SHA-256 is checked against the exact bytes and --artifact-url
 * is required, because filesystem paths are never stored in the catalog.
 *
 * Exit codes:
 *   0 = published (or validated with --dry-run)
 *   2 = usage error (a required option is missing)
 *   3 = catalog source missing or unreadable
 *   4 = refused/invalid (unknown or non-Pro item, bad value, checksum mismatch)
 *   5 = output write/replace failure
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'build.php';

/**
 * @return array{
 *   id: string|null,
 *   version: string|null,
 *   artifact: string|null,
 *   artifact_url: string|null,
 *   sha256: string|null,
 *   signature: string|null,
 *   signature_url: string|null,
 *   catalog: string|null,
 *   dry_run: bool
 * }
 */
function publishParseArguments(array $argv): array
{
    $options = [
        'id' => null,
        'version' => null,
        'artifact' => null,
        'artifact_url' => null,
        'sha256' => null,
        'signature' => null,
        'signature_url' => null,
        'catalog' => null,
        'dry_run' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            publishUsage();
            exit(EXIT_OK);
        }

        if ($argument === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        $matched = false;
        foreach (['id', 'version', 'artifact', 'artifact-url', 'sha256', 'signature', 'signature-url', 'catalog'] as $name) {
            if ($argument === '--' . $name) {
                publishFail(EXIT_USAGE, sprintf('--%s requires the =value form.', $name));
            }

            $prefix = '--' . $name . '=';
            if (!str_starts_with($argument, $prefix)) {
                continue;
            }

            $value = substr($argument, strlen($prefix));
            if ($value === '') {
                publishFail(EXIT_USAGE, sprintf('--%s requires a non-empty value (use --%s=value).', $name, $name));
            }

            $options[str_replace('-', '_', $name)] = $value;
            $matched = true;
            break;
        }

        if (!$matched) {
            publishFail(EXIT_USAGE, sprintf('Unknown option: %s', $argument));
        }
    }

    return $options;
}

function publishUsage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php scripts/ecosystem-catalog/publish-pro.php --id=<id> --version=<x.y.z> \
    --artifact=<local file|https URL|root-relative path> \
    --sha256=<64 hex characters> --signature=<detached signature token> \
    [--artifact-url=<https URL|root-relative path>] \
    [--signature-url=<https URL|root-relative path>] \
    [--catalog=<path>] [--dry-run]

Options:
  --id           Pro catalog item id (for example siro/affiliate). Required.
  --version      Numeric SemVer MAJOR.MINOR.PATCH. Required.
  --artifact     Local artifact file to checksum, or the artifact URL when no
                 local file is available. Required.
  --artifact-url Artifact URL stored in the catalog. Required when --artifact is
                 a local file; otherwise --artifact is used when omitted.
  --sha256       Expected lowercase/uppercase SHA-256 of the artifact bytes.
                 Required. A local artifact must match exactly.
  --signature    Detached artifact signature token. Required for publication.
  --signature-url Optional URL for the detached signature file.
  --catalog      Overlay path. Default: backend/resources/ecosystem/catalog.json.
  --dry-run      Validate and print the plan without replacing the overlay.
  --help, -h     Show this help.

The command is offline: it never fetches a URL and never reads secrets. It
refuses to publish unless the item exists, is edition=pro, and all required
fields are present. Filesystem paths are never stored in the catalog. The whole
overlay is validated with build.php before the atomic replace.

Exit codes:
  0 published or dry-run validated, 2 usage error, 3 source missing/unreadable,
  4 refused/invalid or checksum mismatch, 5 write/replace failure.

TXT);
}

function publishFail(int $code, string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit($code);
}

function publishOut(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

/**
 * @param array{id: string|null, version: string|null, artifact: string|null, artifact_url: string|null, sha256: string|null, signature: string|null, signature_url: string|null, catalog: string|null, dry_run: bool} $options
 */
function publishRequired(array $options, string $key, string $flag): string
{
    $value = $options[$key] ?? null;
    if (!is_string($value) || $value === '') {
        publishFail(EXIT_USAGE, sprintf('%s is required.', $flag));
    }

    return $value;
}

function publishUrl(string $value, string $field): string
{
    $errors = [];
    $url = optionalUrl($value, $field, 'publish', $errors);
    if ($errors !== [] || $url === null) {
        publishFail(EXIT_INVALID, sprintf('%s is invalid: %s', $field, implode(' ', $errors)));
    }

    return $url;
}

function publishSignature(string $value): string
{
    $errors = [];
    $signature = optionalSignature($value, 'publish', $errors);
    if ($errors !== [] || $signature === null) {
        publishFail(EXIT_INVALID, 'signature is invalid: ' . implode(' ', $errors));
    }

    return $signature;
}

/**
 * @return array<string, mixed>
 */
function publishReadCatalog(string $path): array
{
    if (!is_file($path)) {
        publishFail(EXIT_SOURCE, sprintf(
            "catalog source not found: %s\n"
            . 'Expected the checked-in overlay at backend/resources/ecosystem/catalog.json. '
            . 'Use --catalog=path only for a reviewed staging copy; this tool never fetches from the network.',
            $path
        ));
    }

    if (!is_readable($path)) {
        publishFail(EXIT_SOURCE, sprintf('catalog source is not readable: %s', $path));
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        publishFail(EXIT_SOURCE, sprintf('could not read catalog source: %s', $path));
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        publishFail(EXIT_INVALID, sprintf('catalog is not valid JSON (%s): %s', $path, $exception->getMessage()));
    }

    if (!is_array($data) || array_is_list($data)) {
        publishFail(EXIT_INVALID, sprintf('catalog root must be a JSON object: %s', $path));
    }

    $items = $data['items'] ?? null;
    if (!is_array($items) || !array_is_list($items)) {
        publishFail(EXIT_INVALID, sprintf('catalog items must be a JSON array: %s', $path));
    }

    return $data;
}

/**
 * Write the overlay through a same-directory temp file and replace it. POSIX
 * rename is atomic; Windows cannot always rename over an existing file, so it
 * falls back to copying the already-validated bytes.
 */
function publishReplace(string $path, string $json): void
{
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));

    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        publishFail(EXIT_WRITE, sprintf('could not write temporary catalog: %s', $temporary));
    }

    $cleanup = $temporary;
    register_shutdown_function(static function () use (&$cleanup): void {
        if (is_string($cleanup) && $cleanup !== '' && is_file($cleanup)) {
            @unlink($cleanup);
        }
    });

    // Validate the complete overlay before replacing the reviewed file.
    buildFeed($temporary);

    if (!@rename($temporary, $path)) {
        if (!@copy($temporary, $path)) {
            publishFail(EXIT_WRITE, sprintf('could not replace catalog: %s', $path));
        }
        @unlink($temporary);
    }

    $cleanup = '';
}

function publishMain(array $argv): void
{
    $options = publishParseArguments($argv);

    $id = publishRequired($options, 'id', '--id');
    if (preg_match(ID_PATTERN, $id) !== 1) {
        publishFail(EXIT_INVALID, 'id must match the package id rule ^[a-z0-9][a-z0-9._-]*(/[a-z0-9][a-z0-9._-]*)?$.');
    }

    $version = publishRequired($options, 'version', '--version');
    if (preg_match(SEMVER_PATTERN, $version) !== 1) {
        publishFail(EXIT_INVALID, 'version must be a numeric SemVer MAJOR.MINOR.PATCH.');
    }

    $sha256 = strtolower(publishRequired($options, 'sha256', '--sha256'));
    if (preg_match(SHA256_PATTERN, $sha256) !== 1) {
        publishFail(EXIT_INVALID, 'sha256 must be exactly 64 hexadecimal characters.');
    }

    $signature = publishSignature(publishRequired($options, 'signature', '--signature'));

    $artifact = publishRequired($options, 'artifact', '--artifact');
    $artifactUrlOption = $options['artifact_url'];
    $localFile = null;

    if (is_file($artifact)) {
        if ($artifactUrlOption === null) {
            publishFail(EXIT_USAGE, '--artifact-url is required when --artifact is a local file; filesystem paths are never stored in the catalog.');
        }
        $localFile = $artifact;
        $artifactUrl = publishUrl($artifactUrlOption, 'artifact_url');
    } else {
        $artifactUrl = publishUrl($artifactUrlOption ?? $artifact, 'artifact_url');
    }

    if ($localFile !== null) {
        $actual = hash_file('sha256', $localFile);
        if (!is_string($actual) || !hash_equals($sha256, strtolower($actual))) {
            publishFail(EXIT_INVALID, sprintf(
                'artifact checksum mismatch for %s: expected %s, got %s.',
                $localFile,
                $sha256,
                is_string($actual) ? strtolower($actual) : '<unreadable>'
            ));
        }
    }

    $signatureUrl = null;
    if ($options['signature_url'] !== null) {
        $signatureUrl = publishUrl($options['signature_url'], 'signature_url');
    }

    $catalogPath = $options['catalog'] ?? catalogSourcePath(null);
    $data = publishReadCatalog($catalogPath);

    $index = null;
    foreach ($data['items'] as $candidate => $item) {
        if (is_array($item) && ($item['id'] ?? null) === $id) {
            $index = (int) $candidate;
            break;
        }
    }

    if ($index === null) {
        publishFail(EXIT_INVALID, sprintf("catalog has no item with id '%s'.", $id));
    }

    $current = $data['items'][$index];
    if (($current['edition'] ?? null) !== 'pro') {
        publishFail(EXIT_INVALID, sprintf("item '%s' is not edition=pro; publish-pro only publishes Pro items.", $id));
    }

    $current['status'] = 'published';
    $current['latest_version'] = $version;
    $current['artifact_url'] = $artifactUrl;
    $current['sha256'] = $sha256;
    $current['signature'] = $signature;
    $current['signature_url'] = $signatureUrl;
    $data['items'][$index] = $current;

    try {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } catch (JsonException $exception) {
        publishFail(EXIT_INVALID, 'could not encode catalog: ' . $exception->getMessage());
    }

    if ($options['dry_run']) {
        $temporary = $catalogPath . '.tmp-dry-run-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            publishFail(EXIT_WRITE, sprintf('could not write dry-run catalog: %s', $temporary));
        }

        $cleanup = $temporary;
        register_shutdown_function(static function () use (&$cleanup): void {
            if (is_string($cleanup) && $cleanup !== '' && is_file($cleanup)) {
                @unlink($cleanup);
            }
        });

        buildFeed($temporary);
        @unlink($temporary);
        $cleanup = '';

        publishOut(sprintf(
            'DRY RUN: would publish %s %s with artifact %s (sha256 %s, signature %d chars). No file was replaced.',
            $id,
            $version,
            $artifactUrl,
            $sha256,
            strlen($signature)
        ));

        exit(EXIT_OK);
    }

    publishReplace($catalogPath, $json);

    publishOut(sprintf(
        'OK: published %s %s in %s (artifact %s, sha256 %s, signature %d chars).',
        $id,
        $version,
        $catalogPath,
        $artifactUrl,
        $sha256,
        strlen($signature)
    ));

    exit(EXIT_OK);
}

publishMain($argv ?? []);
