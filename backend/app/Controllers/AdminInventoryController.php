<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Inventory\Application\InventoryAdminService;
use App\Modules\Inventory\Application\InventoryQueryService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Repositories\ProductRepository;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;

/**
 * Warehouse stock admin (spec 04: update stock ≤ 2 clicks).
 * Routes gated by `auth:admin,owner,warehouse`.
 */
final class AdminInventoryController extends Controller
{
    public function __construct(
        private readonly InventoryQueryService $inventory,
        private readonly InventoryAdminService $adminInventory,
        private readonly ProductRepository $products,
    ) {
    }

    public function show(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); if (!$this->products->variantInScope(Arr::requiredParam($request, 'variant'), $scope)) return $this->error('Inventory item not found', 404); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $item = $this->inventory->item(Arr::requiredParam($request, 'variant'));
        if ($item === null) {
            return $this->error('Inventory item not found', 404);
        }
        $item['available'] = $this->inventory->available(Arr::requiredStr($item, 'variant_id'));
        return $this->success($item, 'Inventory detail');
    }

    public function sources(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); if (!$this->products->variantInScope(Arr::requiredParam($request, 'variant'), $scope)) return $this->error('Inventory item not found', 404); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        return $this->success($this->inventory->sources(Arr::requiredParam($request, 'variant')), 'Inventory sources');
    }

    public function adjust(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['on_hand' => 'required|integer', 'source_id' => 'nullable|string']);
        $variantId = Arr::requiredParam($request, 'variant');
        try { $scope = CommerceScope::fromRequest($request); if (!$this->products->variantInScope($variantId, $scope)) return $this->error('Inventory item not found', 404); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $user = $request->user();
        /** @var array<string, mixed>|null $user */
        $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
        $sourceId = isset($data['source_id']) && is_string($data['source_id']) ? $data['source_id'] : 'default';
        $key = $request->header('idempotency-key', '');
        if (!is_string($key) || $key === '') $key = 'admin-adjustment-' . bin2hex(random_bytes(12));
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $key)) return $this->error('A valid Idempotency-Key is required', 422);
        try { $result = $this->adminInventory->setOnHand($variantId, Arr::requiredInt($data, 'on_hand'), $actor, $sourceId, $key); }
        catch (\RuntimeException|\InvalidArgumentException $e) { return $this->error($e->getMessage(), 409); }
        $item = $this->inventory->item($variantId, $sourceId);
        return $this->success(is_array($item) ? ($item + ['adjustment' => $result]) : ['adjustment' => $result], 'Stock updated');
    }

    public function transfer(Request $request): Response
    {
        $data = $request->validate(['from_source_id'=>'required|string','to_source_id'=>'required|string','qty'=>'required|integer','reason'=>'nullable|string']);
        $data['variant_id']=Arr::requiredParam($request,'variant');
        try { $scope = CommerceScope::fromRequest($request); if (!$this->products->variantInScope($data['variant_id'], $scope)) return $this->error('Inventory item not found', 404); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $user=$request->user(); $data['actor']=is_array($user)?Arr::str($user,'email','admin'):'admin'; $data['idempotency_key']=$request->header('idempotency-key','');
        if (!is_string($data['idempotency_key']) || $data['idempotency_key'] === '') return $this->error('Idempotency-Key is required',422);
        try { return $this->success($this->adminInventory->transfer($data), 'Inventory transferred'); } catch (\RuntimeException|\InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }

    public function priority(Request $request): Response
    {
        $data=$request->validate(['source_id'=>'required|string','priority'=>'required|integer']);
        try { $scope = CommerceScope::fromRequest($request); if (!$this->products->variantInScope(Arr::requiredParam($request,'variant'), $scope)) return $this->error('Inventory item not found', 404); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        try { return $this->success($this->adminInventory->setSourcePriority(Arr::requiredParam($request,'variant'),Arr::requiredStr($data,'source_id'),Arr::requiredInt($data,'priority')), 'Source priority updated'); } catch (\RuntimeException|\InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }

    public function replenishment(Request $request): Response
    {
        $lowOnly = $request->queryString('low_only', '') === '1';
        try {
            $pdo = \Siro\Core\Database::connection();
            $sql = 'SELECT variant_id, product_id, sold_7, sold_30, on_hand, available, days_of_cover, suggested_qty, computed_at FROM replenishment_hints';
            if ($lowOnly) $sql .= ' WHERE suggested_qty > 0';
            $stmt = $pdo->query($sql . ' ORDER BY suggested_qty DESC, sold_30 DESC LIMIT 200');
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $this->success(is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [], 'Replenishment hints');
        } catch (\Throwable) {
            return $this->success([], 'Replenishment hints');
        }
    }

    public function atp(Request $request): Response
    {
        $data=$request->validate(['qty'=>'required|integer','cart_id'=>'required|string','ttl_seconds'=>'nullable|integer','allow_split'=>'nullable|boolean','backorder_policy'=>'nullable|string','allow_backorder'=>'nullable|boolean','allow_preorder'=>'nullable|boolean','allowed_source_ids'=>'nullable|array','excluded_source_ids'=>'nullable|array','store_id'=>'nullable|string','company_id'=>'nullable|string']);
        $key=$request->header('idempotency-key','');
        if (!is_string($key) || $key==='') return $this->error('Idempotency-Key is required',422);
        $policy=['allow_split'=>$data['allow_split']??true,'backorder_policy'=>Arr::str($data,'backorder_policy','fail'),'allow_backorder'=>$data['allow_backorder']??true,'allow_preorder'=>$data['allow_preorder']??true,'allowed_source_ids'=>$data['allowed_source_ids']??[],'excluded_source_ids'=>$data['excluded_source_ids']??[]];
        try { return $this->success($this->inventoryManager()->reserveAcrossSources(Arr::requiredParam($request,'variant'),Arr::requiredInt($data,'qty'),Arr::requiredStr($data,'cart_id'),Arr::int($data,'ttl_seconds',900),$policy,$key,isset($data['store_id'])?Arr::str($data,'store_id'):null,isset($data['company_id'])?Arr::str($data,'company_id'):null), 'ATP reserved'); } catch (\RuntimeException|\InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }

    private function inventoryManager(): \App\Modules\Inventory\Application\InventoryManager
    {
        $manager = \Siro\Core\Container::getInstance()->make(\App\Modules\Inventory\Application\InventoryManager::class);
        if (!$manager instanceof \App\Modules\Inventory\Application\InventoryManager) throw new \RuntimeException('Inventory service unavailable');
        return $manager;
    }
}
