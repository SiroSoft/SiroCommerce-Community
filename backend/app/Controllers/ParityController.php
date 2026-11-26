<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Platform\Application\ParityService;
use App\Modules\Platform\Application\MarketplaceService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\ScopeAuthorizer;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class ParityController extends Controller
{
    public function __construct(private readonly ParityService $parity, private readonly MarketplaceService $marketplace) {}

    public function enqueue(Request $request): Response
    {
        try { $scope = $this->scope($request); $data = $request->validate(['name' => 'required|string', 'payload' => 'nullable|array', 'max_attempts' => 'nullable|integer']); return $this->created($this->parity->enqueue($data, $scope, $this->key($request), $this->actor($request)), 'Job queued'); }
        catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function job(Request $request): Response { try { $row = $this->parity->job(Arr::requiredParam($request, 'id'), $this->scope($request)); return $row === null ? $this->error('Job not found', 404) : $this->success($row, 'Job status'); } catch (\Throwable $e) { return $this->error($e->getMessage(), 403); } }

    public function change(Request $request): Response { try { return $this->success($this->parity->changeJob(Arr::requiredParam($request, 'id'), Arr::requiredParam($request, 'action'), $this->scope($request)), 'Job updated'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }

    public function transfer(Request $request): Response { try { $data = $request->validate(['from_source_id' => 'required|string', 'to_source_id' => 'required|string', 'qty' => 'required|integer', 'variant_id' => 'required|string']); return $this->success($this->parity->transfer($data, $this->scope($request), $this->key($request), $this->actor($request)), 'Inventory transferred'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }

    public function audit(Request $request): Response { return $this->success($this->parity->audit($this->scope($request), min(100, max(1, $request->queryInt('limit', 50))), max(0, $request->queryInt('offset', 0))), 'Audit log'); }
    public function reserve(Request $request): Response { try { $data = $request->validate(['variant_id' => 'required|string', 'qty' => 'required|integer', 'cart_id' => 'required|string', 'ttl_seconds' => 'nullable|integer']); return $this->success($this->parity->reserve($data, $this->scope($request), $this->key($request)), 'Reservation created', 201); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }
    public function release(Request $request): Response { try { $id = Arr::requiredParam($request, 'id'); $this->parity->release($id, $this->scope($request)); return $this->success(['id' => $id, 'state' => 'released'], 'Reservation released'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } }
    public function notifications(Request $request): Response { return $this->success($this->parity->notifications($this->scope($request), min(100, max(1, $request->queryInt('limit', 50))), max(0, $request->queryInt('offset', 0))), 'Notification events'); }
    public function auditExport(Request $request): Response { return $this->success(['format' => 'json', 'rows' => $this->parity->audit($this->scope($request), min(10000, max(1, $request->queryInt('limit', 1000))), max(0, $request->queryInt('offset', 0)))], 'Audit export'); }
    public function vendors(Request $request): Response { return $this->success($this->parity->vendors($this->scope($request), min(100, max(1, $request->queryInt('limit', 50))), max(0, $request->queryInt('offset', 0))), 'Vendors'); }
    public function vendor(Request $request): Response { $row = $this->parity->vendor(Arr::requiredParam($request, 'id'), $this->scope($request)); return $row === null ? $this->error('Vendor not found', 404) : $this->success($row, 'Vendor'); }

    public function onboardVendor(Request $request): Response { try { return $this->created($this->marketplace->onboard($request->validate(['code' => 'required|string', 'name' => 'required|string', 'profile' => 'nullable|array']), $this->scope($request), $this->key($request), $this->actor($request)), 'Vendor onboarding accepted'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }
    public function updateVendor(Request $request): Response { try { return $this->success($this->marketplace->updateProfile(Arr::requiredParam($request, 'id'), $request->validate(['name' => 'nullable|string', 'profile' => 'nullable|array', 'version' => 'required|integer']), $this->scope($request), $this->key($request), $this->actor($request)), 'Vendor profile updated'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }
    public function vendorStatus(Request $request): Response { try { $data = $request->validate(['status' => 'required|string']); return $this->success($this->marketplace->status(Arr::requiredParam($request, 'id'), Arr::requiredStr($data, 'status'), $this->scope($request), $this->key($request), $this->actor($request)), 'Vendor status updated'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }
    public function vendorCatalog(Request $request): Response { try { return $this->success($this->marketplace->catalog(Arr::requiredParam($request, 'vendor'), $request->validate(['product_id' => 'required|string', 'visibility' => 'required|string', 'version' => 'nullable|integer']), $this->scope($request), $this->key($request), $this->actor($request)), 'Catalog ownership updated'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }
    public function commission(Request $request): Response { try { return $this->created($this->marketplace->commission($request->validate(['vendor_id' => 'required|string', 'gross_minor' => 'required|integer', 'rate_bps' => 'required|integer', 'currency' => 'required|string', 'order_id' => 'nullable|string']), $this->scope($request), $this->key($request), $this->actor($request)), 'Commission ledger entry posted'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }
    public function commissions(Request $request): Response { return $this->success($this->marketplace->ledgerList($this->scope($request), min(100, max(1, $request->queryInt('limit', 50))), max(0, $request->queryInt('offset', 0))), 'Commission ledger'); }
    public function commissionEntry(Request $request): Response { try { return $this->success($this->marketplace->ledger(Arr::requiredParam($request, 'id'), $this->scope($request)), 'Commission ledger entry'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 404); } }
    public function createPayout(Request $request): Response { try { return $this->created($this->marketplace->createPayout($request->validate(['vendor_id' => 'required|string', 'amount_minor' => 'required|integer|min:0', 'currency' => 'required|string']), $this->scope($request), $this->key($request), $this->actor($request)), 'Payout metadata created'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }
    public function payout(Request $request): Response { try { return $this->success($this->marketplace->payout(Arr::requiredParam($request, 'id'), $this->scope($request)), 'Payout metadata'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 404); } }
    public function payoutTransition(Request $request): Response { try { $data = $request->validate(['state' => 'required|string']); return $this->success($this->marketplace->payoutTransition(Arr::requiredParam($request, 'id'), Arr::requiredStr($data, 'state'), $this->scope($request), $this->key($request), $this->actor($request)), 'Payout state updated'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), str_contains($e->getMessage(), 'BLOCKED_EXTERNAL') ? 503 : 409, [], str_contains($e->getMessage(), 'BLOCKED_EXTERNAL') ? 'BLOCKED_EXTERNAL' : 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }
    public function sellerOrders(Request $request): Response { return $this->success($this->marketplace->sellerOrders($this->scope($request), min(100, max(1, $request->queryInt('limit', 50))), max(0, $request->queryInt('offset', 0))), 'Seller orders'); }
    public function splitOrder(Request $request): Response { try { $data = $request->validate(['order_id' => 'nullable|string', 'currency' => 'required|string', 'parts' => 'required|array']); if (!isset($data['order_id'])) $data['order_id'] = Arr::requiredParam($request, 'id'); return $this->created($this->marketplace->splitOrder($data, $this->scope($request), $this->key($request), $this->actor($request)), 'Seller order split created'); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }

    private function scope(Request $request): CommerceScope { $scope = CommerceScope::fromRequest($request); $user = $request->user(); ScopeAuthorizer::assertAllowed($scope, is_array($user) ? $user : []); return $scope; }
    private function key(Request $request): string { $key = $request->header('Idempotency-Key', ''); if (!is_string($key) || $key === '') throw new \InvalidArgumentException('Idempotency-Key is required'); return $key; }
    private function actor(Request $request): string { $user = $request->user(); return is_array($user) ? Arr::str($user, 'email', 'system') : 'system'; }
}
