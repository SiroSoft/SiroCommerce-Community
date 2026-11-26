<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Order\Application\OrderAdminService;
use App\Repositories\OrderRepository;
use App\Modules\Platform\Application\AdminWorkbenchService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class AdminWorkbenchController extends Controller
{
    public function __construct(private readonly AdminWorkbenchService $workbench, private readonly OrderAdminService $orders, private readonly OrderRepository $orderRows) {}

    public function show(Request $request): Response
    {
        try { $scope=$this->scope($request); $user=$this->requireUser($request); return $this->success($this->workbench->bootstrap($scope, $user['id'], $user['role']), 'Admin workbench'); }
        catch (\Throwable $e) { return $this->error($e->getMessage(), 400); }
    }
    public function saveView(Request $request): Response { try { $user=$this->requireUser($request); return $this->created($this->workbench->saveView($this->scope($request),$user['id'],$request->validate(['name'=>'required|string','resource'=>'required|string','filters'=>'required|array','columns'=>'nullable|array'])),'Saved view created'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(),422); } }
    public function deleteView(Request $request): Response { try { $user=$this->requireUser($request); $this->workbench->deleteView(Arr::requiredParam($request,'id'),$this->scope($request),$user['id']); return $this->success(['id'=>Arr::requiredParam($request,'id')],'Saved view deleted'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(),404); } }
    public function savePermissionSet(Request $request): Response { try { $user=$this->requireUser($request); if ($user['role'] !== 'owner') return $this->error('Only owners can configure permission sets',403); $data=$request->validate(['permissions'=>'required|array']); return $this->success($this->workbench->savePermissionSet($user['id'],Arr::requiredParam($request,'role'),$data['permissions']),'Permission set updated'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(),422); } }
    public function createWorkflow(Request $request): Response { try { $user=$this->requireUser($request); return $this->created($this->workbench->createWorkflow($this->scope($request),$user['id'],$request->validate(['name'=>'required|string','resource'=>'required|string','action'=>'required|string','steps'=>'required|array'])),'Approval workflow created'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(),422); } }
    public function requestApproval(Request $request): Response { try { $user=$this->requireUser($request); return $this->created($this->workbench->requestApproval($this->scope($request),$user['id'],$request->validate(['workflow_id'=>'required|string','resource_id'=>'required|string','payload'=>'nullable|array'])),'Approval requested'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(),422); } }
    public function decideApproval(Request $request): Response { try { $user=$this->requireUser($request); $data=$request->validate(['decision'=>'required|string']); return $this->success($this->workbench->decideApproval(Arr::requiredParam($request,'id'),$this->scope($request),$user['id'],$user['role'],Arr::requiredStr($data,'decision')),'Approval decided'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(),422); } catch (\RuntimeException $e) { return $this->error($e->getMessage(),403); } }

    public function bulkOrders(Request $request): Response
    {
        try {
            $user=$this->requireUser($request); $scope=$this->scope($request); $data=$request->validate(['ids'=>'required|array','to'=>'required|string','reason'=>'nullable|string']);
            if (count($data['ids']) < 1 || count($data['ids']) > 100) throw new \InvalidArgumentException('Bulk actions support 1-100 orders');
            $key=trim((string)$request->header('Idempotency-Key','')); if ($key === '') throw new \InvalidArgumentException('Idempotency-Key is required');
            $result=['succeeded'=>[],'failed'=>[]]; foreach ($data['ids'] as $id) { $id=(string)$id; try { if ($this->orderRows->findInScope($id,$scope) === null) throw new \RuntimeException('Order not found'); $result['succeeded'][]=$this->orders->transition($id,(string)$data['to'],(string)$user['email'],(string)($data['reason'] ?? ''),$key.':'.$id); } catch (\Throwable $e) { $result['failed'][]=['id'=>$id,'message'=>$e->getMessage()]; } }
            return $this->success($result,'Bulk order action completed');
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }

    private function scope(Request $request): CommerceScope { try { return CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { throw new \InvalidArgumentException($e->getMessage()); } }
    /** @return array{id:int,role:string,email:string} */
    private function requireUser(Request $request): array { $user=$request->user(); if (!is_array($user) || !is_int($user['id'] ?? null) || !is_string($user['role'] ?? null)) throw new \RuntimeException('Authenticated admin required'); return ['id'=>$user['id'],'role'=>$user['role'],'email'=>(string)($user['email'] ?? 'admin')]; }
}
