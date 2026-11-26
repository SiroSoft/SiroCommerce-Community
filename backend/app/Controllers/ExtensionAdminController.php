<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionLicenseService;
use App\Modules\Extension\Application\ExtensionLicenseStore;
use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Application\ExtensionQualityGate;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Extension lifecycle over HTTP (spec RULE-E3/E10): owner/admin only.
 * Extension ids contain slashes (vendor/name), so the id travels in the
 * request body instead of the route path. Mutations require Idempotency-Key.
 */
final class ExtensionAdminController extends Controller
{
    private function service(): ExtensionLifecycleService
    {
        return new ExtensionLifecycleService(ExtensionDiscovery::classes($this->base()));
    }

    private function base(): string
    {
        return defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd();
    }

    private function key(Request $request): string
    {
        $key = $request->header('idempotency-key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            throw new \InvalidArgumentException('A valid Idempotency-Key is required');
        }
        return $key;
    }

    public function license(Request $request): Response
    {
        unset($request);
        $service = $this->licenseService();
        $status = $service->status();
        $status['source'] = $service->source();
        $status['stored'] = $service->stored();

        return $this->success($status, 'Extension license');
    }

    /**
     * Import a signed license document into the private portal store. Only
     * cryptographically valid envelopes are accepted; expired licenses are kept
     * for the record but remain inactive. An `EXTENSION_LICENSE_FILE` override
     * is never overwritten.
     */
    public function importLicense(Request $request): Response
    {
        try {
            $this->key($request);
            $license = $request->input('license');
            $raw = $this->licenseDocument($license);
            if ($raw === null) {
                return $this->error('A license document is required', 422);
            }
            if (strlen($raw) > ExtensionLicenseStore::MAX_BYTES) {
                return $this->error('License document is too large', 422);
            }

            $service = $this->licenseService();
            $status = $service->load($raw);
            if (!in_array($status['reason'], ['valid', 'grace', 'expired', 'not_yet_valid'], true)) {
                return $this->error('License is not cryptographically valid', 422);
            }

            $stored = false;
            if ($service->source() !== ExtensionLicenseService::SOURCE_ENV) {
                $service->store()->write($raw);
                $stored = true;
            }

            return $this->success([
                'status' => $status,
                'stored' => $stored,
                'source' => $service->source(),
            ], 'Extension license imported');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException) {
            return $this->error('Extension license could not be stored', 500);
        }
    }

    /**
     * Remove the portal-stored license. Idempotent; an env-managed license is
     * owned by the deployment and cannot be removed from the portal.
     */
    public function removeLicense(Request $request): Response
    {
        try {
            $this->key($request);
            $service = $this->licenseService();
            if ($service->source() === ExtensionLicenseService::SOURCE_ENV) {
                return $this->error('Extension license is managed by EXTENSION_LICENSE_FILE', 409);
            }

            $removed = $service->store()->remove();
            $status = $service->status();
            $status['source'] = $service->source();
            $status['stored'] = $service->stored();

            return $this->success([
                'status' => $status,
                'removed' => $removed,
                'stored' => $service->stored(),
                'source' => $service->source(),
            ], 'Extension license removed');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException) {
            return $this->error('Extension license could not be removed', 500);
        }
    }

    private function licenseService(): ExtensionLicenseService
    {
        return new ExtensionLicenseService(new ExtensionLicenseStore($this->base()));
    }

    private function licenseDocument(mixed $license): ?string
    {
        if (is_string($license)) {
            $license = trim($license);

            return $license === '' ? null : $license;
        }
        if (is_array($license)) {
            try {
                return json_encode($license, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }

        return null;
    }

    public function index(Request $request): Response
    {
        $service = $this->service();
        $items = [];
        foreach ($service->discover() as $manifest) {
            $items[] = $this->describe($manifest);
        }
        return $this->success($items, 'Extensions');
    }

    /**
     * Embedded-view nav (spec EXTENSION_UI_SPEC): enabled extensions with an
     * admin block. The admin shell renders one section entry + one page per
     * item; panels are fetched from the extension's own ext routes.
     */
    public function ui(Request $request): Response
    {
        unset($request);
        $service = $this->service();
        $items = [];
        foreach ($service->discover() as $manifest) {
            $described = $this->describe($manifest);
            if (($described['state'] ?? null) !== 'enabled') continue;
            $ui = $manifest->adminUi();
            if ($ui === null) continue;
            $items[] = array_merge(['id' => $manifest->id], $ui);
        }
        return $this->success($items, 'Extension views');
    }

    public function verifyPackage(Request $request): Response
    {
        try {
            $this->key($request);
            $file = $request->file('package');
            if ($file === null || !$file->isValid()) return $this->error('A package zip is required', 422);
            if (strtolower($file->extension()) !== 'zip') return $this->error('Package must be a .zip archive', 422);
            $report = (new ExtensionPackageInstaller($this->base()))->verify($file->getPathname());
            return $this->success($report, $report['ok'] ? 'Package verified' : 'Package has errors');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function qitPackage(Request $request): Response
    {
        try {
            $this->key($request);
            $file = $request->file('package');
            if ($file === null || !$file->isValid()) return $this->error('A package zip is required', 422);
            if (strtolower($file->extension()) !== 'zip') return $this->error('Package must be a .zip archive', 422);
            $report = (new ExtensionQualityGate($this->base()))->run($file->getPathname());
            return $this->success($report, $report['ok'] ? 'Quality gate passed' : 'Quality gate failed');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function installPackage(Request $request): Response
    {
        try {
            $this->key($request);
            $file = $request->file('package');
            if ($file === null || !$file->isValid()) return $this->error('A package zip is required', 422);
            if (strtolower($file->extension()) !== 'zip') return $this->error('Package must be a .zip archive', 422);
            $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);
            $result = (new ExtensionPackageInstaller($this->base()))->install($file->getPathname(), $force);
            $this->service()->discover();
            return $this->success($result, 'Extension package installed');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function install(Request $request): Response
    {
        try {
            $this->key($request);
            /** @var array<string, mixed> $data */
            $data = $request->validate(['id' => 'required|string']);
            $id = Arr::requiredStr($data, 'id');
            $manifest = $this->manifestFor($this->service(), $id);
            $this->service()->install($manifest);
            return $this->success(['id' => $id, 'state' => 'installed'], 'Extension installed');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function enable(Request $request): Response
    {
        try {
            $this->key($request);
            /** @var array<string, mixed> $data */
            $data = $request->validate(['id' => 'required|string']);
            $id = Arr::requiredStr($data, 'id');
            $this->service()->enable($id);
            return $this->success(['id' => $id, 'state' => 'enabled'], 'Extension enabled');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $code = str_starts_with($e->getMessage(), 'Extension not discovered:') ? 404 : 422;
            return $this->error($e->getMessage(), $code);
        }
    }

    public function disable(Request $request): Response
    {
        try {
            $this->key($request);
            /** @var array<string, mixed> $data */
            $data = $request->validate(['id' => 'required|string']);
            $id = Arr::requiredStr($data, 'id');
            $this->service()->disable($id);
            return $this->success(['id' => $id, 'state' => 'disabled'], 'Extension disabled');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function uninstall(Request $request): Response
    {
        try {
            $this->key($request);
            /** @var array<string, mixed> $data */
            $data = $request->validate(['id' => 'required|string', 'remove_data' => 'nullable']);
            $id = Arr::requiredStr($data, 'id');
            $remove = ($data['remove_data'] ?? false) === true || $data['remove_data'] === 'true' || $data['remove_data'] === 1;
            $this->service()->uninstall($id, $remove);
            return $this->success(['id' => $id, 'state' => $remove ? 'uninstalled' : 'disabled'], 'Extension uninstalled');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function health(Request $request): Response
    {
        try {
            /** @var array<string, mixed> $data */
            $data = $request->validate(['id' => 'required|string']);
            $id = Arr::requiredStr($data, 'id');
            return $this->success(['id' => $id] + $this->service()->health($id), 'Extension health');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function upgrade(Request $request): Response
    {
        try {
            $this->key($request);
            /** @var array<string, mixed> $data */
            $data = $request->validate(['id' => 'required|string']);
            $id = Arr::requiredStr($data, 'id');
            $manifest = $this->manifestFor($this->service(), $id);
            $this->service()->upgrade($manifest);
            return $this->success(['id' => $id, 'state' => 'enabled'], 'Extension upgraded');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    private function manifestFor(ExtensionLifecycleService $service, string $id): ExtensionManifest
    {
        foreach ($service->discover() as $manifest) {
            if ($manifest->id === $id) return $manifest;
        }
        throw new \RuntimeException('Extension not discovered: ' . $id);
    }

    /** @return array<string, mixed> */
    private function describe(ExtensionManifest $manifest): array
    {
        $state = 'discovered';
        $version = $manifest->version;
        $lastError = null;
        try {
            $row = \App\Models\Extension::query()->where('id', $manifest->id)->first();
            if ($row !== null) {
                $data = $row->toArray();
                $state = is_string($data['state'] ?? null) ? $data['state'] : 'discovered';
                $version = is_string($data['version'] ?? null) ? $data['version'] : $manifest->version;
                $lastError = is_string($data['last_error'] ?? null) ? $data['last_error'] : null;
            }
        } catch (\Throwable) {
            // Fresh install without the extensions table: discovery still works.
        }
        return ['id' => $manifest->id, 'version' => $version, 'manifest_version' => $manifest->version, 'state' => $state, 'last_error' => $lastError, 'capabilities' => $manifest->capabilities, 'permissions' => $manifest->permissions, 'migrations' => $manifest->migrations, 'routes' => $manifest->routes, 'requires' => $manifest->requires, 'admin_ui' => $state === 'enabled' ? $manifest->adminUi() : null];
    }
}
