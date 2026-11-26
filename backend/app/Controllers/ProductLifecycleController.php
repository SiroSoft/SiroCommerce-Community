<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Catalog\Application\ProductLifecycleService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use App\Repositories\ProductRepository;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class ProductLifecycleController extends Controller
{
    public function __construct(private readonly ProductLifecycleService $lifecycle, private readonly ProductRepository $products) {}

    public function transition(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = Arr::requiredParam($request, 'id');
            if ($this->products->findInScope($id, $scope) === null) return $this->error('Product not found', 404);
            $data = $request->validate(['state' => 'required|string', 'publish_at' => 'nullable|string', 'version' => 'nullable|integer']);
            return $this->success($this->lifecycle->transition($id, $data), 'Product lifecycle updated');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        catch (\App\Modules\Catalog\Domain\CatalogConflict $e) { return $this->error($e->getMessage(), 409, ['version' => [$e->actualVersion]]); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function voucher(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = Arr::requiredParam($request, 'id');
            if ($this->products->findInScope($id, $scope) === null) return $this->error('Product not found', 404);
            $data = $request->validate(['code' => 'required|string', 'expires_at' => 'nullable|string']);
            $key = $request->header('idempotency-key', '');
            if (!is_string($key) || $key === '') return $this->error('Idempotency-Key is required', 422);
            return $this->created($this->lifecycle->addVoucher($id, $data, $key), 'Voucher code created');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function approve(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = Arr::requiredParam($request, 'id');
            if ($this->products->findInScope($id, $scope) === null) return $this->error('Product not found', 404);
            return $this->success($this->lifecycle->approve($id, $request->validate(['approval_status' => 'required|string', 'version' => 'nullable|integer'])), 'Product approval updated');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        catch (\App\Modules\Catalog\Domain\CatalogConflict $e) { return $this->error($e->getMessage(), 409, ['version' => [$e->actualVersion]]); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function versions(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = Arr::requiredParam($request, 'id');
            if ($this->products->findInScope($id, $scope) === null) return $this->error('Product not found', 404);
            return $this->success($this->lifecycle->versions($id), 'Product versions');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
    }

    public function vouchers(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); $id = Arr::requiredParam($request, 'id'); if ($this->products->findInScope($id, $scope) === null) return $this->error('Product not found', 404); return $this->success($this->lifecycle->vouchers($id), 'Voucher codes'); }
        catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
    }
}
