<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use App\Contracts\ExtensionInterface;
use App\Models\Extension;
use Siro\Core\Env;
use ZipArchive;

/**
 * Private Pro artifact delivery and installation.
 *
 * Artifacts live outside the public web root (PRO_ARTIFACT_DIR, default
 * storage/ecosystem/pro). The service never exposes filesystem paths, signing
 * keys or license internals: the feed gets short-lived HMAC download tokens and
 * installation runs through the existing {@see ExtensionPackageInstaller}
 * (checksum + Ed25519 signature required, atomic install + backup), never
 * enabling an extension automatically.
 *
 * @phpstan-type ArtifactMetadata array{
 *   id: string,
 *   version: string,
 *   available: bool,
 *   filename: string|null,
 *   sha256: string|null,
 *   size: int|null,
 *   signed: bool,
 *   reason: string|null
 * }
 */
final class ProArtifactService
{
    public const DEFAULT_ARTIFACT_DIR = 'storage/ecosystem/pro';

    public const TOKEN_TTL = 300;

    private const ID_PATTERN = '#^[a-z0-9][a-z0-9._-]*(/[a-z0-9][a-z0-9._-]*)?$#';

    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

    private const INSTALLED_STATES = ['enabled', 'disabled', 'installed', 'failed'];

    private string $basePath;

    private ?string $catalogPath;

    private ?ExtensionPackageInstaller $installer;

    /** @var list<class-string<ExtensionInterface>>|null */
    private ?array $extensionClasses;

    private ?EcosystemCatalogService $catalog = null;

    /**
     * @param list<class-string<ExtensionInterface>>|null $extensionClasses
     */
    public function __construct(
        ?string $basePath = null,
        ?string $catalogPath = null,
        ?ExtensionPackageInstaller $installer = null,
        ?array $extensionClasses = null,
    ) {
        $this->basePath = $basePath !== null && $basePath !== ''
            ? rtrim($basePath, DIRECTORY_SEPARATOR)
            : $this->defaultBasePath();
        $this->catalogPath = $catalogPath;
        $this->installer = $installer;
        $this->extensionClasses = $extensionClasses;
    }

    /**
     * Safe artifact metadata for the private Pro feed. No filesystem path and no
     * download token are ever part of this payload.
     *
     * @param array<string, mixed> $item
     * @return ArtifactMetadata
     */
    public function metadata(array $item): array
    {
        $id = $this->stringField($item, 'id');
        $version = $this->stringField($item, 'latest_version');
        if ($id === null || preg_match(self::ID_PATTERN, $id) !== 1
            || $version === null || preg_match(self::VERSION_PATTERN, $version) !== 1
        ) {
            return $this->unavailable('', '', 'Artifact metadata is invalid');
        }

        return $this->resolveMetadata($item, $version)['meta'];
    }

    /**
     * Issue a short-lived, signed token for one exact artifact version/checksum.
     */
    public function issueToken(string $id, string $version, string $sha256): string
    {
        $id = $this->normalizeId($id);
        $version = $this->normalizeVersion($version) ?? '';
        if ($version === '' || preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
            throw new \InvalidArgumentException('Artifact token claim is invalid');
        }

        $claims = [
            'v' => 1,
            'id' => $id,
            'version' => $version,
            'sha256' => strtolower($sha256),
            'exp' => time() + self::TOKEN_TTL,
        ];
        $payload = $this->base64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->tokenKey(), true));

        return $payload . '.' . $signature;
    }

    /**
     * Resolve and authorize a download token. Returns an internal path only to
     * the controller, which streams the file; the path is never serialized.
     *
     * @return array{path: string, filename: string}
     */
    public function resolveDownload(string $token): array
    {
        $claims = $this->verifyToken($token);
        $item = $this->catalogItem($claims['id']);
        $this->assertEntitled($item);

        $version = $claims['version'];
        $path = $this->artifactPath($claims['id'], $version);
        if ($path === null) {
            throw new \RuntimeException('Pro artifact is not available', 404);
        }

        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($claims['sha256'], strtolower($actual))) {
            throw new \RuntimeException('Pro artifact checksum does not match', 409);
        }
        $catalogSha = $this->stringField($item, 'sha256');
        if ($catalogSha !== null && !hash_equals(strtolower($catalogSha), strtolower($actual))) {
            throw new \RuntimeException('Pro artifact checksum does not match', 409);
        }

        return ['path' => $path, 'filename' => basename($path)];
    }

    /**
     * Install or update a signed Pro artifact. Never enables the extension.
     *
     * @return array{id: string, version: string, previous_version: string|null, state: string, updated: bool, backup: bool, sha256: string}
     */
    public function install(string $id, ?string $version = null, bool $update = false): array
    {
        $id = $this->normalizeId($id);
        $version = $this->normalizeVersion($version);
        $item = $this->catalogItem($id);
        $this->assertInstallableItem($item, $update);
        $this->assertEntitled($item);

        $targetVersion = $version ?? $this->stringField($item, 'latest_version');
        if ($targetVersion === null || preg_match(self::VERSION_PATTERN, $targetVersion) !== 1) {
            throw new \RuntimeException('Pro artifact version is invalid', 422);
        }

        $resolved = $this->resolveMetadata($item, $targetVersion);
        $meta = $resolved['meta'];
        if ($meta['available'] !== true || $resolved['path'] === null || $meta['sha256'] === null) {
            $code = $meta['reason'] !== null && str_contains(strtolower($meta['reason']), 'available') ? 404 : 422;
            throw new \RuntimeException($meta['reason'] ?? 'Pro artifact is not available', $code);
        }

        $row = Extension::query()->where('id', $id)->first();
        $previousVersion = null;
        if ($row instanceof Extension) {
            $data = $row->toArray();
            $previousVersion = is_string($data['version'] ?? null) ? $data['version'] : null;
        }

        if ($update) {
            if (!$row instanceof Extension) {
                throw new \RuntimeException('Extension is not installed', 409);
            }
            if ($previousVersion === null || !$this->isNewer($targetVersion, $previousVersion)) {
                throw new \RuntimeException('No newer Pro artifact is available', 409);
            }
        } elseif ($row instanceof Extension) {
            $state = $this->rowState($row);
            if (in_array($state, self::INSTALLED_STATES, true)) {
                throw new \RuntimeException('Extension is already installed', 409);
            }
        }

        $result = $this->installer()->install($resolved['path'], $update, true);
        if ($result['version'] !== $targetVersion) {
            throw new \RuntimeException('Pro artifact version does not match the catalog', 409);
        }

        $state = $this->syncRegistry($id, $targetVersion, $update);
        $backup = ($result['backup'] ?? null) !== null;

        $this->audit($update ? 'pro_update' : 'pro_install', $id, $targetVersion, $meta['sha256'], $state, $backup);

        return [
            'id' => $id,
            'version' => $targetVersion,
            'previous_version' => $previousVersion,
            'state' => $state,
            'updated' => $update,
            'backup' => $backup,
            'sha256' => $meta['sha256'],
        ];
    }

    private function defaultBasePath(): string
    {
        if (defined('BASE_PATH') && is_string(BASE_PATH)) {
            return rtrim(BASE_PATH, DIRECTORY_SEPARATOR);
        }

        return dirname(__DIR__, 4);
    }

    private function catalog(): EcosystemCatalogService
    {
        return $this->catalog ??= new EcosystemCatalogService($this->basePath, $this->catalogPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogItem(string $id): array
    {
        foreach ($this->catalog()->load()['items'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        throw new \RuntimeException('Pro artifact not found', 404);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function assertInstallableItem(array $item, bool $update): void
    {
        $type = $this->stringField($item, 'type');
        if ($type !== 'plugin') {
            throw new \RuntimeException('Only plugin artifacts can be installed', 422);
        }
        $edition = $this->stringField($item, 'edition');
        $licenseRequired = ($item['license_required'] ?? null) === true;
        if ($edition !== 'pro' && !$licenseRequired) {
            throw new \RuntimeException('This item is not a Pro artifact', 422);
        }
        if (($this->stringField($item, 'status') ?? '') !== 'published') {
            throw new \RuntimeException('Pro artifact is not published', 409);
        }
        if ($update && ($this->stringField($item, 'latest_version') === null)) {
            throw new \RuntimeException('Pro artifact version is invalid', 422);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function assertEntitled(array $item): void
    {
        $status = $this->licenseStatus();
        if (!in_array($status['edition'], ['pro', 'enterprise'], true)
            || $status['ok'] !== true
            || !in_array($status['reason'], ['valid', 'grace'], true)
        ) {
            throw new \RuntimeException('Pro license is required', 403);
        }

        $id = $this->stringField($item, 'id');
        $sku = $this->stringField($item, 'license_sku');
        $listed = in_array('*', $status['extensions'], true)
            || ($id !== null && in_array($id, $status['extensions'], true))
            || ($sku !== null && in_array($sku, $status['extensions'], true));
        if (!$listed) {
            throw new \RuntimeException('Pro license does not entitle this item', 403);
        }
    }

    /**
     * @return array{ok: bool, edition: string, license_id: string|null, extensions: array<int, string>, features: array<int, string>, expires_at: string|null, grace_until: string|null, reason: string}
     */
    private function licenseStatus(): array
    {
        return (new ExtensionLicenseService(new ExtensionLicenseStore($this->basePath)))->status();
    }

    /**
     * @param array<string, mixed> $item
     * @return array{meta: ArtifactMetadata, path: string|null}
     */
    private function resolveMetadata(array $item, string $version): array
    {
        $id = $this->stringField($item, 'id') ?? '';
        $path = $this->artifactPath($id, $version);
        if ($path === null) {
            return ['meta' => $this->unavailable($id, $version, 'Signed Pro artifact is not available'), 'path' => null];
        }

        $hash = hash_file('sha256', $path);
        $sha256 = is_string($hash) ? strtolower($hash) : null;
        $size = filesize($path);
        $catalogSha = $this->stringField($item, 'sha256');
        $checksumMatches = $catalogSha !== null
            && preg_match('/^[a-f0-9]{64}$/i', $catalogSha) === 1
            && $sha256 !== null
            && hash_equals(strtolower($catalogSha), $sha256);
        $signed = $this->hasSignature($path);

        $reason = null;
        if (!$checksumMatches) {
            $reason = 'Artifact checksum is missing or does not match the catalog';
        } elseif (!$signed) {
            $reason = 'Pro artifact is not signed';
        }

        return [
            'meta' => [
                'id' => $id,
                'version' => $version,
                'available' => $checksumMatches && $signed,
                'filename' => basename($path),
                'sha256' => $sha256,
                'size' => is_int($size) ? $size : null,
                'signed' => $signed,
                'reason' => $reason,
            ],
            'path' => $path,
        ];
    }

    /**
     * @return ArtifactMetadata
     */
    private function unavailable(string $id, string $version, string $reason): array
    {
        return [
            'id' => $id,
            'version' => $version,
            'available' => false,
            'filename' => null,
            'sha256' => null,
            'size' => null,
            'signed' => false,
            'reason' => $reason,
        ];
    }

    private function artifactDir(): string
    {
        $configured = Env::get('PRO_ARTIFACT_DIR');
        if (!is_string($configured) || trim($configured) === '') {
            $configured = self::DEFAULT_ARTIFACT_DIR;
        }
        $configured = trim($configured);
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $configured) !== 1) {
            $configured = $this->basePath . DIRECTORY_SEPARATOR . $configured;
        }

        return rtrim($configured, '/\\');
    }

    private function artifactPath(string $id, string $version): ?string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1 || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            return null;
        }
        $dir = realpath($this->artifactDir());
        if ($dir === false || !is_dir($dir)) {
            return null;
        }

        $prefix = str_replace('/', '-', $id) . '-' . $version;
        foreach ([$prefix . '.zip', $prefix . '.siro.zip'] as $name) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $name);
            if ($path === false || !is_file($path) || !is_readable($path)) {
                continue;
            }
            if (!str_starts_with($path, $dir . DIRECTORY_SEPARATOR)) {
                continue;
            }

            return $path;
        }

        return null;
    }

    private function hasSignature(string $path): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    continue;
                }
                $name = str_replace('\\', '/', (string) $stat['name']);
                if ($name === 'signature.json' || str_ends_with($name, '/signature.json')) {
                    return true;
                }
            }
        } finally {
            $zip->close();
        }

        return false;
    }

    private function installer(): ExtensionPackageInstaller
    {
        return $this->installer ??= new ExtensionPackageInstaller($this->basePath);
    }

    private function syncRegistry(string $id, string $version, bool $update): string
    {
        $classes = $this->extensionClasses ?? ExtensionDiscovery::classes($this->basePath);
        $lifecycle = new ExtensionLifecycleService($classes);

        $manifest = null;
        foreach ($lifecycle->discover() as $candidate) {
            if ($candidate->id === $id) {
                $manifest = $candidate;
                break;
            }
        }

        if ($manifest !== null) {
            if ($update) {
                return $lifecycle->syncBundledManifest($manifest);
            }
            $row = Extension::query()->where('id', $id)->first();
            if (!$row instanceof Extension || !in_array($this->rowState($row), self::INSTALLED_STATES, true)) {
                $lifecycle->install($manifest);
                return 'installed';
            }

            return $this->rowState($row);
        }

        $row = Extension::query()->where('id', $id)->first();
        if (!$row instanceof Extension) {
            throw new \RuntimeException('Extension manifest could not be loaded after install', 500);
        }
        $state = $this->rowState($row);
        $row->setAttribute('version', $version);
        $row->setAttribute('updated_at', date('Y-m-d H:i:s'));
        $row->save();

        return $state;
    }

    private function rowState(Extension $row): string
    {
        $data = $row->toArray();
        $state = $data['state'] ?? null;

        return is_string($state) && $state !== '' ? $state : 'discovered';
    }

    private function isNewer(string $latest, string $installed): bool
    {
        if (preg_match(self::VERSION_PATTERN, $latest) !== 1 || preg_match(self::VERSION_PATTERN, $installed) !== 1) {
            return false;
        }

        return version_compare($latest, $installed, '>');
    }

    private function normalizeId(string $id): string
    {
        $id = trim($id);
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('Extension id is invalid');
        }

        return $id;
    }

    private function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }
        $version = trim($version);
        if ($version === '') {
            return null;
        }
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new \InvalidArgumentException('Artifact version is invalid');
        }

        return $version;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function stringField(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return array{id: string, version: string, sha256: string}
     */
    private function verifyToken(string $token): array
    {
        if ($token === '' || strlen($token) > 2048) {
            throw new \RuntimeException('Artifact download token is invalid', 403);
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Artifact download token is invalid', 403);
        }

        $payload = $parts[0];
        $signature = $this->base64UrlDecode($parts[1]);
        $expected = hash_hmac('sha256', $payload, $this->tokenKey(), true);
        if ($signature === null || !hash_equals($expected, $signature)) {
            throw new \RuntimeException('Artifact download token is invalid', 403);
        }

        $raw = $this->base64UrlDecode($payload);
        if ($raw === null) {
            throw new \RuntimeException('Artifact download token is invalid', 403);
        }
        try {
            $claims = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Artifact download token is invalid', 403);
        }
        if (!is_array($claims)
            || ($claims['v'] ?? null) !== 1
            || !is_string($claims['id'] ?? null)
            || !is_string($claims['version'] ?? null)
            || !is_string($claims['sha256'] ?? null)
            || !is_int($claims['exp'] ?? null)
        ) {
            throw new \RuntimeException('Artifact download token is invalid', 403);
        }
        if (preg_match(self::ID_PATTERN, $claims['id']) !== 1
            || preg_match(self::VERSION_PATTERN, $claims['version']) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $claims['sha256']) !== 1
        ) {
            throw new \RuntimeException('Artifact download token is invalid', 403);
        }
        if ($claims['exp'] < time()) {
            throw new \RuntimeException('Artifact download token has expired', 410);
        }

        return ['id' => $claims['id'], 'version' => $claims['version'], 'sha256' => $claims['sha256']];
    }

    private function tokenKey(): string
    {
        $override = Env::get('PRO_ARTIFACT_TOKEN_KEY');
        if (is_string($override) && strlen(trim($override)) >= 16) {
            return hash('sha256', trim($override), true);
        }
        $appKey = Env::get('APP_KEY');
        if (is_string($appKey) && strlen(trim($appKey)) >= 16) {
            return hash('sha256', trim($appKey) . '|pro-artifact', true);
        }

        $file = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'framework' . DIRECTORY_SEPARATOR . 'pro-artifact.token.key';
        if (is_file($file)) {
            $raw = trim((string) @file_get_contents($file));
            $decoded = $raw !== '' ? hex2bin($raw) : false;
            if (is_string($decoded) && strlen($decoded) >= 32) {
                return $decoded;
            }
        }

        $key = random_bytes(32);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Artifact download token is not configured', 500);
        }
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, bin2hex($key), LOCK_EX) === false) {
            @unlink($temporary);
            throw new \RuntimeException('Artifact download token is not configured', 500);
        }
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            throw new \RuntimeException('Artifact download token is not configured', 500);
        }
        @chmod($file, 0600);

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding !== 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($padded, true);

        return is_string($decoded) ? $decoded : null;
    }

    private function audit(string $action, string $id, string $version, string $sha256, string $state, bool $backup): void
    {
        error_log(sprintf(
            '[ecosystem-pro] action=%s id=%s version=%s sha256=%s state=%s backup=%s',
            $action,
            $id,
            $version,
            $sha256,
            $state,
            $backup ? 'yes' : 'no'
        ));
    }
}
