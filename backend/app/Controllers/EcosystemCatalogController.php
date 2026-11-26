<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Extension\Application\EcosystemCatalogService;
use App\Modules\Extension\Application\ProArtifactService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Ecosystem catalog over HTTP (catalog plan sections 6 and 10).
 * Admin/owner only; P1 mutations target bundled plugins only.
 */
final class EcosystemCatalogController extends Controller
{
    private ?EcosystemCatalogService $catalogService;

    private ?ProArtifactService $artifactService = null;

    public function __construct(?EcosystemCatalogService $catalogService = null)
    {
        $this->catalogService = $catalogService;
    }

    /**
     * Optional seam for tests and future generated wiring. The HTTP container
     * keeps using the default service; no path or key can be injected remotely.
     */
    public function withArtifactService(ProArtifactService $service): self
    {
        $this->artifactService = $service;

        return $this;
    }

    public function catalog(Request $request): Response
    {
        try {
            $service = new EcosystemCatalogService();
            $payload = $service->catalog(
                $this->filter($request, 'type', 'all'),
                $this->filter($request, 'edition', 'all'),
                $this->filter($request, 'q', ''),
            );

            return $this->success($payload, 'Ecosystem catalog');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable) {
            return $this->error('Ecosystem catalog is unavailable', 500);
        }
    }

    public function updates(Request $request): Response
    {
        unset($request);

        try {
            return $this->success((new EcosystemCatalogService())->updates(), 'Ecosystem updates');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable) {
            return $this->error('Ecosystem catalog is unavailable', 500);
        }
    }

    public function proFeed(Request $request): Response
    {
        unset($request);

        try {
            return $this->success($this->catalogService()->proFeed(), 'Ecosystem Pro feed');
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 403) {
                return $this->error(EcosystemCatalogService::PRO_FEED_LICENSE_REQUIRED, 403);
            }

            return $this->error('Ecosystem catalog is unavailable', 500);
        } catch (\Throwable) {
            return $this->error('Ecosystem catalog is unavailable', 500);
        }
    }

    public function install(Request $request): Response
    {
        try {
            $this->key($request);
            /** @var array<string, mixed> $data */
            $data = $request->validate(['id' => 'required|string']);
            $id = Arr::requiredStr($data, 'id');
            $result = (new EcosystemCatalogService())->installBundled($id);

            return $this->success($result, 'Ecosystem item installed');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->mutationFailure($e);
        }
    }

    public function update(Request $request): Response
    {
        try {
            $this->key($request);
            /** @var array<string, mixed> $data */
            $data = $request->validate(['id' => 'required|string']);
            $id = Arr::requiredStr($data, 'id');
            $result = (new EcosystemCatalogService())->updateBundled($id);

            return $this->success($result, 'Ecosystem item updated');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->mutationFailure($e);
        }
    }

    /**
     * Download a Pro artifact using a short-lived token issued by the private
     * feed. The response is the archive itself; no path or key is serialized.
     */
    public function proArtifactDownload(Request $request): Response
    {
        try {
            /** @var array<string, mixed> $data */
            $data = $request->validate(['token' => 'required|string']);
            $token = Arr::requiredStr($data, 'token');
            $file = $this->artifactService()->resolveDownload($token);
            $response = Response::download($file['path'], $file['filename'], [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);

            return $response->statusCode() === 200
                ? $response
                : $this->error('Pro artifact is unavailable', 404);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->artifactFailure($e);
        } catch (\Throwable) {
            return $this->error('Pro artifact is unavailable', 500);
        }
    }

    public function proInstall(Request $request): Response
    {
        return $this->proMutation($request, false);
    }

    public function proUpdate(Request $request): Response
    {
        return $this->proMutation($request, true);
    }

    private function proMutation(Request $request, bool $update): Response
    {
        try {
            $this->key($request);
            /** @var array<string, mixed> $data */
            $data = $request->validate(['id' => 'required|string', 'version' => 'nullable|string']);
            $id = Arr::requiredStr($data, 'id');
            $version = Arr::nullableStr($data, 'version');
            if ($version !== null) {
                $version = trim($version);
                if ($version === '') {
                    $version = null;
                }
            }
            $result = $this->artifactService()->install($id, $version, $update);

            return $this->success($result, $update ? 'Pro extension updated' : 'Pro extension installed');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->artifactFailure($e);
        } catch (\Throwable) {
            return $this->error('Pro artifact operation failed', 500);
        }
    }

    private function catalogService(): EcosystemCatalogService
    {
        return $this->catalogService ??= new EcosystemCatalogService();
    }

    private function artifactService(): ProArtifactService
    {
        return $this->artifactService ??= new ProArtifactService();
    }

    private function artifactFailure(\RuntimeException $e): Response
    {
        $code = $e->getCode();
        if (in_array($code, [403, 404, 409, 410, 422], true)) {
            return $this->error($e->getMessage(), $code);
        }

        return $this->error('Pro artifact is unavailable', 500);
    }

    private function key(Request $request): string
    {
        $key = $request->header('idempotency-key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            throw new \InvalidArgumentException('A valid Idempotency-Key is required');
        }

        return $key;
    }

    private function mutationFailure(\RuntimeException $e): Response
    {
        $code = $e->getCode();
        if (in_array($code, [404, 409, 422], true)) {
            return $this->error($e->getMessage(), $code);
        }

        return $this->error('Ecosystem catalog is unavailable', 500);
    }

    private function filter(Request $request, string $key, string $default): string
    {
        $value = $request->query($key, $default);
        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid filter value');
        }

        return $value;
    }
}
