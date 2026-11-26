<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Extension\Application\EcosystemCatalogService;
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

    public function __construct(?EcosystemCatalogService $catalogService = null)
    {
        $this->catalogService = $catalogService;
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

    private function catalogService(): EcosystemCatalogService
    {
        return $this->catalogService ??= new EcosystemCatalogService();
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
