<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use App\CommerceVersion;
use App\Contracts\ExtensionInterface;
use App\Extension\ExtensionRegistry;
use App\Extension\VersionConstraint;
use App\Models\Extension;
use App\Modules\Extension\Contracts\ExtensionHealth;
use App\Modules\Extension\Contracts\ExtensionLifecycle;
use App\Modules\Extension\Domain\ExtensionManifest;
use Siro\Core\Console;

final class ExtensionLifecycleService implements ExtensionLifecycle
{
    /** @var array<string, class-string<ExtensionInterface>> */
    private array $classes = [];

    /** @param array<int, class-string<ExtensionInterface>> $extensionClasses */
    public function __construct(array $extensionClasses = [])
    {
        Extension::clearIdentityMap();
        foreach ($extensionClasses as $class) {
            $this->classes[$class::id()] = $class;
        }
    }

    /** @param array<int, class-string<ExtensionInterface>> $classes
     * @return array<int, ExtensionManifest>
     */
    public function discover(array $classes = []): array
    {
        $manifests = [];
        foreach ($classes as $class) {
            $this->classes[$class::id()] = $class;
        }
        foreach ($this->classes as $id => $class) {
            $extension = new $class();
            $manifest = $this->manifestFor($extension);
            $this->validate($manifest);
            $this->refresh($manifest, $class);
            $manifests[] = $manifest;
        }
        return $manifests;
    }

    /**
     * Discovery must never clobber lifecycle state: re-listing (every admin
     * page load calls discover()) used to reset enabled extensions back to
     * 'discovered'. Insert once; afterwards only refresh code metadata.
     */
    private function refresh(ExtensionManifest $manifest, ?string $class): void
    {
        $existing = Extension::query()->where('id', $manifest->id)->first();
        if (!$existing instanceof Extension) {
            $this->save($manifest, $class, 'discovered');
            return;
        }
        $data = $existing->toArray();
        if (($data['version'] ?? null) !== $manifest->version) {
            $existing->setAttribute('version', $manifest->version);
            $existing->setAttribute('manifest_json', json_encode($manifest->toArray() + ['class' => $class], JSON_THROW_ON_ERROR));
            $existing->setAttribute('updated_at', date('Y-m-d H:i:s'));
            $existing->save();
        }
    }

    /** @return array<int, string> */
    public function validate(ExtensionManifest $manifest): array
    {
        $errors = [];
        if (preg_match('/^[a-z0-9][a-z0-9._-]*(\/[a-z0-9][a-z0-9._-]*)?$/', $manifest->id) !== 1) {
            $errors[] = 'Extension id must be a lowercase vendor/name identifier';
        }
        if (preg_match('/^\d+\.\d+\.\d+$/', $manifest->version) !== 1) {
            $errors[] = 'Extension version must use major.minor.patch format';
        }
        foreach ($manifest->requires as $runtime => $constraint) {
            $version = match ($runtime) {
                'core' => Console::VERSION,
                'commerce' => CommerceVersion::VERSION,
                default => null,
            };
            if ($version === null) {
                $errors[] = "Extension declares unsupported runtime {$runtime}";
            } elseif (!VersionConstraint::satisfies($version, $constraint)) {
                $errors[] = "Extension requires {$runtime} {$constraint}, have {$version}";
            }
        }
        foreach (['capabilities' => $manifest->capabilities, 'permissions' => $manifest->permissions] as $field => $items) {
            foreach ($items as $item) if (preg_match('/^[a-z0-9][a-z0-9._:-]*$/', $item) !== 1) $errors[] = "Extension {$field} contain invalid value {$item}";
        }
        foreach (['migrations' => $manifest->migrations, 'routes' => $manifest->routes] as $field => $items) {
            foreach ($items as $item) if (preg_match('#^(?!/)(?!.*\.\.)[A-Za-z0-9_./:-]+$#', $item) !== 1) $errors[] = "Extension {$field} contain invalid path {$item}";
        }
        return $errors;
    }

    public function install(ExtensionManifest $manifest): void
    {
        $this->assertValid($manifest);
        $this->save($manifest, $this->classes[$manifest->id] ?? null, 'installed');
    }

    public function enable(string $extensionId): void
    {
        $row = $this->row($extensionId);
        $manifest = $this->manifestFromRow($row);
        $this->assertValid($manifest);
        $this->assertLicense($manifest);
        $class = $this->classes[$extensionId] ?? ($this->classFromRow($row));
        try {
            if ($class !== null) {
                $dir = ExtensionMigrationRunner::extensionDir($class);
                if ($dir !== null) ExtensionMigrationRunner::migrate($extensionId, $dir);
                if (ExtensionRegistry::extension($extensionId) === null) {
                    ExtensionRegistry::register(new $class());
                }
                $instance = ExtensionRegistry::extension($extensionId);
                if ($instance !== null) $instance->boot();
            }
        } catch (\Throwable $e) {
            $this->updateState($row, 'failed', $this->safeError($e->getMessage()));
            throw new \RuntimeException('Extension enable failed: ' . $e->getMessage(), 0, $e);
        }
        $this->updateState($row, 'enabled', null);
    }

    public function disable(string $extensionId): void
    {
        $row = $this->row($extensionId);
        try {
            $instance = ExtensionRegistry::extension($extensionId);
            if ($instance !== null) $instance->shutdown();
        } catch (\Throwable) {
            // Shutdown is best effort; unregistration still proceeds.
        }
        ExtensionRegistry::unregister($extensionId);
        $this->updateState($row, 'disabled', null);
    }

    /** @return array{ok: bool, message: string, details: array<string, mixed>} */
    public function health(string $extensionId): array
    {
        $row = $this->row($extensionId);
        $class = $this->classes[$extensionId] ?? $this->classFromRow($row);
        if ($class === null) {
            return ['ok' => false, 'message' => 'Extension implementation is unavailable', 'details' => []];
        }
        try {
            $extension = new $class();
            $result = $extension instanceof ExtensionHealth
                ? $extension->health()
                : ['ok' => true, 'message' => 'Extension is available', 'details' => []];
            if (!$result['ok']) {
                $this->updateState($row, 'failed', $this->safeError($result['message']));
            }
            return $result;
        } catch (\Throwable $exception) {
            $this->updateState($row, 'failed', $this->safeError($exception->getMessage()));
            return ['ok' => false, 'message' => 'Extension health check failed', 'details' => []];
        }
    }

    public function upgrade(ExtensionManifest $manifest): void
    {
        $this->assertValid($manifest);
        $this->save($manifest, $this->classes[$manifest->id] ?? null, 'enabled');
    }

    /**
     * Refresh the stored version and manifest from the deployed bundled code
     * while preserving the current lifecycle state. Never enables the
     * extension and never runs migrations; the operator stays in control.
     */
    public function syncBundledManifest(ExtensionManifest $manifest): string
    {
        $this->assertValid($manifest);
        $row = $this->row($manifest->id);
        $data = $row->toArray();
        $state = is_string($data['state'] ?? null) && $data['state'] !== ''
            ? $data['state']
            : 'installed';
        $class = $this->classes[$manifest->id] ?? $this->classFromRow($row);

        $row->setAttribute('version', $manifest->version);
        $row->setAttribute(
            'manifest_json',
            json_encode($manifest->toArray() + ['class' => $class], JSON_THROW_ON_ERROR)
        );
        $row->setAttribute('updated_at', date('Y-m-d H:i:s'));
        $row->save();

        return $state;
    }

    public function uninstall(string $extensionId, bool $removeData): void
    {
        $row = $this->row($extensionId);
        try {
            $instance = ExtensionRegistry::extension($extensionId);
            if ($instance !== null) $instance->shutdown();
        } catch (\Throwable) {
            // Shutdown is best effort; unregistration still proceeds.
        }
        ExtensionRegistry::unregister($extensionId);
        if ($removeData) {
            $class = $this->classes[$extensionId] ?? $this->classFromRow($row);
            if ($class === null) throw new \RuntimeException('Extension implementation is unavailable');
            $dir = ExtensionMigrationRunner::extensionDir($class);
            if ($dir === null) throw new \RuntimeException('Extension directory is unavailable');
            ExtensionMigrationRunner::rollback($extensionId, $dir);
            $row->delete();
            return;
        }
        $this->updateState($row, 'disabled', null);
    }

    private function manifestFor(ExtensionInterface $extension): ExtensionManifest
    {
        if (method_exists($extension, 'manifest')) {
            $manifest = $extension->manifest();
            if (!is_array($manifest)) throw new \InvalidArgumentException('Extension manifest must be an array');
            $normalized = [];
            foreach ($manifest as $key => $value) if (is_string($key)) $normalized[$key] = $value;
            return ExtensionManifest::fromArray($normalized);
        }
        return new ExtensionManifest(
            $extension::id(),
            '1.0.0',
            ['core' => $extension::compatibleCore(), 'commerce' => $extension::compatibleCommerce()],
            [],
            [],
        );
    }

    private function assertValid(ExtensionManifest $manifest): void
    {
        $errors = $this->validate($manifest);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }

    /**
     * Extensions that declare `license.required = true` may only be enabled on
     * a Pro/Enterprise installation whose license actually lists them.
     * Community and missing licenses must fail before migrations or boot run.
     */
    private function assertLicense(ExtensionManifest $manifest): void
    {
        if (($manifest->license['required'] ?? false) !== true) {
            return;
        }
        $status = (new ExtensionLicenseService())->status();
        $licensedEdition = in_array($status['edition'], ['pro', 'enterprise'], true);
        $listed = in_array('*', $status['extensions'], true)
            || in_array($manifest->id, $status['extensions'], true);
        if ($status['ok'] !== true || !$licensedEdition || !$listed) {
            throw new \RuntimeException(
                'Extension ' . $manifest->id . ' requires a valid Pro license (' . $status['reason'] . ')'
            );
        }
    }

    private function save(ExtensionManifest $manifest, ?string $class, string $state): void
    {
        $payload = ['id' => $manifest->id, 'version' => $manifest->version, 'state' => $state,
            'manifest_json' => json_encode($manifest->toArray() + ['class' => $class], JSON_THROW_ON_ERROR),
            'last_error' => null, 'updated_at' => date('Y-m-d H:i:s')];
        if ($state === 'installed') {
            $payload['installed_at'] = date('Y-m-d H:i:s');
        }
        Extension::updateOrCreate(['id' => $manifest->id], $payload);
    }

    private function row(string $id): Extension
    {
        $row = Extension::query()->where('id', $id)->first();
        if (!$row instanceof Extension) {
            throw new \RuntimeException('Extension not discovered: ' . $id);
        }
        return $row;
    }

    private function updateState(Extension $row, string $state, ?string $error): void
    {
        $row->setAttribute('state', $state);
        $row->setAttribute('last_error', $error);
        $row->setAttribute('updated_at', date('Y-m-d H:i:s'));
        $row->save();
    }

    private function manifestFromRow(Extension $row): ExtensionManifest
    {
        $data = $this->payload($row);
        return ExtensionManifest::fromArray($data);
    }

    /** @return class-string<ExtensionInterface>|null */
    private function classFromRow(Extension $row): ?string
    {
        $data = $this->payload($row);
        $class = $data['class'] ?? null;
        return is_string($class) && is_a($class, ExtensionInterface::class, true) ? $class : null;
    }

    /** @return array<string, mixed> */
    private function payload(Extension $row): array
    {
        $json = $row->manifest_json;
        if (!is_string($json)) {
            throw new \RuntimeException('Stored extension manifest is invalid');
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Stored extension manifest is invalid');
        }
        $payload = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }
        return $payload;
    }

    private function safeError(string $message): string
    {
        return substr(preg_replace('/\s+/', ' ', $message) ?: 'Extension health check failed', 0, 255);
    }
}
