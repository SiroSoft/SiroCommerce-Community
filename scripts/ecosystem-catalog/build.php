<?php

declare(strict_types=1);

/**
 * Validate the checked-in ecosystem catalog overlay and optionally build a
 * deterministic static release feed.
 *
 * This tool is intentionally offline: it never fetches a URL, never reads
 * environment secrets and never trusts unknown fields. It reads only the
 * catalog source file (the checked-in overlay by default).
 *
 * Usage:
 *   php scripts/ecosystem-catalog/build.php --check
 *   php scripts/ecosystem-catalog/build.php --out=path/to/catalog.json
 *   php scripts/ecosystem-catalog/build.php --check-public=path/to/catalog.json
 *   php scripts/ecosystem-catalog/build.php --in=path/to/catalog.json --check
 *
 * The deterministic feed written by --out is the public-safe projection: it
 * omits installed state, license data, artifact URLs/checksums and filesystem
 * paths. --check-public rebuilds that projection in memory and compares it with
 * the committed file at the given path.
 *
 * Exit codes:
 *   0 = valid (and written when --out is used, or matching when --check-public)
 *   2 = usage error
 *   3 = catalog source missing or unreadable
 *   4 = invalid catalog, or public feed drift/missing
 *   5 = output write failure
 */

const CATALOG_SCHEMA = 'siro.ecosystem-catalog/1';

const EXIT_OK = 0;
const EXIT_USAGE = 2;
const EXIT_SOURCE = 3;
const EXIT_INVALID = 4;
const EXIT_WRITE = 5;

const ID_PATTERN = '#^[a-z0-9][a-z0-9._-]*(/[a-z0-9][a-z0-9._-]*)?$#';
const SEMVER_PATTERN = '/^\d+\.\d+\.\d+$/';
const SHA256_PATTERN = '/^[a-fA-F0-9]{64}$/';

/**
 * @return array{check: bool, out: ?string, in: ?string, check_public: ?string}
 */
function parseArguments(array $argv): array
{
    $options = [
        'check' => false,
        'out' => null,
        'in' => null,
        'check_public' => null,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--check') {
            $options['check'] = true;
            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            printUsage();
            exit(EXIT_OK);
        }

        if (str_starts_with($argument, '--out=')) {
            $value = substr($argument, strlen('--out='));
            if ($value === '') {
                fail(EXIT_USAGE, '--out requires a non-empty path (use --out=path).');
            }
            $options['out'] = $value;
            continue;
        }

        if (str_starts_with($argument, '--check-public=')) {
            $value = substr($argument, strlen('--check-public='));
            if ($value === '') {
                fail(EXIT_USAGE, '--check-public requires a non-empty path (use --check-public=path).');
            }
            $options['check_public'] = $value;
            continue;
        }

        if (str_starts_with($argument, '--in=')) {
            $value = substr($argument, strlen('--in='));
            if ($value === '') {
                fail(EXIT_USAGE, '--in requires a non-empty path (use --in=path).');
            }
            $options['in'] = $value;
            continue;
        }

        if ($argument === '--out' || $argument === '--in' || $argument === '--check-public') {
            fail(EXIT_USAGE, sprintf('%s requires the =path form.', $argument));
        }

        fail(EXIT_USAGE, sprintf('Unknown option: %s', $argument));
    }

    return $options;
}

function printUsage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php scripts/ecosystem-catalog/build.php --check
  php scripts/ecosystem-catalog/build.php --out=path/to/catalog.json
  php scripts/ecosystem-catalog/build.php --check-public=path/to/catalog.json
  php scripts/ecosystem-catalog/build.php --in=path/to/catalog.json --check

Options:
  --check               Validate only. Never writes a feed.
  --out=path            Validate, then write the deterministic public feed to
                        path. Parent directories are created when missing.
  --check-public=path   Validate, build the public feed in memory and compare it
                        byte-for-byte with the committed file at path. Exits 4 on
                        drift or when the file is missing. Never writes.
  --in=path             Override the catalog source. Default:
                        backend/resources/ecosystem/catalog.json. Intended for
                        staging and test fixtures; operators normally use the
                        default.
  --help, -h            Show this help.

The tool is offline. It never fetches a URL and never reads secrets. The feed is
a public-safe projection of the overlay: no installed state, license data,
artifact URLs/checksums, secrets or filesystem paths.

Exit codes:
  0 valid, 2 usage error, 3 source missing/unreadable,
  4 invalid catalog or public feed drift/missing, 5 output write failure.

TXT);
}

function fail(int $code, string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit($code);
}

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function catalogSourcePath(?string $override): string
{
    if ($override !== null) {
        return $override;
    }

    $repoRoot = dirname(__DIR__, 2);

    return $repoRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR
        . 'resources' . DIRECTORY_SEPARATOR . 'ecosystem' . DIRECTORY_SEPARATOR
        . 'catalog.json';
}

/**
 * Reject obvious secret material before it can reach a feed, error message or
 * debug output. This is a fail-closed guard, not a secret scanner.
 *
 * @param array<mixed> $data
 */
function assertNoSecretMaterial(string $raw, array $data): void
{
    if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH |ENCRYPTED )?PRIVATE KEY-----/', $raw) === 1) {
        fail(EXIT_INVALID, 'catalog contains private key material; refusing to build a feed.');
    }

    $forbiddenKeys = [
        'private_key',
        'privatekey',
        'private_key_file',
        'client_secret',
        'clientsecret',
        'secret',
        'password',
        'api_key',
        'apikey',
        'access_token',
        'authorization',
    ];

    $walk = static function (mixed $value, string $path) use (&$walk, $forbiddenKeys): void {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), $forbiddenKeys, true)) {
                fail(EXIT_INVALID, sprintf('catalog contains forbidden secret-like field: %s.%s', $path, $key));
            }

            $walk($child, $path . '.' . (is_int($key) ? (string) $key : $key));
        }
    };

    $walk($data, '$');
}

function isRfc3339(string $value): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
        return false;
    }

    try {
        new DateTimeImmutable($value);

        return true;
    } catch (Exception) {
        return false;
    }
}

function isIsoDate(string $value): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

function requiredString(mixed $value, string $field, string $where, array &$errors): ?string
{
    if (!is_string($value) || trim($value) === '') {
        $errors[] = sprintf('%s.%s must be a non-empty string.', $where, $field);

        return null;
    }

    return $value;
}

function optionalString(mixed $value, string $field, string $where, array &$errors): ?string
{
    if ($value === null) {
        return null;
    }

    if (!is_string($value) || trim($value) === '') {
        $errors[] = sprintf('%s.%s must be null or a non-empty string.', $where, $field);

        return null;
    }

    return $value;
}

function optionalChangelog(mixed $value, string $where, array &$errors): ?string
{
    if ($value === null) {
        return null;
    }

    if (!is_string($value)) {
        $errors[] = sprintf('%s.changelog must be null or a plain-text string.', $where);

        return null;
    }

    if (trim($value) === '') {
        return null;
    }

    if (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $value) === 1) {
        $errors[] = sprintf('%s.changelog must not contain control characters other than newlines.', $where);

        return null;
    }

    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length > 1000) {
        $errors[] = sprintf('%s.changelog must be 1000 characters or fewer (got %d).', $where, $length);

        return null;
    }

    return $value;
}

function optionalUrl(mixed $value, string $field, string $where, array &$errors): ?string
{
    if ($value === null) {
        return null;
    }

    if (!is_string($value) || trim($value) === '') {
        $errors[] = sprintf('%s.%s must be null or a non-empty URL string.', $where, $field);

        return null;
    }

    if (preg_match('/[\x00-\x1F\x7F\s]/', $value) === 1) {
        $errors[] = sprintf('%s.%s must not contain whitespace or control characters.', $where, $field);

        return null;
    }

    if (str_contains($value, '\\')) {
        $errors[] = sprintf('%s.%s must not contain backslashes.', $where, $field);

        return null;
    }

    if (str_starts_with($value, '/')) {
        if (str_starts_with($value, '//')) {
            $errors[] = sprintf('%s.%s must be https:// or a root-relative path (not protocol-relative).', $where, $field);

            return null;
        }

        return $value;
    }

    $parts = parse_url($value);
    if ($parts === false
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || !isset($parts['host'])
        || $parts['host'] === '') {
        $errors[] = sprintf('%s.%s must be https:// or a root-relative path; javascript:, data:, http: and other schemes are rejected.', $where, $field);

        return null;
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        $errors[] = sprintf('%s.%s must not contain credentials.', $where, $field);

        return null;
    }

    return $value;
}

function optionalSha256(mixed $value, string $where, array &$errors): ?string
{
    if ($value === null) {
        return null;
    }

    if (!is_string($value) || preg_match(SHA256_PATTERN, $value) !== 1) {
        $errors[] = sprintf('%s.sha256 must be null or exactly 64 hexadecimal characters.', $where);

        return null;
    }

    return strtolower($value);
}

/**
 * @param array<mixed> $item
 * @param array<int, int> $seen
 * @return array<string, mixed>
 */
function normalizeItem(array $item, int $index, array &$errors, array &$seen): array
{
    $where = sprintf('items[%d]', $index);

    if (array_is_list($item)) {
        $errors[] = sprintf('%s must be a JSON object.', $where);

        return [
            'id' => '',
            'type' => null,
            'name' => null,
            'description' => null,
            'vendor' => null,
            'edition' => null,
            'status' => null,
            'latest_version' => null,
            'min_core' => null,
            'min_commerce' => null,
            'license_required' => null,
            'license_sku' => null,
            'preview_url' => null,
            'changelog_url' => null,
            'changelog' => null,
            'artifact_url' => null,
            'sha256' => null,
            'published_at' => null,
            'tags' => [],
        ];
    }

    $id = $item['id'] ?? null;
    $idValid = is_string($id) && preg_match(ID_PATTERN, $id) === 1;
    if (!$idValid) {
        $errors[] = sprintf('%s.id must match the package id rule ^[a-z0-9][a-z0-9._-]*(/[a-z0-9][a-z0-9._-]*)?$.', $where);
    } else {
        if (array_key_exists($id, $seen)) {
            $errors[] = sprintf("%s.id duplicates '%s' (first seen at items[%d]).", $where, $id, $seen[$id]);
        } else {
            $seen[$id] = $index;
        }

        $where .= sprintf(" ('%s')", $id);
    }

    $type = $item['type'] ?? null;
    $typeValid = is_string($type) && in_array($type, ['plugin', 'theme'], true);
    if (!$typeValid) {
        $errors[] = sprintf('%s.type must be one of: plugin, theme.', $where);
    }

    $edition = $item['edition'] ?? null;
    $editionValid = is_string($edition) && in_array($edition, ['free', 'pro'], true);
    if (!$editionValid) {
        $errors[] = sprintf('%s.edition must be one of: free, pro.', $where);
    }

    $status = $item['status'] ?? null;
    $statusValid = is_string($status) && in_array($status, ['published', 'planned', 'deprecated'], true);
    if (!$statusValid) {
        $errors[] = sprintf('%s.status must be one of: published, planned, deprecated.', $where);
    }

    $name = requiredString($item['name'] ?? null, 'name', $where, $errors);
    $description = optionalString($item['description'] ?? null, 'description', $where, $errors);
    $vendor = optionalString($item['vendor'] ?? null, 'vendor', $where, $errors);

    $latestVersion = $item['latest_version'] ?? null;
    if (!is_string($latestVersion) || preg_match(SEMVER_PATTERN, $latestVersion) !== 1) {
        $errors[] = sprintf('%s.latest_version must be a numeric SemVer MAJOR.MINOR.PATCH.', $where);
        $latestVersion = null;
    }

    $minCore = optionalString($item['min_core'] ?? null, 'min_core', $where, $errors);
    $minCommerce = optionalString($item['min_commerce'] ?? null, 'min_commerce', $where, $errors);

    $licenseRequired = $item['license_required'] ?? null;
    $licenseValid = is_bool($licenseRequired);
    if (!$licenseValid) {
        $errors[] = sprintf('%s.license_required must be a boolean.', $where);
    }

    if ($editionValid && $licenseValid) {
        if ($edition === 'pro' && $licenseRequired !== true) {
            $errors[] = sprintf('%s.license_required must be true when edition=pro.', $where);
        }

        if ($edition === 'free' && $licenseRequired !== false) {
            $errors[] = sprintf('%s.license_required must be false when edition=free.', $where);
        }
    }

    $licenseSku = optionalString($item['license_sku'] ?? null, 'license_sku', $where, $errors);

    $previewUrl = optionalUrl($item['preview_url'] ?? null, 'preview_url', $where, $errors);
    $changelogUrl = optionalUrl($item['changelog_url'] ?? null, 'changelog_url', $where, $errors);
    $changelog = optionalChangelog($item['changelog'] ?? null, $where, $errors);
    $artifactUrl = optionalUrl($item['artifact_url'] ?? null, 'artifact_url', $where, $errors);
    $sha256 = optionalSha256($item['sha256'] ?? null, $where, $errors);

    if ($editionValid && $statusValid) {
        if ($edition === 'pro' && $status === 'published') {
            if ($artifactUrl === null) {
                $errors[] = sprintf('%s.artifact_url is required before a Pro item can be published.', $where);
            }

            if ($sha256 === null) {
                $errors[] = sprintf('%s.sha256 is required before a Pro item can be published.', $where);
            }
        }

        if ($artifactUrl !== null && $sha256 === null) {
            $errors[] = sprintf('%s.sha256 is required when artifact_url is set.', $where);
        }
    }

    $publishedAt = $item['published_at'] ?? null;
    if ($publishedAt !== null && (!is_string($publishedAt) || !isIsoDate($publishedAt))) {
        $errors[] = sprintf('%s.published_at must be null or a YYYY-MM-DD date.', $where);
        $publishedAt = null;
    }

    $tags = $item['tags'] ?? [];
    if (!is_array($tags) || !array_is_list($tags)) {
        $errors[] = sprintf('%s.tags must be an array of strings.', $where);
        $tags = [];
    } else {
        foreach ($tags as $tagIndex => $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                $errors[] = sprintf('%s.tags[%d] must be a non-empty string.', $where, $tagIndex);
            }
        }
    }

    return [
        'id' => is_string($id) ? $id : '',
        'type' => $typeValid ? $type : null,
        'name' => $name,
        'description' => $description,
        'vendor' => $vendor,
        'edition' => $editionValid ? $edition : null,
        'status' => $statusValid ? $status : null,
        'latest_version' => $latestVersion,
        'min_core' => $minCore,
        'min_commerce' => $minCommerce,
        'license_required' => $licenseValid ? $licenseRequired : null,
        'license_sku' => $licenseSku,
        'preview_url' => $previewUrl,
        'changelog_url' => $changelogUrl,
        'changelog' => $changelog,
        'artifact_url' => $artifactUrl,
        'sha256' => $sha256,
        'published_at' => is_string($publishedAt) ? $publishedAt : null,
        'tags' => $tags,
    ];
}

function buildFeed(string $source): array
{
    if (!is_file($source)) {
        fail(EXIT_SOURCE, sprintf(
            "catalog source not found: %s\n"
            . 'Expected the checked-in overlay at backend/resources/ecosystem/catalog.json. '
            . 'The backend catalog workstream must create it; this tool never fetches from the network.',
            $source
        ));
    }

    if (!is_readable($source)) {
        fail(EXIT_SOURCE, sprintf('catalog source is not readable: %s', $source));
    }

    $raw = file_get_contents($source);
    if ($raw === false) {
        fail(EXIT_SOURCE, sprintf('could not read catalog source: %s', $source));
    }

    if (trim($raw) === '') {
        fail(EXIT_INVALID, sprintf('catalog source is empty: %s', $source));
    }

    try {
        $root = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail(EXIT_INVALID, sprintf('catalog is not valid JSON (%s): %s', $source, $exception->getMessage()));
    }

    if (!is_object($root) || !is_array($data)) {
        fail(EXIT_INVALID, sprintf('catalog root must be a JSON object: %s', $source));
    }

    assertNoSecretMaterial($raw, $data);

    $errors = [];

    $schema = $data['schema'] ?? null;
    if ($schema !== CATALOG_SCHEMA) {
        $errors[] = sprintf('schema must be "%s".', CATALOG_SCHEMA);
    }

    $generatedAt = $data['generated_at'] ?? null;
    if (!is_string($generatedAt) || !isRfc3339($generatedAt)) {
        $errors[] = 'generated_at must be an RFC3339 timestamp, for example 2026-11-24T00:00:00Z.';
    }

    $rawItems = $data['items'] ?? null;
    if (!is_array($rawItems) || !array_is_list($rawItems)) {
        $errors[] = 'items must be a JSON array.';
        $rawItems = [];
    }

    $seen = [];
    $items = [];
    foreach ($rawItems as $index => $rawItem) {
        if (!is_array($rawItem)) {
            $errors[] = sprintf('items[%d] must be a JSON object.', $index);
            continue;
        }

        $items[] = normalizeItem($rawItem, (int) $index, $errors, $seen);
    }

    if ($errors !== []) {
        fwrite(STDERR, sprintf('ERROR: invalid ecosystem catalog: %s', $source) . PHP_EOL);
        foreach ($errors as $error) {
            fwrite(STDERR, '  - ' . $error . PHP_EOL);
        }
        fwrite(STDERR, 'No feed was written.' . PHP_EOL);
        exit(EXIT_INVALID);
    }

    usort($items, static fn (array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));

    return [
        'schema' => CATALOG_SCHEMA,
        'generated_at' => $generatedAt,
        'items' => $items,
    ];
}

function encodeFeed(array $feed, string $source): string
{
    try {
        $json = json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail(EXIT_INVALID, sprintf('could not encode feed from %s: %s', $source, $exception->getMessage()));
    }

    return $json . "\n";
}

/**
 * Public-safe projection of the validated feed. The overlay never carries
 * installed state, but this also drops license data, artifact URLs/checksums,
 * secrets and filesystem paths so the storefront can serve it as a static
 * asset. Fields are emitted in a fixed order for byte-identical output.
 *
 * @param array<string, mixed> $feed
 * @return array{schema: string, generated_at: string, items: list<array<string, mixed>>}
 */
function publicFeed(array $feed): array
{
    $items = [];
    foreach ($feed['items'] as $item) {
        $items[] = [
            'id' => $item['id'],
            'type' => $item['type'],
            'name' => $item['name'],
            'description' => $item['description'],
            'vendor' => $item['vendor'],
            'edition' => $item['edition'],
            'status' => $item['status'],
            'latest_version' => $item['latest_version'],
            'min_core' => $item['min_core'],
            'min_commerce' => $item['min_commerce'],
            'preview_url' => $item['preview_url'],
            'changelog_url' => $item['changelog_url'],
            'changelog' => $item['changelog'],
            'published_at' => $item['published_at'],
            'tags' => $item['tags'],
        ];
    }

    return [
        'schema' => CATALOG_SCHEMA,
        'generated_at' => $feed['generated_at'],
        'items' => $items,
    ];
}

function checkPublicFeed(string $path, string $expected): int
{
    if (!is_file($path)) {
        fail(EXIT_INVALID, sprintf(
            "public feed is missing: %s\nRun npm run ecosystem:catalog to regenerate it from the overlay.",
            $path
        ));
    }

    $actual = @file_get_contents($path);
    if ($actual === false) {
        fail(EXIT_INVALID, sprintf('could not read public feed: %s', $path));
    }

    if ($actual !== $expected) {
        fail(EXIT_INVALID, sprintf(
            "public feed drift detected: %s\n"
            . "Expected %d bytes (sha256 %s), found %d bytes (sha256 %s).\n"
            . 'Run npm run ecosystem:catalog to regenerate it from the overlay.',
            $path,
            strlen($expected),
            hash('sha256', $expected),
            strlen($actual),
            hash('sha256', $actual)
        ));
    }

    return strlen($expected);
}

function writeFeed(string $path, string $json): int
{
    $directory = dirname($path);
    if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            fail(EXIT_WRITE, sprintf('could not create output directory: %s', $directory));
        }
    }

    $written = @file_put_contents($path, $json, LOCK_EX);
    if ($written === false) {
        fail(EXIT_WRITE, sprintf('could not write feed: %s', $path));
    }

    return $written;
}

function main(array $argv): void
{
    $options = parseArguments($argv);

    $modes = 0;
    foreach (['check', 'out', 'check_public'] as $mode) {
        if ($options[$mode] !== false && $options[$mode] !== null) {
            $modes++;
        }
    }

    if ($modes > 1) {
        fail(EXIT_USAGE, 'Use only one of --check, --out=path or --check-public=path.');
    }

    $source = catalogSourcePath($options['in']);

    $feed = buildFeed($source);
    $public = publicFeed($feed);
    $json = encodeFeed($public, $source);
    $itemCount = count($public['items']);

    if ($options['check_public'] !== null) {
        $bytes = checkPublicFeed($options['check_public'], $json);
        out(sprintf(
            'OK: %s matches the deterministic public feed (%d item%s, %d bytes).',
            $options['check_public'],
            $itemCount,
            $itemCount === 1 ? '' : 's',
            $bytes
        ));

        exit(EXIT_OK);
    }

    if ($options['out'] === null) {
        out(sprintf(
            'OK: %s is valid (%d item%s, schema %s).',
            $source,
            $itemCount,
            $itemCount === 1 ? '' : 's',
            CATALOG_SCHEMA
        ));

        if (!$options['check']) {
            out('Hint: pass --out=path to write the deterministic public feed.');
        }

        exit(EXIT_OK);
    }

    $written = writeFeed($options['out'], $json);
    out(sprintf(
        'OK: wrote %s (%d item%s, %d bytes).',
        $options['out'],
        $itemCount,
        $itemCount === 1 ? '' : 's',
        $written
    ));

    exit(EXIT_OK);
}

main($argv);
