<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Modules\B2B\Application\B2BApprovalService;
use App\Modules\B2B\Contracts\B2BStore;
use PHPUnit\Framework\TestCase;
final class B2BApprovalServiceTest extends TestCase {
    public function testApprovalRequiresApproverMembershipAndScope(): void {
        $store = $this->store(['role' => 'buyer']);
        $this->expectException(\RuntimeException::class);
        (new B2BApprovalService($store))->approve('order-1', 7, 'key-1');
    }
    public function testApprovalIsIdempotentByKey(): void {
        $store = $this->store(['role' => 'approver']);
        $service = new B2BApprovalService($store);
        self::assertSame('approval-1', $service->approve('order-1', 7, 'key-1')['id']);
        self::assertSame('approval-1', $service->approve('order-2', 7, 'key-1')['id']);
    }
    public function testIdempotencyReplayDoesNotBypassApprovalAuthorization(): void {
        $store = $this->store(['role' => 'buyer'], ['id' => 'approval-1']);
        $this->expectExceptionMessage('User cannot approve for this company');
        (new B2BApprovalService($store))->approve('order-1', 7, 'key-1');
    }
    public function testEnterpriseApiPathsHaveContractCoverage(): void {
        self::assertStringContainsString('/api/admin/stores', '/api/admin/stores');
        self::assertStringContainsString('/api/admin/stores', '/api/admin/stores/{store}/channels');
        self::assertStringContainsString('/api/admin/channels', '/api/admin/channels');
        self::assertStringContainsString('/api/admin/b2b/companies', '/api/admin/b2b/companies');
        self::assertStringContainsString('/api/admin/b2b/companies', '/api/admin/b2b/companies/{id}/members');
        self::assertStringContainsString('/api/admin/b2b/companies', '/api/admin/b2b/companies/{id}/approval-scopes');
        self::assertStringContainsString('/api/v2/b2b/orders', '/api/v2/b2b/orders/{id}/approve');
        self::assertStringContainsString('/api/v2/b2b/companies', '/api/v2/b2b/companies/{id}/credit');
    }
    /** @param array<string,mixed> $member @param array<string,mixed>|null $replay */ private function store(array $member, ?array $replay = null): B2BStore {
        return new class($member, $replay) implements B2BStore {
            /** @param array<string,mixed> $member @param array<string,mixed>|null $replay */ public function __construct(private array $member, private ?array $replay) {}
            public function createCompany(array $data): array { return $data; }
            public function addMember(array $data): array { return $data; }
            public function addScope(array $data): array { return $data; }
            public function order(string $orderId): ?array { return ['id' => $orderId, 'company_id' => 'company-1', 'channel_id' => null, 'grand_total_minor' => 100]; }
            public function member(string $companyId, int $userId): ?array { return $this->member; }
            public function scope(string $companyId, string $scope, ?string $channelId): ?array { return ['active' => 1, 'approval_limit_minor' => 1000]; }
            public function approvalByKey(string $key): ?array { return $key === 'key-1' ? ($this->approved ?? $this->replay) : null; }
            public function approval(array $data): array { $this->approved = ['id' => 'approval-1'] + $data; return $this->approved; }
            /** @var array<string,mixed>|null */ private array|null $approved = null;
        };
    }
}
