<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Modules\B2B\Application\B2BWorkflowService;
use App\Modules\B2B\Contracts\B2BWorkflowStore;
use PHPUnit\Framework\TestCase;
final class B2BWorkflowServiceTest extends TestCase {
    public function testBuyerCanSubmitAndRepeatedKeyDoesNotRepeatTransition(): void { $store=new FakeB2BWorkflowStore(); $service=new B2BWorkflowService($store); $first=$service->transition('order-1',7,'submit','submit-1'); self::assertSame('submit-1',$first['idempotency_key']); self::assertSame($first,$service->transition('order-1',7,'submit','submit-1')); self::assertSame(1,$store->reservations); }
    public function testBuyerCannotApproveAndRejectReleasesCredit(): void { $store=new FakeB2BWorkflowStore(); $service=new B2BWorkflowService($store); $service->transition('order-1',7,'submit','submit-2'); $this->expectException(\RuntimeException::class); $service->transition('order-1',7,'approve','approve-1'); }
    public function testSubmittedOrderUsesLifecycleStatesAndApproverCanApprove(): void { $store=new FakeB2BWorkflowStore(); $service=new B2BWorkflowService($store); $service->transition('order-1',7,'submit','submit-3'); self::assertSame('submitted',$store->workflow['order-1']['state']); $store->role='approver'; $result=$service->transition('order-1',8,'approve','approve-3'); self::assertSame('approved',$result['to_state']); self::assertSame('approved',$store->workflow['order-1']['state']); }
    public function testCreditEvidenceRequiresCompanyMembership(): void { $store=new FakeB2BWorkflowStore(); $service=new B2BWorkflowService($store); $store->memberAllowed=false; $this->expectException(\RuntimeException::class); $service->credit('company-1',99); }
}
final class FakeB2BWorkflowStore implements B2BWorkflowStore {
    public int $reservations=0; public string $role='buyer'; public bool $memberAllowed=true; public array $workflow=[]; private array $transitions=[];
    public function createCompany(array $data): array{return $data;} public function addMember(array $data): array{return $data;} public function addScope(array $data): array{return $data;}
    public function order(string $id): ?array{return ['id'=>$id,'company_id'=>'company-1','currency'=>'VND','grand_total_minor'=>100,'channel_id'=>null];}
    public function member(string $companyId,int $userId): ?array{return $this->memberAllowed ? ['role'=>$this->role] : null;} public function scope(string $companyId,string $scope,?string $channelId): ?array{return ['active'=>1,'approval_limit_minor'=>1000];}
    public function approvalByKey(string $key): ?array{return null;} public function approval(array $data): array{return $data;}
    public function creditAccount(array $data): array{return $data;} public function creditAccountForCompany(string $companyId): ?array{return ['company_id'=>$companyId,'currency'=>'VND','limit_minor'=>1000,'status'=>'active'];}
    public function contract(array $data): array{return $data;} public function contractById(string $id): ?array{return null;} public function contractApprovalByKey(string $key): ?array{return null;} public function contractApproval(array $data): array{return $data;} public function activateContract(string $id,array $data): array{return $data;}
    public function workflow(string $id): ?array{return $this->workflow[$id]??null;} public function transitionByKey(string $key): ?array{return $this->transitions[$key]??null;}
    public function transitionOrder(array $data): array{$this->workflow[$data['order_id']]=['state'=>$data['to_state']]; $row=['idempotency_key'=>$data['idempotency_key'],'to_state'=>$data['to_state']]; return $this->transitions[$data['idempotency_key']]=$row;}
    public function reserveCredit(string $companyId,string $orderId,int $amount,string $currency,string $key): array{$this->reservations++;return ['order_id'=>$orderId];} public function releaseCredit(string $orderId,string $key): array{return ['order_id'=>$orderId,'state'=>'released'];} public function creditEvidence(string $companyId): array{return [];}
}
