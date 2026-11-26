<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use App\CommerceVersion;
use App\Extension\VersionConstraint;
use App\Models\Extension;
use App\Modules\Extension\Domain\ExtensionManifest;
use Siro\Core\Console;

/**
 * Read-only ecosystem catalog: loads the checked-in distribution metadata,
 * validates it, and merges installed/discovered runtime state.
 *
 * @phpstan-type CatalogItem array{
 *   id: string,
 *   type: string,
 *   name: string,
 *   description: string,
 *   vendor: string,
 *   edition: string,
 *   status: string,
 *   latest_version: string,
 *   min_core: string|null,
 *   min_commerce: string|null,
 *   license_required: bool,
 *   license_sku: string|null,
 *   preview_url: string|null,
 *   changelog_url: string|null,
 *   changelog: string|null,
 *   artifact_url: string|null,
 *   sha256: string|null,
 *   published_at: string|null,
 *   tags: list<string>
 * }
 * @phpstan-type MergedItem array{
 *   id: string,
 *   type: string,
 *   name: string,
 *   description: string,
 *   vendor: string,
 *   edition: string,
 *   status: string,
 *   latest_version: string,
 *   installed_version: string|null,
 *   installed_state: string,
 *   update_available: bool,
 *   compatible: bool,
 *   min_core: string|null,
 *   min_commerce: string|null,
 *   license_required: bool,
 *   license_sku: string|null,
 *   license_status: string,
 *   artifact_url: string|null,
 *   sha256: string|null,
 *   preview_url: string|null,
 *   changelog_url: string|null,
 *   changelog: string|null,
 *   installable: bool,
 *   can_update: bool,
 *   action_reason: string|null,
 *   tags: list<string>
 * }
 * @phpstan-type LicenseStatus array{
 *   ok: bool,
 *   edition: string,
 *   license_id: string|null,
 *   extensions: array<int, string>,
 *   features: array<int, string>,
 *   expires_at: string|null,
 *   grace_until: string|null,
 *   reason: string
 * }
 */
final class EcosystemCatalogService
{
    public const SCHEMA = 'siro.ecosystem-catalog/1';

    private const TYPES = ['plugin', 'theme', 'all'];
    private const EDITIONS = ['free', 'pro', 'all'];
    private const STATUSES = ['published', 'planned', 'deprecated'];
    private const INSTALLED_STATES = ['enabled', 'disabled', 'installed', 'failed'];
    private const PRO_ACTION_REASON = 'Pro license and signed artifact required';

    public const PRO_FEED_LICENSE_REQUIRED = 'Pro feed requires a valid license';

    private string $basePath;

    private ?string $catalogPath;

    /** @var LicenseStatus|null */
    private ?array $license = null;

    public function __construct(?string $basePath = null, ?string $catalogPath = null)
    {
        $this->basePath = $basePath !== null && $basePath !== ''
            ? rtrim($basePath, DIRECTORY_SEPARATOR)
            : $this->defaultBasePath();
        $this->catalogPath = $catalogPath;
    }

    /**
     * @return array{schema: string, generated_at: string, items: list<MergedItem>}
     */
    public function catalog(string $type = 'all', string $edition = 'all', string $q = ''): array
    {
        $type = $this->filterType($type);
        $edition = $this->filterEdition($edition);
        $query = $this->filterQuery($q);

        $loaded = $this->load();
        $items = $this->merge($loaded['items'], $type);
        $items = array_values(array_filter(
            $items,
            fn (array $item): bool => $this->matches($item, $type, $edition, $query)
        ));

        return [
            'schema' => $loaded['schema'],
            'generated_at' => $loaded['generated_at'],
            'items' => $items,
        ];
    }

    /**
     * @return array{items: list<MergedItem>}
     */
    public function updates(): array
    {
        $loaded = $this->load();
        $items = array_filter(
            $this->merge($loaded['items'], 'all'),
            static fn (array $item): bool => $item['update_available'] === true
        );

        return ['items' => array_values($items)];
    }

    /**
     * Private Pro feed: Pro metadata only, gated by an active Pro/Enterprise
     * entitlement (valid or in grace). No install/update mutation is possible
     * through this read model.
     *
     * @return array{schema: string, generated_at: string, items: list<MergedItem>, license: array{edition: string, status: string}}
     */
    public function proFeed(): array
    {
        $status = $this->licenseStatus();
        if (!$this->proFeedEntitled($status)) {
            throw new \RuntimeException(self::PRO_FEED_LICENSE_REQUIRED, 403);
        }

        $loaded = $this->load();
        $items = array_values(array_filter(
            $this->merge($loaded['items'], 'all'),
            static fn (array $item): bool => $item['edition'] === 'pro'
        ));

        return [
            'schema' => $loaded['schema'],
            'generated_at' => $loaded['generated_at'],
            'items' => $items,
            'license' => [
                'edition' => $status['edition'],
                'status' => $status['reason'],
            ],
        ];
    }

    /**
     * Install a bundled free plugin from the catalog without enabling it.
     *
     * @return array{id: string, state: string, version: string}
     */
    public function installBundled(string $id): array
    {
        $id = $this->itemId($id);
        $item = $this->mergedItem($id);

        if ($item['edition'] === 'pro' || $item['license_required'] === true) {
            throw new \RuntimeException(self::PRO_ACTION_REASON, 422);
        }
        if (in_array($item['installed_state'], self::INSTALLED_STATES, true)) {
            throw new \RuntimeException('Extension is already installed', 409);
        }
        if ($item['installable'] !== true) {
            throw new \RuntimeException($item['action_reason'] ?? 'This item cannot be installed', 422);
        }

        $service = $this->lifecycle();
        $manifest = $this->manifestFor($service, $id);
        $service->install($manifest);

        return ['id' => $id, 'state' => 'installed', 'version' => $manifest->version];
    }

    /**
     * Sync the registry version and manifest from the deployed bundled code,
     * preserving the current lifecycle state and never running migrations.
     *
     * @return array{id: string, from: string|null, to: string, state: string}
     */
    public function updateBundled(string $id): array
    {
        $id = $this->itemId($id);
        $item = $this->mergedItem($id);

        if ($item['can_update'] !== true) {
            throw new \RuntimeException($item['action_reason'] ?? 'No update is available', 409);
        }

        $service = $this->lifecycle();
        $manifest = $this->manifestFor($service, $id);
        $from = $item['installed_version'];
        $state = $service->syncBundledManifest($manifest);

        return ['id' => $id, 'from' => $from, 'to' => $manifest->version, 'state' => $state];
    }

    private function itemId(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[a-z0-9][a-z0-9._-]*(\/[a-z0-9][a-z0-9._-]*)?$/', $id) !== 1) {
            throw new \InvalidArgumentException('Extension id is invalid');
        }

        return $id;
    }

    /**
     * @return MergedItem
     */
    private function mergedItem(string $id): array
    {
        foreach ($this->catalog()['items'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        throw new \RuntimeException('Ecosystem item not found: ' . $id, 404);
    }

    private function lifecycle(): ExtensionLifecycleService
    {
        return new ExtensionLifecycleService(ExtensionDiscovery::classes($this->basePath));
    }

    private function manifestFor(ExtensionLifecycleService $service, string $id): ExtensionManifest
    {
        foreach ($service->discover() as $manifest) {
            if ($manifest->id === $id) {
                return $manifest;
            }
        }

        throw new \RuntimeException('Extension not discovered: ' . $id, 404);
    }

    /**
     * @return array{schema: string, generated_at: string, items: list<CatalogItem>}
     */
    public function load(): array
    {
        $raw = @file_get_contents($this->catalogFile());
        if (!is_string($raw)) {
            throw new \RuntimeException('Ecosystem catalog is unavailable');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Ecosystem catalog is malformed');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException('Ecosystem catalog is malformed');
        }

        $schema = $decoded['schema'] ?? null;
        if ($schema !== self::SCHEMA) {
            throw new \RuntimeException('Ecosystem catalog schema is invalid');
        }

        $generatedAt = $decoded['generated_at'] ?? null;
        if (!is_string($generatedAt) || trim($generatedAt) === '') {
            throw new \RuntimeException('Ecosystem catalog generated_at is invalid');
        }

        $rows = $decoded['items'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException('Ecosystem catalog items are invalid');
        }

        $items = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $item = $this->validateItem($row, (int) $index, $seen);
            $seen[$item['id']] = true;
            $items[] = $item;
        }

        return [
            'schema' => $schema,
            'generated_at' => $generatedAt,
            'items' => $items,
        ];
    }

    private function defaultBasePath(): string
    {
        if (defined('BASE_PATH') && is_string(BASE_PATH)) {
            return rtrim(BASE_PATH, DIRECTORY_SEPARATOR);
        }

        return dirname(__DIR__, 4);
    }

    private function catalogFile(): string
    {
        if ($this->catalogPath !== null && $this->catalogPath !== '') {
            return $this->catalogPath;
        }

        return $this->basePath
            . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'ecosystem'
            . DIRECTORY_SEPARATOR . 'catalog.json';
    }

    /**
     * @param list<CatalogItem> $catalogItems
     * @return list<MergedItem>
     */
    private function merge(array $catalogItems, string $type): array
    {
        $includePlugins = $type === 'all' || $type === 'plugin';
        $discovered = $includePlugins ? $this->discoveredPlugins() : [];

        $catalogPluginIds = [];
        foreach ($catalogItems as $item) {
            if ($item['type'] === 'plugin') {
                $catalogPluginIds[$item['id']] = true;
            }
        }

        $items = [];
        foreach ($catalogItems as $item) {
            if ($type !== 'all' && $item['type'] !== $type) {
                continue;
            }
            $items[] = $this->mergeCatalogItem($item, isset($discovered[$item['id']]));
        }

        if ($includePlugins) {
            $fallbackIds = [];
            foreach (array_keys($discovered) as $id) {
                if (!isset($catalogPluginIds[$id])) {
                    $fallbackIds[] = $id;
                }
            }
            sort($fallbackIds);
            foreach ($fallbackIds as $id) {
                $items[] = $this->fallbackItem($id, $discovered[$id]);
            }
        }

        return $items;
    }

    /**
     * @return array<string, array{version: string, requires: array<string, string>}>
     */
    private function discoveredPlugins(): array
    {
        $discovered = [];
        foreach ($this->discoveredManifests() as $manifest) {
            $discovered[$manifest->id] = [
                'version' => $manifest->version,
                'requires' => $manifest->requires,
            ];
        }

        return $discovered;
    }

    /**
     * @return array<string, ExtensionManifest>
     */
    private function discoveredManifests(): array
    {
        try {
            $service = new ExtensionLifecycleService(ExtensionDiscovery::classes($this->basePath));
            $manifests = $service->discover();
        } catch (\Throwable) {
            return [];
        }

        $indexed = [];
        foreach ($manifests as $manifest) {
            $indexed[$manifest->id] = $manifest;
        }

        return $indexed;
    }

    /**
     * @param CatalogItem $item
     * @return MergedItem
     */
    private function mergeCatalogItem(array $item, bool $discovered): array
    {
        $installed = $item['type'] === 'theme'
            ? ['state' => 'available', 'version' => null]
            : $this->installedState($item['id']);
        $compatible = $this->compatible($item['min_core'], $item['min_commerce']);
        $updateAvailable = $installed['version'] !== null
            && $this->isNewer($item['latest_version'], $installed['version']);
        $actions = $this->actionFlags(
            $item['type'],
            $item['edition'],
            $item['license_required'],
            $item['status'],
            $compatible,
            $discovered,
            $installed,
            $updateAvailable,
        );

        return [
            'id' => $item['id'],
            'type' => $item['type'],
            'name' => $item['name'],
            'description' => $item['description'],
            'vendor' => $item['vendor'],
            'edition' => $item['edition'],
            'status' => $item['status'],
            'latest_version' => $item['latest_version'],
            'installed_version' => $installed['version'],
            'installed_state' => $installed['state'],
            'update_available' => $updateAvailable,
            'compatible' => $compatible,
            'min_core' => $item['min_core'],
            'min_commerce' => $item['min_commerce'],
            'license_required' => $item['license_required'],
            'license_sku' => $item['license_sku'],
            'license_status' => $this->licenseStatusFor($item),
            'artifact_url' => $item['artifact_url'],
            'sha256' => $item['sha256'],
            'preview_url' => $item['preview_url'],
            'changelog_url' => $item['changelog_url'],
            'changelog' => $item['changelog'],
            'installable' => $actions['installable'],
            'can_update' => $actions['can_update'],
            'action_reason' => $actions['action_reason'],
            'tags' => $item['tags'],
        ];
    }

    /**
     * @param array{version: string, requires: array<string, string>} $info
     * @return MergedItem
     */
    private function fallbackItem(string $id, array $info): array
    {
        $installed = $this->installedState($id);
        $minCore = $info['requires']['core'] ?? null;
        $minCommerce = $info['requires']['commerce'] ?? null;
        $compatible = $this->compatible($minCore, $minCommerce);
        $updateAvailable = $installed['version'] !== null
            && $this->isNewer($info['version'], $installed['version']);
        $actions = $this->actionFlags(
            'plugin',
            'free',
            false,
            'published',
            $compatible,
            true,
            $installed,
            $updateAvailable,
        );

        return [
            'id' => $id,
            'type' => 'plugin',
            'name' => $id,
            'description' => '',
            'vendor' => $this->vendorFor($id),
            'edition' => 'free',
            'status' => 'published',
            'latest_version' => $info['version'],
            'installed_version' => $installed['version'],
            'installed_state' => $installed['state'],
            'update_available' => $updateAvailable,
            'compatible' => $compatible,
            'min_core' => $minCore,
            'min_commerce' => $minCommerce,
            'license_required' => false,
            'license_sku' => null,
            'license_status' => 'not_required',
            'artifact_url' => null,
            'sha256' => null,
            'preview_url' => null,
            'changelog_url' => null,
            'changelog' => null,
            'installable' => $actions['installable'],
            'can_update' => $actions['can_update'],
            'action_reason' => $actions['action_reason'],
            'tags' => [],
        ];
    }

    /**
     * @param array{state: string, version: string|null} $installed
     * @return array{installable: bool, can_update: bool, action_reason: string|null}
     */
    private function actionFlags(
        string $type,
        string $edition,
        bool $licenseRequired,
        string $status,
        bool $compatible,
        bool $discovered,
        array $installed,
        bool $updateAvailable,
    ): array {
        $isPlugin = $type === 'plugin';
        $isPro = $edition === 'pro' || $licenseRequired;
        $bundled = $isPlugin && $discovered;
        $installedNow = in_array($installed['state'], self::INSTALLED_STATES, true);

        $installable = $bundled
            && !$isPro
            && $edition === 'free'
            && $status === 'published'
            && $compatible
            && $installed['state'] === 'available';
        $canUpdate = $bundled
            && !$isPro
            && $edition === 'free'
            && $installedNow
            && $updateAvailable
            && $compatible;

        $reason = null;
        if ($isPro) {
            $reason = self::PRO_ACTION_REASON;
        } elseif ($isPlugin && !$discovered) {
            $reason = 'Bundled extension code is not available';
        } elseif ($isPlugin && !$compatible) {
            $reason = 'Incompatible with the current core or commerce version';
        } elseif ($isPlugin && !$installedNow && $status !== 'published') {
            $reason = 'Only published plugins can be installed';
        }

        return [
            'installable' => $installable,
            'can_update' => $canUpdate,
            'action_reason' => $reason,
        ];
    }

    /**
     * @return array{state: string, version: string|null}
     */
    private function installedState(string $id): array
    {
        $row = $this->extensionRow($id);
        if ($row === null || $row['state'] === '' || $row['state'] === 'discovered') {
            return ['state' => 'available', 'version' => null];
        }

        return ['state' => $row['state'], 'version' => $row['version']];
    }

    /**
     * @return array{state: string, version: string}|null
     */
    private function extensionRow(string $id): ?array
    {
        try {
            $row = Extension::query()->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
        if ($row === null) {
            return null;
        }

        $data = $row->toArray();
        $state = $data['state'] ?? null;
        $version = $data['version'] ?? null;
        if (!is_string($state) || !is_string($version)) {
            return null;
        }

        return ['state' => $state, 'version' => $version];
    }

    private function vendorFor(string $id): string
    {
        $parts = explode('/', $id, 2);

        return $parts[0] !== '' ? $parts[0] : 'Siro';
    }

    private function isNewer(string $latest, string $installed): bool
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $latest) !== 1
            || preg_match('/^\d+\.\d+\.\d+$/', $installed) !== 1
        ) {
            return false;
        }

        return version_compare($latest, $installed, '>');
    }

    private function compatible(?string $core, ?string $commerce): bool
    {
        if ($core !== null && $core !== '' && !VersionConstraint::satisfies(Console::VERSION, $core)) {
            return false;
        }
        if ($commerce !== null && $commerce !== '' && !VersionConstraint::satisfies(CommerceVersion::VERSION, $commerce)) {
            return false;
        }

        return true;
    }

    /**
     * @param CatalogItem $item
     */
    private function licenseStatusFor(array $item): string
    {
        if ($item['license_required'] !== true) {
            return 'not_required';
        }

        $license = $this->licenseStatus();
        if ($license['reason'] === 'grace') {
            return 'grace';
        }
        if ($license['ok'] !== true) {
            return $license['reason'] === 'expired' ? 'expired' : 'missing';
        }
        if (!in_array($license['edition'], ['pro', 'enterprise'], true)) {
            return 'missing';
        }

        $listed = in_array('*', $license['extensions'], true)
            || in_array($item['id'], $license['extensions'], true)
            || ($item['license_sku'] !== null && in_array($item['license_sku'], $license['extensions'], true));

        return $listed ? 'valid' : 'missing';
    }

    /**
     * @param LicenseStatus $status
     */
    private function proFeedEntitled(array $status): bool
    {
        if (!in_array($status['edition'], ['pro', 'enterprise'], true)) {
            return false;
        }
        if ($status['ok'] !== true) {
            return false;
        }
        if (in_array($status['reason'], ['valid', 'grace'], true)) {
            return true;
        }

        return (new ExtensionLicenseService())->entitled('siro/affiliate');
    }

    /**
     * @return LicenseStatus
     */
    private function licenseStatus(): array
    {
        if ($this->license === null) {
            $this->license = (new ExtensionLicenseService())->status();
        }

        return $this->license;
    }

    /**
     * @param MergedItem $item
     */
    private function matches(array $item, string $type, string $edition, string $query): bool
    {
        if ($type !== 'all' && $item['type'] !== $type) {
            return false;
        }
        if ($edition !== 'all' && $item['edition'] !== $edition) {
            return false;
        }
        if ($query === '') {
            return true;
        }

        $needle = mb_strtolower($query);
        $values = [$item['id'], $item['name'], $item['description'], $item['vendor']];
        foreach ($item['tags'] as $tag) {
            $values[] = $tag;
        }
        foreach ($values as $value) {
            if (mb_stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function filterType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            $type = 'all';
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid type filter');
        }

        return $type;
    }

    private function filterEdition(string $edition): string
    {
        $edition = strtolower(trim($edition));
        if ($edition === '') {
            $edition = 'all';
        }
        if (!in_array($edition, self::EDITIONS, true)) {
            throw new \InvalidArgumentException('Invalid edition filter');
        }

        return $edition;
    }

    private function filterQuery(string $q): string
    {
        $q = trim($q);
        if (mb_strlen($q) > 80) {
            throw new \InvalidArgumentException('Search query must be 80 characters or fewer');
        }

        return $q;
    }

    /**
     * @param array<string, bool> $seen
     * @return CatalogItem
     */
    private function validateItem(mixed $row, int $index, array $seen): array
    {
        if (!is_array($row)) {
            throw $this->invalid($index, 'item must be an object');
        }

        $data = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $data[$key] = $value;
            }
        }

        $id = $this->requiredString($data, 'id', $index);
        if (preg_match('/^[a-z0-9][a-z0-9._-]*(\/[a-z0-9][a-z0-9._-]*)?$/', $id) !== 1) {
            throw $this->invalid($index, 'id is invalid');
        }
        if (isset($seen[$id])) {
            throw $this->invalid($index, 'duplicate id ' . $id);
        }

        $type = $this->enumValue($data, 'type', ['plugin', 'theme'], $index);
        $edition = $this->enumValue($data, 'edition', ['free', 'pro'], $index);
        $status = $this->enumValue($data, 'status', self::STATUSES, $index);
        $name = $this->requiredString($data, 'name', $index);
        $description = $this->optionalString($data, 'description', $index) ?? '';
        $vendor = $this->requiredString($data, 'vendor', $index);
        $latestVersion = $this->requiredString($data, 'latest_version', $index);
        if (preg_match('/^\d+\.\d+\.\d+$/', $latestVersion) !== 1) {
            throw $this->invalid($index, 'latest_version is invalid');
        }

        $minCore = $this->optionalString($data, 'min_core', $index);
        $minCommerce = $this->optionalString($data, 'min_commerce', $index);
        $licenseRequired = $data['license_required'] ?? null;
        if (!is_bool($licenseRequired)) {
            throw $this->invalid($index, 'license_required must be a boolean');
        }
        if ($edition === 'pro' && $licenseRequired !== true) {
            throw $this->invalid($index, 'pro items must set license_required');
        }

        $licenseSku = $this->optionalString($data, 'license_sku', $index);
        $previewUrl = $this->urlValue($data, 'preview_url', $index);
        $changelogUrl = $this->urlValue($data, 'changelog_url', $index);
        $changelog = $this->changelogValue($data, $index);
        $artifactUrl = $this->urlValue($data, 'artifact_url', $index);
        $sha256 = $this->optionalString($data, 'sha256', $index);
        if ($sha256 !== null && preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
            throw $this->invalid($index, 'sha256 is invalid');
        }

        $publishedAt = $this->optionalString($data, 'published_at', $index);
        $tags = $this->tags($data, $index);

        return [
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'description' => $description,
            'vendor' => $vendor,
            'edition' => $edition,
            'status' => $status,
            'latest_version' => $latestVersion,
            'min_core' => $minCore,
            'min_commerce' => $minCommerce,
            'license_required' => $licenseRequired,
            'license_sku' => $licenseSku,
            'preview_url' => $previewUrl,
            'changelog_url' => $changelogUrl,
            'changelog' => $changelog,
            'artifact_url' => $artifactUrl,
            'sha256' => $sha256,
            'published_at' => $publishedAt,
            'tags' => $tags,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $field, int $index): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw $this->invalid($index, $field . ' is required');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalString(array $data, string $field, int $index): ?string
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }
        $value = $data[$field];
        if (!is_string($value)) {
            throw $this->invalid($index, $field . ' must be a string');
        }
        if (trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private function enumValue(array $data, string $field, array $allowed, int $index): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw $this->invalid($index, $field . ' is invalid');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function urlValue(array $data, string $field, int $index): ?string
    {
        $value = $this->optionalString($data, $field, $index);
        if ($value === null) {
            return null;
        }
        if (!$this->isSafeUrl($value)) {
            throw $this->invalid($index, $field . ' must be https or root-relative');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function changelogValue(array $data, int $index): ?string
    {
        $value = $this->optionalString($data, 'changelog', $index);
        if ($value === null) {
            return null;
        }
        if (mb_strlen($value) > 1000) {
            throw $this->invalid($index, 'changelog must be 1000 characters or fewer');
        }
        if (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $value) === 1) {
            throw $this->invalid($index, 'changelog must not contain control characters');
        }
        if (str_contains($value, '<') || str_contains($value, '>')) {
            throw $this->invalid($index, 'changelog must be plain text');
        }

        return $value;
    }

    private function isSafeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (!is_string($scheme) || strtolower($scheme) !== 'https') {
            return false;
        }
        if (!is_string($host) || $host === '') {
            return false;
        }

        return !isset($parts['user']) && !isset($parts['pass']);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function tags(array $data, int $index): array
    {
        $value = $data['tags'] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->invalid($index, 'tags must be a list');
        }

        $tags = [];
        foreach ($value as $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                throw $this->invalid($index, 'tags must contain non-empty strings');
            }
            $tags[] = $tag;
        }

        return $tags;
    }

    private function invalid(int $index, string $reason): \RuntimeException
    {
        return new \RuntimeException('Ecosystem catalog item ' . $index . ': ' . $reason);
    }
}
